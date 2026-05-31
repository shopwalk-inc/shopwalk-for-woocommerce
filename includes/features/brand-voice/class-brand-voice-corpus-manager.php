<?php
/**
 * Shopwalk_Brand_Voice_Corpus_Manager — corpus input + chunking.
 *
 * The "corpus" is the bag of text samples we send to Shopwalk's training
 * endpoint. It is assembled from three input sources:
 *
 *   1. Auto-discovered existing site content (posts, pages, product
 *      descriptions). Surfaced in the admin UI as a checklist that the
 *      merchant approves/rejects per item.
 *   2. Manually-uploaded plain-text files (.txt, .md). Up to 50 files,
 *      max 2MB each. Stored as discrete items in the corpus.
 *   3. A free-form paste-text box for off-site samples (newsletter
 *      archives, ad copy, brand briefs pasted as text).
 *
 * Every item carries a `source` identifier (e.g. "post_id:123",
 * "upload:about.txt", "paste:1") so the training pipeline can report
 * provenance back to the merchant.
 *
 * The manager also handles:
 *   - minimum-word-count enforcement (refuse to train below 5,000 words)
 *   - chunking corpus items into ~10KB UTF-8 chunks for batched upload
 *   - hashing the approved corpus so the cross-feature class can flag
 *     "stale" after edits without re-uploading the same content
 *
 * @package ShopwalkWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shopwalk_Brand_Voice_Corpus_Manager — corpus assembly + chunking.
 */
final class Shopwalk_Brand_Voice_Corpus_Manager {

	/**
	 * Minimum total word count across all approved corpus items before we
	 * allow a training run. Below this we cannot reliably learn a voice —
	 * the spec calls out 5,000 as a safe floor (~5 short blog posts).
	 */
	public const MIN_WORD_COUNT = 5000;

	/**
	 * Maximum number of uploaded text files in the corpus at any time.
	 * Beyond this point training quality plateaus and the per-batch upload
	 * cost grows linearly with no benefit.
	 */
	public const MAX_UPLOADED_FILES = 50;

	/**
	 * Maximum size (in bytes) for a single uploaded text file. 2MB is far
	 * more than any realistic brand-brief PDF transcript or About-page dump.
	 */
	public const MAX_UPLOAD_BYTES = 2 * 1024 * 1024;

	/**
	 * Target chunk size for the per-batch upload to the training endpoint.
	 * Tuned so that ~10 chunks comfortably fit in a single 100KB HTTP body
	 * with overhead, which keeps every batch under WP's default 1MB
	 * wp_remote_post body limit even when corpus chunks run long.
	 */
	public const CHUNK_BYTES = 10 * 1024;

	/**
	 * Maximum corpus chunks per upload batch. The orchestrator splits the
	 * full corpus into batches of this size and enqueues one AS job per
	 * batch.
	 */
	public const CHUNKS_PER_BATCH = 10;

	/**
	 * WP option holding the merchant's auto-discovered selection state —
	 * map of source-id → bool (true = approved, false = rejected). Used
	 * to remember which discovered items the merchant has already vetted
	 * across page loads.
	 */
	public const OPTION_SELECTION = 'shopwalk_brand_voice_selection';

	/**
	 * WP option holding the array of uploaded text files. Each item is an
	 * array {name: string, text: string, bytes: int, uploaded_at: string}.
	 */
	public const OPTION_UPLOADS = 'shopwalk_brand_voice_uploads';

	/**
	 * WP option holding the merchant's pasted text (single string).
	 */
	public const OPTION_PASTE = 'shopwalk_brand_voice_paste';

	// ── Discovery ───────────────────────────────────────────────────────────

	/**
	 * Discover candidate content already on the site that we could learn
	 * from. Returns an ordered list of descriptors:
	 *
	 *   [
	 *     {
	 *       source: "post_id:123",
	 *       type: "post"|"page"|"product",
	 *       title: "Hello world",
	 *       word_count: 142,
	 *       approved: true|false|null,   // null = not yet vetted by merchant
	 *       excerpt: "First 200 chars…",
	 *     },
	 *     …
	 *   ]
	 *
	 * Scanned content types (intentional):
	 *   - "post"     — Standard WP blog posts (great voice signal)
	 *   - "page"     — Static pages (About, FAQ, Shipping — brand-voice gold)
	 *   - "product"  — Long-form product descriptions (the main signal for
	 *                  description-generation use cases)
	 *
	 * Intentionally skipped content types:
	 *   - "product_variation" — variations have no editorial copy of their own
	 *   - "shop_order" / "shop_coupon" / WC internal CPTs — transactional, no voice
	 *   - "attachment" — media library, not voice
	 *   - "revision" / "nav_menu_item" / "wp_block" / "wp_template*" — structural, not editorial
	 *   - Anything with `public => false` — internal/system CPTs by design
	 *   - Drafts, pending, trash — only `publish` status (live voice only)
	 *
	 * We hard-cap at 500 items to keep the picker UI responsive on large
	 * stores. A "load more" affordance is a v1.1 enhancement.
	 *
	 * @return list<array{source:string,type:string,title:string,word_count:int,approved:?bool,excerpt:string}>
	 */
	public static function discover_candidates(): array {
		$selection = get_option( self::OPTION_SELECTION, array() );
		$selection = is_array( $selection ) ? $selection : array();

		$types = array( 'post', 'page', 'product' );
		$query = new WP_Query(
			array(
				'post_type'              => $types,
				'post_status'            => 'publish',
				'posts_per_page'         => 500,
				'orderby'                => 'modified',
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'fields'                 => 'all',
			)
		);

		$out = array();
		foreach ( $query->posts as $post ) {
			$source = 'post_id:' . (int) $post->ID;
			$text   = self::extract_text_from_post( $post );
			$words  = self::word_count( $text );

			// Skip empties — a 0-word "post" is just a stub.
			if ( 0 === $words ) {
				continue;
			}

			$approved = array_key_exists( $source, $selection ) ? (bool) $selection[ $source ] : null;

			$out[] = array(
				'source'     => $source,
				'type'       => (string) $post->post_type,
				'title'      => (string) get_the_title( $post ),
				'word_count' => $words,
				'approved'   => $approved,
				'excerpt'    => self::excerpt( $text, 200 ),
			);
		}
		return $out;
	}

	/**
	 * Persist the merchant's approve/reject decisions.
	 *
	 * @param array<string,bool> $selection Map of source-id → approved bool.
	 */
	public static function save_selection( array $selection ): void {
		// Whitelist values to strict booleans — never let `true|false|null`
		// strings leak into the option from a hand-crafted POST.
		$clean = array();
		foreach ( $selection as $source => $approved ) {
			$source = (string) $source;
			if ( '' === $source ) {
				continue;
			}
			$clean[ $source ] = (bool) $approved;
		}
		update_option( self::OPTION_SELECTION, $clean );

		// Any selection change invalidates the trained voice.
		Shopwalk_Brand_Voice::_mark_stale();
	}

	// ── Uploads ─────────────────────────────────────────────────────────────

	/**
	 * Add a single uploaded plain-text file to the corpus.
	 *
	 * @param string $filename Sanitized filename (the caller is responsible
	 *                         for sanitize_file_name() before passing).
	 * @param string $contents UTF-8 text contents.
	 * @return array{ok:bool, error?:string, source?:string}
	 */
	public static function add_upload( string $filename, string $contents ): array {
		$uploads = self::get_uploads();
		if ( count( $uploads ) >= self::MAX_UPLOADED_FILES ) {
			return array(
				'ok'    => false,
				'error' => sprintf(
					/* translators: %d: maximum number of files */
					__( 'Upload limit reached (%d files). Remove some before adding more.', 'shopwalk-for-woocommerce' ),
					self::MAX_UPLOADED_FILES
				),
			);
		}

		$bytes = strlen( $contents );
		if ( $bytes > self::MAX_UPLOAD_BYTES ) {
			return array(
				'ok'    => false,
				'error' => sprintf(
					/* translators: %s: maximum file size, human-readable */
					__( 'File too large (max %s).', 'shopwalk-for-woocommerce' ),
					size_format( self::MAX_UPLOAD_BYTES )
				),
			);
		}

		// Reject non-UTF8 / binary content defensively. We only train on text.
		if ( ! mb_check_encoding( $contents, 'UTF-8' ) ) {
			return array(
				'ok'    => false,
				'error' => __( 'File contents are not valid UTF-8 text. Convert to plain text and retry.', 'shopwalk-for-woocommerce' ),
			);
		}

		$source             = 'upload:' . $filename;
		$uploads[ $source ] = array(
			'name'        => $filename,
			'text'        => $contents,
			'bytes'       => $bytes,
			'uploaded_at' => gmdate( 'c' ),
		);
		update_option( self::OPTION_UPLOADS, $uploads );
		Shopwalk_Brand_Voice::_mark_stale();

		return array( 'ok' => true, 'source' => $source );
	}

	/**
	 * Remove an uploaded file from the corpus.
	 *
	 * @param string $source Source identifier (must start with "upload:").
	 */
	public static function remove_upload( string $source ): bool {
		if ( 0 !== strpos( $source, 'upload:' ) ) {
			return false;
		}
		$uploads = self::get_uploads();
		if ( ! isset( $uploads[ $source ] ) ) {
			return false;
		}
		unset( $uploads[ $source ] );
		update_option( self::OPTION_UPLOADS, $uploads );
		Shopwalk_Brand_Voice::_mark_stale();
		return true;
	}

	/**
	 * Returns the current uploads array.
	 *
	 * @return array<string,array{name:string,text:string,bytes:int,uploaded_at:string}>
	 */
	public static function get_uploads(): array {
		$uploads = get_option( self::OPTION_UPLOADS, array() );
		return is_array( $uploads ) ? $uploads : array();
	}

	// ── Paste ───────────────────────────────────────────────────────────────

	/**
	 * Save (or clear) the merchant's pasted text.
	 *
	 * @param string $text UTF-8 text — empty string clears the paste.
	 */
	public static function save_paste( string $text ): void {
		// Soft cap on the paste box at 200KB so a runaway paste doesn't fill
		// the options table.
		if ( strlen( $text ) > 200 * 1024 ) {
			$text = substr( $text, 0, 200 * 1024 );
		}
		update_option( self::OPTION_PASTE, $text );
		Shopwalk_Brand_Voice::_mark_stale();
	}

	/**
	 * Get the merchant's pasted text (empty string if none).
	 */
	public static function get_paste(): string {
		return (string) get_option( self::OPTION_PASTE, '' );
	}

	// ── Assembly + chunking ────────────────────────────────────────────────

	/**
	 * Assemble the full approved corpus into an ordered list of items.
	 * Each item is { source: string, text: string }.
	 *
	 * @return list<array{source:string,text:string}>
	 */
	public static function assemble(): array {
		$items = array();

		// 1. Approved auto-discovered content.
		$selection = get_option( self::OPTION_SELECTION, array() );
		$selection = is_array( $selection ) ? $selection : array();
		foreach ( $selection as $source => $approved ) {
			if ( ! $approved ) {
				continue;
			}
			if ( 0 !== strpos( (string) $source, 'post_id:' ) ) {
				continue;
			}
			$id   = (int) substr( $source, strlen( 'post_id:' ) );
			$post = $id > 0 ? get_post( $id ) : null;
			if ( ! $post || 'publish' !== $post->post_status ) {
				continue;
			}
			$text = self::extract_text_from_post( $post );
			if ( '' === trim( $text ) ) {
				continue;
			}
			$items[] = array( 'source' => $source, 'text' => $text );
		}

		// 2. Uploaded files.
		foreach ( self::get_uploads() as $source => $upload ) {
			$text = isset( $upload['text'] ) ? (string) $upload['text'] : '';
			if ( '' === trim( $text ) ) {
				continue;
			}
			$items[] = array( 'source' => $source, 'text' => $text );
		}

		// 3. Pasted text — split paragraph-wise so it surfaces as discrete
		//    "paste:N" items instead of one monolithic blob (better attribution).
		$paste = self::get_paste();
		if ( '' !== trim( $paste ) ) {
			$paragraphs = preg_split( '/\n{2,}/', $paste ) ?: array();
			$n          = 0;
			foreach ( $paragraphs as $para ) {
				$para = trim( (string) $para );
				if ( '' === $para ) {
					continue;
				}
				++$n;
				$items[] = array( 'source' => 'paste:' . $n, 'text' => $para );
			}
		}

		return $items;
	}

	/**
	 * Total word count across the approved corpus.
	 */
	public static function total_word_count(): int {
		$n = 0;
		foreach ( self::assemble() as $item ) {
			$n += self::word_count( $item['text'] );
		}
		return $n;
	}

	/**
	 * Document count across the approved corpus.
	 */
	public static function total_doc_count(): int {
		return count( self::assemble() );
	}

	/**
	 * Whether the corpus has enough material to train. Below the threshold
	 * the admin UI surfaces the "add more" warning and the training endpoint
	 * is refused server-side too (defense in depth).
	 */
	public static function meets_minimum(): bool {
		return self::total_word_count() >= self::MIN_WORD_COUNT;
	}

	/**
	 * Chunk the assembled corpus into ~CHUNK_BYTES blocks of text. Long items
	 * are split across multiple chunks (carrying the same `source` id, with
	 * a `:partN` suffix); short items are emitted whole.
	 *
	 * Returns the flat list of chunks; the orchestrator further batches this
	 * list into CHUNKS_PER_BATCH-sized upload batches.
	 *
	 * @return list<array{source:string,text:string}>
	 */
	public static function chunk(): array {
		$chunks = array();
		foreach ( self::assemble() as $item ) {
			$text = (string) $item['text'];
			$src  = (string) $item['source'];

			// Fast path — item fits in a single chunk.
			if ( strlen( $text ) <= self::CHUNK_BYTES ) {
				$chunks[] = array( 'source' => $src, 'text' => $text );
				continue;
			}

			// Split on paragraph boundaries first, accumulating into chunks
			// until we'd exceed CHUNK_BYTES; emit, reset, continue. Falls
			// back to byte-window splitting for monster paragraphs.
			$paragraphs = preg_split( '/\n{2,}/', $text ) ?: array( $text );
			$buffer     = '';
			$part       = 0;
			foreach ( $paragraphs as $para ) {
				$para = (string) $para;
				if ( strlen( $para ) > self::CHUNK_BYTES ) {
					// Flush buffer.
					if ( '' !== $buffer ) {
						++$part;
						$chunks[] = array( 'source' => $src . ':part' . $part, 'text' => $buffer );
						$buffer   = '';
					}
					// Byte-window split the monster paragraph.
					$pos = 0;
					$len = strlen( $para );
					while ( $pos < $len ) {
						++$part;
						$slice    = substr( $para, $pos, self::CHUNK_BYTES );
						$chunks[] = array( 'source' => $src . ':part' . $part, 'text' => $slice );
						$pos     += self::CHUNK_BYTES;
					}
					continue;
				}

				if ( strlen( $buffer ) + strlen( $para ) + 2 > self::CHUNK_BYTES ) {
					++$part;
					$chunks[] = array( 'source' => $src . ':part' . $part, 'text' => $buffer );
					$buffer   = $para;
				} else {
					$buffer = '' === $buffer ? $para : $buffer . "\n\n" . $para;
				}
			}
			if ( '' !== $buffer ) {
				++$part;
				$chunks[] = array( 'source' => $src . ':part' . $part, 'text' => $buffer );
			}
		}
		return $chunks;
	}

	/**
	 * Stable content hash of the approved corpus. Used by the cross-feature
	 * class to detect post-train edits and flag the voice as stale.
	 */
	public static function corpus_hash(): string {
		$items = self::assemble();
		// Sort by source so identical corpora hash identically regardless of
		// the order get_option() returned the uploads/selection in.
		usort(
			$items,
			static fn( $a, $b ): int => strcmp( (string) $a['source'], (string) $b['source'] )
		);
		$serialized = '';
		foreach ( $items as $item ) {
			$serialized .= $item['source'] . "\x1f" . $item['text'] . "\x1e";
		}
		return hash( 'sha256', $serialized );
	}

	/**
	 * Reset all corpus inputs (selection, uploads, paste). Used by the
	 * "Reset brand voice" admin action.
	 */
	public static function reset(): void {
		delete_option( self::OPTION_SELECTION );
		delete_option( self::OPTION_UPLOADS );
		delete_option( self::OPTION_PASTE );
	}

	// ── Helpers ────────────────────────────────────────────────────────────

	/**
	 * Extract editorial text from a WP post object. Strips shortcodes, HTML
	 * tags, and runs of whitespace so the word count reflects readable copy
	 * rather than markup density.
	 *
	 * @param WP_Post|object $post
	 */
	public static function extract_text_from_post( object $post ): string {
		$content = isset( $post->post_content ) ? (string) $post->post_content : '';
		if ( '' === $content ) {
			return '';
		}
		if ( function_exists( 'strip_shortcodes' ) ) {
			$content = strip_shortcodes( $content );
		}
		// Replace block-level tag closes with double-newlines so paragraph
		// boundaries survive the strip_tags pass — important for chunking.
		$content = preg_replace( '#</(p|div|h[1-6]|li|section|article|header|footer)>#i', "</$1>\n\n", $content ) ?? $content;
		$content = wp_strip_all_tags( $content );
		$content = html_entity_decode( $content, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		// Collapse 3+ blank lines to exactly 2 (preserves paragraph breaks).
		$content = preg_replace( "/\n{3,}/", "\n\n", $content ) ?? $content;
		return trim( $content );
	}

	/**
	 * UTF-8-safe word count. Splits on any Unicode whitespace run.
	 */
	public static function word_count( string $text ): int {
		$text = trim( $text );
		if ( '' === $text ) {
			return 0;
		}
		$parts = preg_split( '/\s+/u', $text );
		return is_array( $parts ) ? count( array_filter( $parts, static fn( $p ): bool => '' !== $p ) ) : 0;
	}

	/**
	 * Return a short UTF-8-safe excerpt for the picker UI.
	 */
	public static function excerpt( string $text, int $max_chars ): string {
		$text = preg_replace( '/\s+/u', ' ', $text ) ?? $text;
		$text = trim( $text );
		if ( mb_strlen( $text, 'UTF-8' ) <= $max_chars ) {
			return $text;
		}
		return mb_substr( $text, 0, $max_chars - 1, 'UTF-8' ) . '…';
	}
}

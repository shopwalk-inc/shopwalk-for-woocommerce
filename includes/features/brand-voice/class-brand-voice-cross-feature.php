<?php
/**
 * Shopwalk_Brand_Voice — STABLE PUBLIC CROSS-FEATURE CONTRACT.
 * ----------------------------------------------------------------------------
 *
 * THIS CLASS IS A LOAD-BEARING PUBLIC INTERFACE.
 *
 * Other plugin features (product descriptions, SEO meta, blog-post authoring,
 * email-copy authoring, ad-copy authoring) call into this class to discover
 * whether the merchant has trained a brand voice and to obtain the opaque
 * voice identifier they should pass to the Shopwalk generation API.
 *
 * Because those features are developed in parallel sessions (and may ship in
 * different release trains), the signatures and semantics below MUST remain
 * stable across releases. Changing the shape of any public method on
 * `Shopwalk_Brand_Voice` is a BREAKING CHANGE for every downstream feature
 * and requires:
 *
 *   1. A major version bump on the plugin (currently 4.x).
 *   2. A deprecation cycle — keep the old method as a shim that emits
 *      `_deprecated_function()` for at least one minor release before removal.
 *   3. Coordinated PR(s) in the descriptions / seo / authoring sessions.
 *   4. A bullet in CHANGELOG.md under "Breaking changes".
 *
 * NEW capabilities are additive only — add new methods, don't change existing
 * signatures. Defaults on new methods MUST preserve the prior behavior for
 * callers that don't pass the new arguments.
 *
 * ----------------------------------------------------------------------------
 * Contract surface (v4.6.0 — frozen):
 * ----------------------------------------------------------------------------
 *
 *   Shopwalk_Brand_Voice::is_trained(): bool
 *     - True iff the merchant has a successfully-trained voice profile that
 *       Shopwalk's backend currently considers ready. Returns false while a
 *       training run is in progress, when training failed, when the corpus
 *       has been modified since the last train (status: "stale"), and when
 *       the merchant has reset their voice.
 *     - Cheap — reads a single WP option. No remote calls. Safe to call
 *       inline from generation hot paths (e.g. per-product description hooks).
 *
 *   Shopwalk_Brand_Voice::get_active_voice_id(): ?string
 *     - Returns the opaque voice_id (UUID, server-assigned) the caller should
 *       pass to Shopwalk's generation endpoints in the `voice_id` request
 *       field. Returns NULL when no voice is trained — callers MUST treat
 *       NULL as "fall back to the default unbranded voice", not as an error.
 *     - Stable across the lifetime of a trained voice. Changes only when the
 *       merchant retrains (a new voice_id is assigned per training run).
 *
 *   Shopwalk_Brand_Voice::get_status(): string
 *     - One of: "untrained", "training", "ready", "failed", "stale".
 *     - "stale" means: a previously-trained voice exists, but the corpus has
 *       been modified since. The voice is still usable (the prior voice_id
 *       remains active until retrain) but the dashboard surfaces a "retrain
 *       recommended" notice. Callers can ignore "stale" for generation
 *       purposes — `get_active_voice_id()` still returns the prior id.
 *
 *   Shopwalk_Brand_Voice::get_profile_summary(): array
 *     - Returns a cached snapshot of the trained voice's metadata for UIs
 *       that want to show "your voice was trained on N docs, M words":
 *         array{
 *           voice_id: ?string,
 *           trained_at: ?string,     // ISO 8601 or null
 *           sample_output: ?string,  // server-generated preview paragraph
 *           corpus_summary: array{
 *             word_count: int,
 *             doc_count: int,
 *           },
 *         }
 *       Always returns the array shape — fields are null/0 when no voice has
 *       been trained. Callers MUST tolerate missing keys defensively in case
 *       a future server release adds fields.
 *
 * ----------------------------------------------------------------------------
 * Anti-patterns the contract guards against:
 * ----------------------------------------------------------------------------
 *
 *   - DO NOT have downstream features read the underlying WP options
 *     (`shopwalk_brand_voice_voice_id`, etc.) directly. Always go through
 *     this class so we can change the storage layout without breaking them.
 *   - DO NOT make downstream features call Shopwalk_Brand_Voice_API_Client
 *     directly. The API client is a private implementation detail and its
 *     signatures are unstable.
 *   - DO NOT add side effects (cache priming, lazy training, etc.) to the
 *     read methods on this class — generation hot paths must be cheap and
 *     pure-read.
 *
 * @package ShopwalkWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shopwalk_Brand_Voice — stable cross-feature accessor.
 *
 * Other features (descriptions, SEO, authoring) MUST go through this class
 * instead of reading brand-voice WP options directly. See the file header
 * for the full contract and stability guarantees.
 */
final class Shopwalk_Brand_Voice {

	/**
	 * WP option name for the active trained voice_id (UUID string), or '' if
	 * no voice has been trained yet. Considered internal storage — callers
	 * should NOT read this directly; use `get_active_voice_id()`.
	 */
	public const OPTION_VOICE_ID = 'shopwalk_brand_voice_voice_id';

	/**
	 * WP option name for the current training status. One of:
	 * "untrained" | "training" | "ready" | "failed" | "stale".
	 */
	public const OPTION_STATUS = 'shopwalk_brand_voice_status';

	/**
	 * WP option name for the most recent training metadata snapshot —
	 * { voice_id, trained_at, sample_output, corpus_summary }. Refreshed
	 * by the polling orchestrator when training transitions to "ready".
	 */
	public const OPTION_PROFILE = 'shopwalk_brand_voice_profile';

	/**
	 * WP option name for a content hash of the approved corpus at the time
	 * of the last training run. The dashboard compares this to the current
	 * corpus hash on every render to decide whether to flag the voice as
	 * "stale" (corpus changed → retrain recommended).
	 */
	public const OPTION_TRAINED_CORPUS_HASH = 'shopwalk_brand_voice_trained_corpus_hash';

	/**
	 * Whether a usable, ready-to-generate voice is currently trained.
	 *
	 * Cheap — reads a single WP option. Safe to call inline from generation
	 * hot paths (per-product description hooks, per-image SEO hooks).
	 *
	 * Returns false when:
	 *   - the merchant has never trained a voice
	 *   - training is in progress (status: "training")
	 *   - training failed (status: "failed")
	 *   - the merchant has reset their voice
	 *
	 * Returns true even when the voice is "stale" (corpus changed since the
	 * last training run) — the prior voice_id is still valid and usable.
	 */
	public static function is_trained(): bool {
		$status = self::get_status();
		return in_array( $status, array( 'ready', 'stale' ), true )
			&& '' !== (string) get_option( self::OPTION_VOICE_ID, '' );
	}

	/**
	 * Returns the opaque server-assigned voice_id (UUID) to pass to Shopwalk's
	 * generation endpoints, or NULL when no voice is trained.
	 *
	 * Callers MUST treat NULL as "fall back to the default unbranded voice",
	 * NOT as an error condition. Brand voice is an enhancement, not a
	 * prerequisite for generation.
	 *
	 * The returned id is stable for the lifetime of the current trained
	 * voice; it changes only when the merchant retrains.
	 */
	public static function get_active_voice_id(): ?string {
		if ( ! self::is_trained() ) {
			return null;
		}
		$id = (string) get_option( self::OPTION_VOICE_ID, '' );
		return '' === $id ? null : $id;
	}

	/**
	 * Returns the current training status.
	 *
	 * One of: "untrained" | "training" | "ready" | "failed" | "stale".
	 */
	public static function get_status(): string {
		$status = (string) get_option( self::OPTION_STATUS, 'untrained' );
		$valid  = array( 'untrained', 'training', 'ready', 'failed', 'stale' );
		return in_array( $status, $valid, true ) ? $status : 'untrained';
	}

	/**
	 * Returns a cached snapshot of the trained voice's metadata.
	 *
	 * Always returns the documented array shape — fields are null/0 when no
	 * voice has been trained. Callers MUST tolerate missing keys defensively
	 * in case a future server release adds fields.
	 *
	 * @return array{
	 *   voice_id: ?string,
	 *   trained_at: ?string,
	 *   sample_output: ?string,
	 *   corpus_summary: array{word_count:int, doc_count:int},
	 * }
	 */
	public static function get_profile_summary(): array {
		$raw     = get_option( self::OPTION_PROFILE, array() );
		$profile = is_array( $raw ) ? $raw : array();

		$corpus = isset( $profile['corpus_summary'] ) && is_array( $profile['corpus_summary'] )
			? $profile['corpus_summary']
			: array();

		return array(
			'voice_id'       => isset( $profile['voice_id'] ) ? (string) $profile['voice_id'] : null,
			'trained_at'     => isset( $profile['trained_at'] ) ? (string) $profile['trained_at'] : null,
			'sample_output'  => isset( $profile['sample_output'] ) ? (string) $profile['sample_output'] : null,
			'corpus_summary' => array(
				'word_count' => isset( $corpus['word_count'] ) ? (int) $corpus['word_count'] : 0,
				'doc_count'  => isset( $corpus['doc_count'] ) ? (int) $corpus['doc_count'] : 0,
			),
		);
	}

	// ── Mutators (package-private — used only by the training orchestrator) ─
	//
	// These are public so the orchestrator class can call them, but they are
	// NOT part of the cross-feature contract. Downstream features MUST NOT
	// call mutators; the brand-voice feature owns the write path.

	/**
	 * Marks the voice as currently training. Called by the orchestrator when
	 * the first batch is enqueued.
	 *
	 * @internal Used by Shopwalk_Brand_Voice_Training_Orchestrator only.
	 */
	public static function _set_training(): void {
		update_option( self::OPTION_STATUS, 'training' );
	}

	/**
	 * Marks the voice as ready and stores the trained profile snapshot.
	 * Called by the polling orchestrator when the backend reports ready.
	 *
	 * @internal Used by Shopwalk_Brand_Voice_Training_Orchestrator only.
	 *
	 * @param string $voice_id      Server-assigned UUID.
	 * @param array  $profile       Profile payload from the /profile endpoint.
	 * @param string $corpus_hash   Hash of the corpus approved at train time.
	 */
	public static function _set_ready( string $voice_id, array $profile, string $corpus_hash ): void {
		update_option( self::OPTION_VOICE_ID, $voice_id );
		update_option( self::OPTION_PROFILE, $profile );
		update_option( self::OPTION_TRAINED_CORPUS_HASH, $corpus_hash );
		update_option( self::OPTION_STATUS, 'ready' );
	}

	/**
	 * Marks the voice as failed and stores the error message.
	 *
	 * @internal Used by Shopwalk_Brand_Voice_Training_Orchestrator only.
	 *
	 * @param string $error_message Server-provided error string.
	 */
	public static function _set_failed( string $error_message ): void {
		update_option( self::OPTION_STATUS, 'failed' );
		update_option( 'shopwalk_brand_voice_last_error', $error_message );
	}

	/**
	 * Marks a ready voice as stale (corpus changed since last train). Keeps
	 * the prior voice_id usable so generation calls don't break mid-edit;
	 * the dashboard surfaces a "retrain recommended" notice.
	 *
	 * @internal Used by the corpus manager when the merchant edits the corpus.
	 */
	public static function _mark_stale(): void {
		if ( 'ready' === self::get_status() ) {
			update_option( self::OPTION_STATUS, 'stale' );
		}
	}

	/**
	 * Wipes all brand-voice state from this site. Called by the merchant's
	 * explicit "Reset brand voice" action. Does NOT call the server — the
	 * AJAX handler that wraps this call is responsible for the server-side
	 * deletion via the API client.
	 *
	 * @internal Used by Shopwalk_Brand_Voice_Admin only.
	 */
	public static function _reset(): void {
		delete_option( self::OPTION_VOICE_ID );
		delete_option( self::OPTION_PROFILE );
		delete_option( self::OPTION_TRAINED_CORPUS_HASH );
		delete_option( self::OPTION_STATUS );
		delete_option( 'shopwalk_brand_voice_last_error' );
	}
}

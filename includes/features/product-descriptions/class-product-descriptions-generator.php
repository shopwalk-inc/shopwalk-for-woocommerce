<?php
/**
 * Shopwalk_Product_Descriptions_Generator — orchestrates one generation.
 *
 * Responsibilities:
 *
 *  - Gather product context (title, attributes, categories, tags, images)
 *  - Resolve brand-voice id (if trained) or fall back to tone override
 *  - Call the API client
 *  - Apply the result to the product (when caller chooses auto-save) and
 *    snapshot the prior version into `_shopwalk_description_history`
 *
 * Pure orchestration — no WP hooks registered here. Called from the meta
 * box (synchronous, per-product) and the bulk runner (per Action Scheduler
 * task).
 *
 * @package ShopwalkWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shopwalk_Product_Descriptions_Generator — per-product generation.
 */
final class Shopwalk_Product_Descriptions_Generator {

	public const META_HISTORY    = '_shopwalk_description_history';
	public const META_LAST_GEN   = '_shopwalk_description_last_generated_at';
	public const HISTORY_KEEP    = 5;
	public const STALE_THRESHOLD = 5 * MINUTE_IN_SECONDS;

	/**
	 * API client (injectable for tests).
	 */
	private Shopwalk_Product_Descriptions_Api_Client $api;

	/**
	 * Constructor.
	 *
	 * @param Shopwalk_Product_Descriptions_Api_Client|null $api Injected API client (defaults to a fresh one).
	 */
	public function __construct( ?Shopwalk_Product_Descriptions_Api_Client $api = null ) {
		$this->api = $api ?? new Shopwalk_Product_Descriptions_Api_Client();
	}

	/**
	 * Build the API request payload for a single product. Pulls product
	 * context (title, attributes, categories, tags, images) from WC and
	 * layers per-call options on top.
	 *
	 * Pure function (no API call, no DB write) — exposed for tests and
	 * for the meta-box "preview the prompt" debug view.
	 *
	 * @param int                 $product_id Product to gather context for.
	 * @param array<string,mixed> $options    Per-call options:
	 *   - fields:          array<string> ['long','short']
	 *   - tone:            string         brand_voice|friendly|professional|technical|playful
	 *   - length:          string         short|medium|long
	 *   - focus_keyphrase: string         optional SEO target
	 *   - include_images:  bool           default true
	 *   - locale:          string         Pro+ multi-language target locale.
	 * @return array<string,mixed>
	 */
	public function build_request_payload( int $product_id, array $options = array() ): array {
		$fields = array_values( array_intersect(
			array( 'long', 'short' ),
			(array) ( $options['fields'] ?? array( 'long', 'short' ) )
		) );
		if ( empty( $fields ) ) {
			$fields = array( 'long', 'short' );
		}

		$tone   = (string) ( $options['tone'] ?? 'brand_voice' );
		$length = (string) ( $options['length'] ?? 'medium' );
		$focus  = (string) ( $options['focus_keyphrase'] ?? '' );
		$locale = (string) ( $options['locale'] ?? '' );
		$incl_imgs = ! array_key_exists( 'include_images', $options ) || (bool) $options['include_images'];

		$context = $this->gather_product_context( $product_id, $incl_imgs );

		$payload = array(
			'partner_id'      => $this->partner_id(),
			'product_id'      => $product_id,
			'fields'          => $fields,
			'tone'            => $tone,
			'length'          => $length,
			'focus_keyphrase' => $focus,
			'context'         => $context,
		);

		$brand_voice_id = $this->brand_voice_id();
		if ( '' !== $brand_voice_id ) {
			$payload['brand_voice_id'] = $brand_voice_id;
		}

		if ( '' !== $locale ) {
			$payload['locale'] = $locale;
		}

		// Plugin version + site URL so the backend can correlate logs
		// against a specific plugin install.
		$payload['plugin_version'] = defined( 'WOOCOMMERCE_SHOPWALK_VERSION' ) ? WOOCOMMERCE_SHOPWALK_VERSION : '0.0.0';
		$payload['site_url']       = function_exists( 'home_url' ) ? home_url() : '';

		/**
		 * Filter the per-call generation request payload before signing.
		 * Documented in platform/woocommerce/wp-hooks-used.md.
		 *
		 * @param array<string,mixed> $payload Request payload.
		 * @param int                 $product_id Product ID being generated.
		 * @param array<string,mixed> $options Caller options.
		 */
		$filtered = apply_filters( 'shopwalk_generation_request', $payload, $product_id, $options );
		// Defensive: a filter that returns null (no listeners + a hook
		// shim that returns null instead of the passthrough default)
		// must NOT erase the payload. Only adopt the filter's return
		// when it's a non-empty array.
		if ( is_array( $filtered ) && ! empty( $filtered ) ) {
			$payload = $filtered;
		}

		return $payload;
	}

	/**
	 * Gather the WC-side product context block. Read-only — no DB writes.
	 *
	 * @param int  $product_id WC product post ID.
	 * @param bool $include_images Whether to attach image URLs.
	 * @return array<string,mixed>
	 */
	public function gather_product_context( int $product_id, bool $include_images = true ): array {
		$context = array(
			'title'             => '',
			'sku'               => '',
			'price'             => 0.0,
			'currency'          => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD',
			'existing_long'     => '',
			'existing_short'    => '',
			'attributes'        => array(),
			'categories'        => array(),
			'tags'              => array(),
			'images'            => array(),
		);

		if ( ! function_exists( 'wc_get_product' ) ) {
			return $context;
		}
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return $context;
		}

		$context['title']          = (string) $product->get_name();
		$context['sku']            = (string) $product->get_sku();
		$context['price']          = (float) $product->get_price();
		$context['existing_long']  = (string) $product->get_description();
		$context['existing_short'] = (string) $product->get_short_description();

		// Attributes — flatten name → values[].
		if ( method_exists( $product, 'get_attributes' ) ) {
			foreach ( (array) $product->get_attributes() as $attribute ) {
				if ( is_object( $attribute ) && method_exists( $attribute, 'get_name' ) ) {
					$name = (string) $attribute->get_name();
					if ( method_exists( $attribute, 'get_options' ) ) {
						$opts = (array) $attribute->get_options();
						// Taxonomy attrs return term IDs; resolve to names.
						if ( method_exists( $attribute, 'is_taxonomy' ) && $attribute->is_taxonomy() ) {
							$names = array();
							foreach ( $opts as $term_id ) {
								$term = function_exists( 'get_term' ) ? get_term( (int) $term_id ) : null;
								if ( $term && ! is_wp_error( $term ) ) {
									$names[] = (string) $term->name;
								}
							}
							$opts = $names;
						}
						$context['attributes'][ $name ] = array_values( array_map( 'strval', $opts ) );
					}
				}
			}
		}

		// Categories.
		$cat_terms = function_exists( 'get_the_terms' ) ? get_the_terms( $product_id, 'product_cat' ) : array();
		if ( $cat_terms && ! is_wp_error( $cat_terms ) ) {
			foreach ( $cat_terms as $term ) {
				$context['categories'][] = (string) $term->name;
			}
		}

		// Tags.
		$tag_terms = function_exists( 'get_the_terms' ) ? get_the_terms( $product_id, 'product_tag' ) : array();
		if ( $tag_terms && ! is_wp_error( $tag_terms ) ) {
			foreach ( $tag_terms as $term ) {
				$context['tags'][] = (string) $term->name;
			}
		}

		// Images.
		if ( $include_images && method_exists( $product, 'get_image_id' ) ) {
			$image_id = $product->get_image_id();
			if ( $image_id ) {
				$url = function_exists( 'wp_get_attachment_url' ) ? wp_get_attachment_url( $image_id ) : '';
				if ( $url ) {
					$context['images'][] = array(
						'url'      => $url,
						'alt'      => function_exists( 'get_post_meta' ) ? (string) get_post_meta( $image_id, '_wp_attachment_image_alt', true ) : '',
						'position' => 0,
					);
				}
			}
			if ( method_exists( $product, 'get_gallery_image_ids' ) ) {
				foreach ( $product->get_gallery_image_ids() as $pos => $gid ) {
					$url = function_exists( 'wp_get_attachment_url' ) ? wp_get_attachment_url( $gid ) : '';
					if ( $url ) {
						$context['images'][] = array(
							'url'      => $url,
							'alt'      => function_exists( 'get_post_meta' ) ? (string) get_post_meta( $gid, '_wp_attachment_image_alt', true ) : '',
							'position' => (int) $pos + 1,
						);
					}
				}
			}
		}

		return $context;
	}

	/**
	 * Resolve a trained brand-voice id, if the brand-voice training feature
	 * has registered one. Two integration points (in this order):
	 *
	 *  1. The cross-feature filter `shopwalk_brand_voice_id` — the canonical
	 *     hand-off when the brand-voice feature ships.
	 *  2. The WP option `shopwalk_brand_voice_id` written by brand-voice
	 *     training when status flips to `trained`.
	 *
	 * Returns '' when no brand voice is available — the caller falls back
	 * to the tone override.
	 *
	 * @return string
	 */
	public function brand_voice_id(): string {
		$status = (string) get_option( 'shopwalk_brand_voice_status', '' );
		if ( '' !== $status && 'trained' !== $status ) {
			return '';
		}

		$filtered = apply_filters( 'shopwalk_brand_voice_id', '' );
		$id       = is_string( $filtered ) ? $filtered : '';
		if ( '' === $id ) {
			$id = (string) get_option( 'shopwalk_brand_voice_id', '' );
		}
		return $id;
	}

	/**
	 * Resolve the merchant's partner_id.
	 *
	 * @return string
	 */
	private function partner_id(): string {
		if ( class_exists( 'Shopwalk_License' ) && method_exists( 'Shopwalk_License', 'partner_id' ) ) {
			return (string) call_user_func( array( 'Shopwalk_License', 'partner_id' ) );
		}
		return (string) get_option( 'shopwalk_partner_id', '' );
	}

	/**
	 * Generate descriptions for a product. Returns the API result body
	 * (caller decides whether to apply it).
	 *
	 * @param int                 $product_id Product ID.
	 * @param array<string,mixed> $options    See build_request_payload().
	 * @return array{ok:bool, status:int, body:array<string,mixed>, error?:string}
	 */
	public function generate( int $product_id, array $options = array() ): array {
		$payload = $this->build_request_payload( $product_id, $options );
		$result  = $this->api->generate( $payload );

		do_action( 'shopwalk_generation_complete', $product_id, $result, $options );

		return $result;
	}

	/**
	 * Apply a generation result to the product. Snapshots the prior values
	 * into `_shopwalk_description_history` (capped at HISTORY_KEEP) before
	 * overwriting. Returns true on success.
	 *
	 * @param int                  $product_id Product to update.
	 * @param array<string,string> $result     Generation result with optional `long` / `short` keys.
	 * @return bool
	 */
	public function apply( int $product_id, array $result ): bool {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return false;
		}
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return false;
		}

		$prior = array(
			'long'         => (string) $product->get_description(),
			'short'        => (string) $product->get_short_description(),
			'generated_at' => time(),
		);

		$long  = isset( $result['long'] ) ? (string) $result['long'] : '';
		$short = isset( $result['short'] ) ? (string) $result['short'] : '';

		if ( '' === $long && '' === $short ) {
			return false;
		}

		if ( '' !== $long ) {
			$product->set_description( $long );
		}
		if ( '' !== $short ) {
			$product->set_short_description( $short );
		}

		$product->save();

		$this->snapshot_history( $product_id, $prior );
		update_post_meta( $product_id, self::META_LAST_GEN, time() );

		do_action( 'shopwalk_generation_applied', $product_id, $result );

		return true;
	}

	/**
	 * Push a prior-version snapshot onto the bounded history array.
	 *
	 * @param int                       $product_id Product ID.
	 * @param array{long:string,short:string,generated_at:int} $entry Snapshot.
	 * @return void
	 */
	private function snapshot_history( int $product_id, array $entry ): void {
		$history = (array) get_post_meta( $product_id, self::META_HISTORY, true );
		if ( ! is_array( $history ) ) {
			$history = array();
		}
		array_unshift( $history, $entry );
		$history = array_slice( $history, 0, self::HISTORY_KEEP );
		update_post_meta( $product_id, self::META_HISTORY, $history );
	}

	/**
	 * Whether the product is "stale" — last sync queue activity is more
	 * than STALE_THRESHOLD ago, suggesting the latest WC edits haven't
	 * been ingested yet. Used by the meta box to show a small badge.
	 *
	 * @param int $product_id Product to check.
	 * @return bool
	 */
	public function is_stale( int $product_id ): bool {
		$modified = function_exists( 'get_post_modified_time' ) ? (int) get_post_modified_time( 'U', true, $product_id ) : 0;
		if ( $modified <= 0 ) {
			return false;
		}
		$sync_state = (array) get_option( 'shopwalk_sync_state', array() );
		$completed  = (int) ( $sync_state['completed_at'] ?? 0 );
		if ( $completed <= 0 ) {
			return true;
		}
		return ( $modified - $completed ) > self::STALE_THRESHOLD;
	}
}

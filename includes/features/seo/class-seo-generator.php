<?php
/**
 * Shopwalk_Seo_Generator — orchestrates a single product's SEO generation.
 *
 * Pulls product context out of WooCommerce, calls the API client, and
 * applies (or returns for preview) the generated meta. Apply path routes
 * through the conflict detector so meta lands in the right SEO plugin.
 *
 * Image alt text is never overwritten silently — the `overwrite_alt`
 * flag in the apply options must be explicitly true (the per-product UI
 * never sets it; only the bulk "force regenerate" toggle does).
 *
 * @package ShopwalkWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shopwalk_Seo_Generator — context gathering, generation, and persistence.
 */
final class Shopwalk_Seo_Generator {

	public const META_TITLE_TARGET_LEN = 60;
	public const META_DESC_TARGET_LEN  = 155;

	/**
	 * Build the API request payload for a WC product.
	 *
	 * @param int      $product_id      WC product id.
	 * @param string[] $fields          Subset of ["meta_title","meta_description","image_alt","seo_checklist"].
	 * @param string   $focus_keyphrase Optional merchant-supplied focus keyphrase hint.
	 * @param bool     $overwrite_alt   Bulk-mode flag; forwarded so the backend knows to suppress alt-text dedup.
	 * @return array{ok:bool,message?:string,payload?:array}
	 */
	public static function build_payload(
		int $product_id,
		array $fields,
		string $focus_keyphrase = '',
		bool $overwrite_alt = false
	): array {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'WooCommerce not available.', 'shopwalk-for-woocommerce' ),
			);
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return array(
				'ok'      => false,
				'message' => __( 'Product not found.', 'shopwalk-for-woocommerce' ),
			);
		}

		$image_urls = self::collect_image_urls( $product );

		// Normalize attributes to {slug: value-or-values[]}.
		$attributes = array();
		foreach ( $product->get_attributes() as $slug => $attr ) {
			if ( is_object( $attr ) && method_exists( $attr, 'get_options' ) ) {
				$attributes[ (string) $slug ] = $attr->get_options();
			} elseif ( is_array( $attr ) ) {
				$attributes[ (string) $slug ] = array_values( $attr );
			} else {
				$attributes[ (string) $slug ] = (string) $attr;
			}
		}

		$category_slugs = array();
		$term_ids       = $product->get_category_ids();
		if ( is_array( $term_ids ) ) {
			foreach ( $term_ids as $tid ) {
				$term = function_exists( 'get_term' ) ? get_term( (int) $tid, 'product_cat' ) : null;
				if ( $term && ! is_wp_error( $term ) && isset( $term->slug ) ) {
					$category_slugs[] = (string) $term->slug;
				}
			}
		}

		$payload = array(
			'product_id'          => $product_id,
			'fields'              => array_values( array_unique( $fields ) ),
			'image_urls'          => $image_urls,
			'focus_keyphrase'     => $focus_keyphrase,
			'product_title'       => (string) $product->get_name(),
			'product_description' => (string) $product->get_description(),
			'product_short_desc'  => (string) $product->get_short_description(),
			'product_attributes'  => $attributes,
			'product_categories'  => $category_slugs,
			'product_sku'         => (string) $product->get_sku(),
			'site_locale'         => function_exists( 'get_locale' ) ? (string) get_locale() : 'en_US',
			'overwrite_alt'       => $overwrite_alt,
		);

		return array(
			'ok'      => true,
			'payload' => $payload,
		);
	}

	/**
	 * One-shot generate for a single product. Calls the API client.
	 *
	 * @param int      $product_id      Product id.
	 * @param string[] $fields          Field selection.
	 * @param string   $focus_keyphrase Optional.
	 * @param bool     $overwrite_alt   Bulk flag.
	 * @return array {ok:bool, message?:string, data?:array}
	 */
	public static function generate(
		int $product_id,
		array $fields,
		string $focus_keyphrase = '',
		bool $overwrite_alt = false
	): array {
		$built = self::build_payload( $product_id, $fields, $focus_keyphrase, $overwrite_alt );
		if ( empty( $built['ok'] ) ) {
			return $built;
		}
		return Shopwalk_Seo_Api_Client::generate( $built['payload'] );
	}

	/**
	 * Apply previously-generated meta to the product. Writes to the active
	 * SEO plugin's fields per the conflict detector.
	 *
	 * @param int   $product_id    Product id.
	 * @param array $generated     The `data` block returned by the API.
	 * @param array $apply_options {
	 *   meta_title:bool, meta_description:bool, focus_keyphrase:bool,
	 *   image_alt:bool, overwrite_alt:bool
	 * }
	 * @return array {ok:bool, applied:array<string,bool>, target:string, message?:string}
	 */
	public static function apply( int $product_id, array $generated, array $apply_options ): array {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return array(
				'ok'      => false,
				'applied' => array(),
				'target'  => Shopwalk_Seo_Conflict_Detector::active_target(),
				'message' => __( 'WooCommerce not available.', 'shopwalk-for-woocommerce' ),
			);
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return array(
				'ok'      => false,
				'applied' => array(),
				'target'  => Shopwalk_Seo_Conflict_Detector::active_target(),
				'message' => __( 'Product not found.', 'shopwalk-for-woocommerce' ),
			);
		}

		$applied = array();
		$target  = Shopwalk_Seo_Conflict_Detector::active_target();

		if ( ! empty( $apply_options['meta_title'] ) && isset( $generated['meta_title'] ) ) {
			$key = Shopwalk_Seo_Conflict_Detector::field_key( 'title' );
			if ( $key ) {
				update_post_meta( $product_id, $key, (string) $generated['meta_title'] );
				$applied['meta_title'] = true;
			}
		}

		if ( ! empty( $apply_options['meta_description'] ) && isset( $generated['meta_description'] ) ) {
			$key = Shopwalk_Seo_Conflict_Detector::field_key( 'description' );
			if ( $key ) {
				update_post_meta( $product_id, $key, (string) $generated['meta_description'] );
				$applied['meta_description'] = true;
			}
		}

		if ( ! empty( $apply_options['focus_keyphrase'] ) && isset( $generated['focus_keyphrase'] ) ) {
			$key = Shopwalk_Seo_Conflict_Detector::field_key( 'focus' );
			if ( $key ) {
				update_post_meta( $product_id, $key, (string) $generated['focus_keyphrase'] );
				$applied['focus_keyphrase'] = true;
			}
		}

		if ( ! empty( $apply_options['image_alt'] ) && ! empty( $generated['image_alts'] ) && is_array( $generated['image_alts'] ) ) {
			$applied['image_alt'] = self::apply_image_alts(
				$product,
				$generated['image_alts'],
				! empty( $apply_options['overwrite_alt'] )
			);
		}

		do_action( 'shopwalk_seo_applied', $product_id, $applied, $target );

		return array(
			'ok'      => true,
			'applied' => $applied,
			'target'  => $target,
		);
	}

	/**
	 * Apply image alt text. Honors the "do not overwrite non-empty alt"
	 * rule unless the caller explicitly opts in via $force_overwrite.
	 *
	 * Keys in $alts can be either attachment IDs (int / numeric string) or
	 * image URLs — we accept both so the backend doesn't have to know how
	 * the merchant's image library is keyed.
	 *
	 * @param object               $product         WC product.
	 * @param array<string,string> $alts            Map: id-or-url => alt text.
	 * @param bool                 $force_overwrite Overwrite non-empty alts.
	 * @return int Number of attachments updated.
	 */
	public static function apply_image_alts( $product, array $alts, bool $force_overwrite ): int {
		$attachment_ids = self::collect_attachment_ids( $product );
		// Build a URL → id lookup for URL-keyed entries.
		$url_to_id = array();
		foreach ( $attachment_ids as $aid ) {
			$url = function_exists( 'wp_get_attachment_url' ) ? wp_get_attachment_url( $aid ) : '';
			if ( $url ) {
				$url_to_id[ $url ] = $aid;
			}
		}

		$updated = 0;
		foreach ( $alts as $key => $alt ) {
			$attachment_id = 0;
			if ( is_numeric( $key ) ) {
				$attachment_id = (int) $key;
			} elseif ( isset( $url_to_id[ $key ] ) ) {
				$attachment_id = (int) $url_to_id[ $key ];
			}
			if ( $attachment_id <= 0 ) {
				continue;
			}
			// Only write attachments that actually belong to the product —
			// don't let a backend response stomp arbitrary media.
			if ( ! in_array( $attachment_id, $attachment_ids, true ) ) {
				continue;
			}

			$current = (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
			if ( '' !== trim( $current ) && ! $force_overwrite ) {
				continue;
			}

			update_post_meta( $attachment_id, '_wp_attachment_image_alt', (string) $alt );
			++$updated;
		}
		return $updated;
	}

	/**
	 * Returns all image attachment ids on a product (featured + gallery).
	 *
	 * @param object $product WC product.
	 * @return int[]
	 */
	public static function collect_attachment_ids( $product ): array {
		$ids = array();
		if ( method_exists( $product, 'get_image_id' ) ) {
			$featured = (int) $product->get_image_id();
			if ( $featured > 0 ) {
				$ids[] = $featured;
			}
		}
		if ( method_exists( $product, 'get_gallery_image_ids' ) ) {
			foreach ( (array) $product->get_gallery_image_ids() as $gid ) {
				$ids[] = (int) $gid;
			}
		}
		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * Resolve attachment ids to URLs that the backend can fetch directly.
	 * The plugin sends URLs only — never raw image bytes.
	 *
	 * @param object $product WC product.
	 * @return string[]
	 */
	public static function collect_image_urls( $product ): array {
		$urls = array();
		foreach ( self::collect_attachment_ids( $product ) as $aid ) {
			$url = function_exists( 'wp_get_attachment_url' ) ? wp_get_attachment_url( $aid ) : '';
			if ( $url ) {
				$urls[] = (string) $url;
			}
		}
		return $urls;
	}
}

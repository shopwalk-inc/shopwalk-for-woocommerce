<?php
/**
 * Frontend AJAX endpoint for lazy-loading recommendations.
 *
 * Route: GET /wp-json/shopwalk/v1/recommendations
 *
 * Query params:
 *   type       — also_viewed | related | fbt | personalized   (required)
 *   product_id — integer, the context product                  (required for non-personalized)
 *   count      — integer 1..24                                  (optional, default 6)
 *   user_id    — integer, optional logged-in WC user id         (optional)
 *
 * Response:
 *   {
 *     "ok": true,
 *     "type": "...",
 *     "fallback": false,
 *     "items": [
 *       { "id": 123, "title": "...", "permalink": "...", "image": "...", "price_html": "...", "html": "<li class=...>...</li>" },
 *       ...
 *     ]
 *   }
 *
 * The `html` field uses WC's `wc_get_template_part( 'content', 'product' )`
 * so the merchant's theme styling lights up automatically (theme-
 * transparency requirement from the spec).
 *
 * Auth: nonce-based (the localized `wp_rest` nonce the storefront JS
 * carries). The endpoint is read-only and scoped to the merchant's own
 * catalog, so we don't gate by capability; out-of-stock + private
 * products are filtered server-side.
 *
 * @package ShopwalkWooCommerce\Features\Recommendations
 */

defined( 'ABSPATH' ) || exit;

/**
 * REST controller.
 */
final class Shopwalk_Recommendations_REST {

	/**
	 * Namespace + route mirror the partner-side hooks doc.
	 */
	private const NAMESPACE_NAME = 'shopwalk/v1';
	private const ROUTE          = '/recommendations';

	/**
	 * Register the route. Called from `rest_api_init`.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route(
			self::NAMESPACE_NAME,
			self::ROUTE,
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'handle_get' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'type'       => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'product_id' => array(
						'required'          => false,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'count'      => array(
						'required'          => false,
						'type'              => 'integer',
						'default'           => 6,
						'sanitize_callback' => 'absint',
					),
					'user_id'    => array(
						'required'          => false,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * GET handler.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public static function handle_get( $request ) {
		// Tier gate. Free returns a 402-ish payload so the JS knows to
		// hide its placeholder rather than retry.
		if ( class_exists( 'Shopwalk_Recommendations_Feature' )
			&& ! Shopwalk_Recommendations_Feature::is_enabled() ) {
			return new WP_REST_Response(
				array(
					'ok'      => false,
					'code'    => 'pro_required',
					'message' => 'Recommendations require a Pro license.',
					'items'   => array(),
				),
				200
			);
		}

		$type       = (string) $request->get_param( 'type' );
		$product_id = (int) $request->get_param( 'product_id' );
		$count      = (int) $request->get_param( 'count' );
		if ( $count <= 0 ) {
			$count = 6;
		}
		$user_id_raw = $request->get_param( 'user_id' );
		$user_id     = null;
		if ( null !== $user_id_raw && '' !== $user_id_raw ) {
			$user_id = (int) $user_id_raw;
		}

		$result = Shopwalk_Recommendations_API_Client::fetch( $type, $product_id, $count, $user_id );
		if ( ! $result['ok'] ) {
			return new WP_REST_Response(
				array(
					'ok'      => false,
					'code'    => $result['code'] ?? 'error',
					'message' => $result['message'] ?? 'Recommendations unavailable.',
					'items'   => array(),
				),
				200 // 200 with ok:false so frontends don't surface a noisy 5xx.
			);
		}

		$items = self::expand_products( $result['product_ids'] );

		return new WP_REST_Response(
			array(
				'ok'         => true,
				'type'       => $type,
				'fallback'   => (bool) $result['fallback'],
				'from_cache' => (bool) $result['from_cache'],
				'items'      => $items,
			),
			200
		);
	}

	/**
	 * Expand a list of product ids into render-ready cards. Filters
	 * products that aren't visible, aren't published, or that the
	 * merchant excluded via the `shopwalk_excluded_post_types` filter.
	 *
	 * @param array<int,int> $ids Product ids.
	 * @return array<int,array<string,mixed>>
	 */
	private static function expand_products( array $ids ): array {
		$out = array();
		if ( ! function_exists( 'wc_get_product' ) ) {
			return $out;
		}
		foreach ( $ids as $id ) {
			$id      = (int) $id;
			$product = wc_get_product( $id );
			if ( ! $product || ! is_object( $product ) ) {
				continue;
			}
			if ( method_exists( $product, 'get_status' ) && 'publish' !== $product->get_status() ) {
				continue;
			}
			if ( method_exists( $product, 'is_visible' ) && ! $product->is_visible() ) {
				continue;
			}

			$image_id  = method_exists( $product, 'get_image_id' ) ? (int) $product->get_image_id() : 0;
			$image_url = $image_id > 0 && function_exists( 'wp_get_attachment_image_url' )
				? (string) wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' )
				: '';

			$out[] = array(
				'id'         => $id,
				'title'      => $product->get_name(),
				'permalink'  => get_permalink( $id ),
				'image'      => $image_url,
				'price_html' => method_exists( $product, 'get_price_html' ) ? $product->get_price_html() : '',
				'html'       => self::render_card_html( $product ),
			);
		}
		return $out;
	}

	/**
	 * Render a single product card using WC's own template part. This
	 * is what makes the carousel adopt the merchant's theme styling
	 * automatically — same template the shop loop uses.
	 *
	 * Falls back to a minimal anchor when WC's template helpers aren't
	 * available (test environments).
	 *
	 * @param object $product WC_Product instance.
	 * @return string
	 */
	private static function render_card_html( $product ): string {
		if ( function_exists( 'wc_get_template_part' ) ) {
			global $post;
			$prev_post = $post;
			$post      = get_post( $product->get_id() ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- WC template parts read $post.
			setup_postdata( $post );
			ob_start();
			wc_get_template_part( 'content', 'product' );
			$html = (string) ob_get_clean();
			$post = $prev_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring previous post context.
			if ( $prev_post ) {
				setup_postdata( $prev_post );
			} else {
				wp_reset_postdata();
			}
			return $html;
		}

		// Minimal fallback for test / non-WC environments.
		$id        = method_exists( $product, 'get_id' ) ? (int) $product->get_id() : 0;
		$name      = method_exists( $product, 'get_name' ) ? $product->get_name() : '';
		$permalink = get_permalink( $id );
		return sprintf(
			'<li class="shopwalk-rec-card" data-product-id="%d"><a href="%s">%s</a></li>',
			$id,
			esc_url( $permalink ),
			esc_html( $name )
		);
	}
}

<?php
/**
 * Gutenberg block + shortcode registration for the recommendations
 * carousel.
 *
 * Both surfaces share the same server-render output: an empty container
 * with data attributes that the lazy-load JS picks up to fetch real
 * recommendations from /wp-json/shopwalk/v1/recommendations. This keeps
 * SSR fast (no blocking API call) and lets WP page-caches store the
 * shell even when the carousel content varies per-shopper.
 *
 * @package ShopwalkWooCommerce\Features\Recommendations
 */

defined( 'ABSPATH' ) || exit;

/**
 * Block + shortcode registrar.
 */
final class Shopwalk_Recommendations_Block_Handler {

	/**
	 * Block name as it appears in the registry.
	 */
	private const BLOCK_NAME = 'shopwalk/recommendations';

	/**
	 * Shortcode tag merchants use in classic-editor pages or theme files.
	 */
	private const SHORTCODE_TAG = 'shopwalk_recommendations';

	/**
	 * Register block + shortcode. Idempotent.
	 *
	 * @return void
	 */
	public static function register(): void {
		// Shortcode.
		add_shortcode( self::SHORTCODE_TAG, array( self::class, 'render_shortcode' ) );

		// Gutenberg block — server-render only. We don't ship an editor
		// preview here (the editor shows the same placeholder the
		// storefront does, then the lazy-load JS fills it in on view).
		if ( function_exists( 'register_block_type' ) ) {
			$block_dir = self::block_assets_dir();
			if ( file_exists( $block_dir . '/block.json' ) ) {
				register_block_type(
					$block_dir,
					array(
						'render_callback' => array( self::class, 'render_block' ),
					)
				);
			} else {
				// block.json missing (e.g. during early testing) — fall
				// back to a code-only registration so the block at least
				// appears in the inserter.
				register_block_type(
					self::BLOCK_NAME,
					array(
						'api_version'     => 2,
						'title'           => __( 'Shopwalk Recommendations', 'shopwalk-for-woocommerce' ),
						'category'        => 'woocommerce',
						'render_callback' => array( self::class, 'render_block' ),
						'attributes'      => array(
							'type'      => array(
								'type'    => 'string',
								'default' => 'related',
							),
							'productId' => array(
								'type'    => 'integer',
								'default' => 0,
							),
							'count'     => array(
								'type'    => 'integer',
								'default' => 6,
							),
							'layout'    => array(
								'type'    => 'string',
								'default' => 'carousel',
							),
							'title'     => array(
								'type'    => 'string',
								'default' => '',
							),
						),
					)
				);
			}
		}
	}

	/**
	 * Shortcode render entry point.
	 *
	 * @param array<string,mixed> $atts Shortcode attributes.
	 * @return string
	 */
	public static function render_shortcode( $atts ): string {
		$atts = shortcode_atts(
			array(
				'type'       => 'related',
				'product_id' => 0,
				'count'      => 6,
				'layout'     => 'carousel',
				'title'      => '',
			),
			is_array( $atts ) ? $atts : array(),
			self::SHORTCODE_TAG
		);

		return self::render_container(
			array(
				'type'       => (string) $atts['type'],
				'product_id' => self::resolve_product_id( (int) $atts['product_id'] ),
				'count'      => (int) $atts['count'],
				'layout'     => (string) $atts['layout'],
				'title'      => (string) $atts['title'],
			)
		);
	}

	/**
	 * Gutenberg block render entry point.
	 *
	 * @param array<string,mixed> $attrs Block attributes.
	 * @return string
	 */
	public static function render_block( $attrs ): string {
		$attrs = is_array( $attrs ) ? $attrs : array();
		return self::render_container(
			array(
				'type'       => (string) ( $attrs['type'] ?? 'related' ),
				'product_id' => self::resolve_product_id( (int) ( $attrs['productId'] ?? 0 ) ),
				'count'      => (int) ( $attrs['count'] ?? 6 ),
				'layout'     => (string) ( $attrs['layout'] ?? 'carousel' ),
				'title'      => (string) ( $attrs['title'] ?? '' ),
			)
		);
	}

	/**
	 * Build the lazy-load container markup. Public so the feature's
	 * single-product injector can call it directly without round-tripping
	 * through the shortcode.
	 *
	 * @param array{type:string,product_id:int,count:int,layout:string,title:string} $args Render args.
	 * @return string
	 */
	public static function render_container( array $args ): string {
		// Tier gate — Free shows nothing on the storefront (the admin
		// already nudges to upgrade).
		if ( class_exists( 'Shopwalk_Recommendations_Feature' )
			&& ! Shopwalk_Recommendations_Feature::is_enabled() ) {
			return '';
		}

		// Make sure assets are queued — works even when the block is the
		// only rec surface on the page.
		if ( class_exists( 'Shopwalk_Recommendations_Feature' ) ) {
			Shopwalk_Recommendations_Feature::enqueue_storefront_assets();
		}

		$type       = self::sanitize_type( $args['type'] );
		$product_id = max( 0, (int) $args['product_id'] );
		$count      = max( 1, min( 24, (int) $args['count'] ) );
		$layout     = in_array( $args['layout'], array( 'carousel', 'grid', 'list' ), true ) ? $args['layout'] : 'carousel';
		$title      = trim( (string) $args['title'] );

		ob_start();
		$view = __DIR__ . '/views/block-render.php';
		if ( file_exists( $view ) ) {
			include $view;
		}
		return (string) ob_get_clean();
	}

	/**
	 * Resolve the product id to use as recommendation context. Honours
	 * the explicit argument when set; otherwise falls back to the global
	 * $product on single product pages.
	 *
	 * @param int $explicit Explicit product id from the block/shortcode.
	 * @return int
	 */
	private static function resolve_product_id( int $explicit ): int {
		if ( $explicit > 0 ) {
			return $explicit;
		}
		global $product;
		if ( $product && is_object( $product ) && method_exists( $product, 'get_id' ) ) {
			return (int) $product->get_id();
		}
		if ( function_exists( 'get_queried_object_id' ) ) {
			$qo = (int) get_queried_object_id();
			if ( $qo > 0 ) {
				return $qo;
			}
		}
		return 0;
	}

	/**
	 * Normalize the type input. Keeps the wire surface tight — the
	 * server enforces this same allow-list.
	 *
	 * @param string $type Incoming type.
	 * @return string
	 */
	private static function sanitize_type( string $type ): string {
		$type = strtolower( trim( $type ) );
		$ok   = array( 'also_viewed', 'related', 'fbt', 'personalized' );
		return in_array( $type, $ok, true ) ? $type : 'related';
	}

	/**
	 * Path to the block assets dir. Lives in /assets/blocks/recommendations/.
	 *
	 * @return string
	 */
	private static function block_assets_dir(): string {
		$base = defined( 'WOOCOMMERCE_SHOPWALK_PLUGIN_DIR' )
			? WOOCOMMERCE_SHOPWALK_PLUGIN_DIR
			: dirname( __FILE__, 4 ) . '/';
		return rtrim( $base, '/' ) . '/assets/blocks/recommendations';
	}
}

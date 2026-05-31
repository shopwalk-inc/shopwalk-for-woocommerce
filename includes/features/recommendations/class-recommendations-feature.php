<?php
/**
 * Recommendations feature entry point.
 *
 * Self-registers via the central `shopwalk_register_feature()` helper when
 * present. When the helper does not yet exist (older plugin core, parallel
 * branch state), the feature falls back to wiring its own WP hooks so it
 * still boots independently. This defensive stub is what lets new features
 * land without modifying the central bootstrap or dashboard class.
 *
 * Loaded from `plugins_loaded` indirectly: the include file in this
 * directory is loaded by the central loader OR self-loaded via the
 * function-exists stub at the bottom of this file when included
 * standalone.
 *
 * @package ShopwalkWooCommerce\Features\Recommendations
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-recommendations-api-client.php';
require_once __DIR__ . '/class-recommendations-block-handler.php';
require_once __DIR__ . '/class-recommendations-rest.php';

if ( function_exists( 'is_admin' ) && is_admin() ) {
	require_once __DIR__ . '/class-recommendations-admin.php';
}

/**
 * Feature container — owns lifecycle, dashboard panel descriptor, and
 * exposes the public boot() method the central loader (or our own
 * function-exists fallback) calls once on `plugins_loaded`.
 */
final class Shopwalk_Recommendations_Feature {

	/**
	 * Option key for the per-slot settings JSON blob. Single option keeps
	 * the admin write surface to one update_option call per save.
	 */
	public const OPTION_SETTINGS = 'shopwalk_recommendations_settings';

	/**
	 * Tiers that unlock recommendations. The plugin treats anything else as
	 * "Free" and surfaces the upgrade nudge instead of wiring the runtime.
	 *
	 * @var array<int,string>
	 */
	private const ENABLED_TIERS = array( 'pro', 'pro_plus', 'partner_monthly', 'partner_annual' );

	/**
	 * Singleton.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Get singleton.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Wire WP hooks. Idempotent: WordPress dedupes identical (object,
	 * method) action callbacks.
	 *
	 * @return void
	 */
	public function boot(): void {
		// REST + AJAX endpoint for lazy-load. Registered for every request
		// so frontends can fetch even when the in-page block hasn't been
		// inlined by the merchant's theme.
		add_action(
			'rest_api_init',
			array( Shopwalk_Recommendations_REST::class, 'register_routes' )
		);

		// Shortcode + Gutenberg block. The block server-renders an empty
		// placeholder + a data-attribute; the lazy-load JS hydrates it
		// after the page paints (no blocking call in SSR).
		add_action(
			'init',
			array( Shopwalk_Recommendations_Block_Handler::class, 'register' )
		);

		// Storefront assets — only enqueued when a recommendation block /
		// shortcode is actually on the page.
		add_action(
			'wp_enqueue_scripts',
			array( $this, 'maybe_enqueue_storefront_assets' )
		);

		// Optional auto-inject on the single product page. Off by default
		// unless the merchant flips the setting. We use
		// `woocommerce_after_single_product_summary` at priority 20 to land
		// after the WC core related products block but before reviews.
		$settings = self::settings();
		if ( ! empty( $settings['inject_single_product'] ) ) {
			add_action(
				'woocommerce_after_single_product_summary',
				array( $this, 'render_single_product_slot' ),
				20
			);
		}
		if ( ! empty( $settings['inject_cart_fbt'] ) ) {
			add_action(
				'woocommerce_cart_collaterals',
				array( $this, 'render_cart_fbt_slot' )
			);
		}

		// Admin dashboard panel + settings page. Loaded only when in
		// wp-admin so the storefront path stays lean.
		if ( is_admin() && class_exists( 'Shopwalk_Recommendations_Admin' ) ) {
			Shopwalk_Recommendations_Admin::instance()->boot();
		}
	}

	/**
	 * Dashboard panel descriptor consumed by the central dashboard class
	 * when it discovers feature panels via `shopwalk_register_feature()`.
	 * The shape mirrors what the dashboard renders today for other
	 * features (slug, label, callback).
	 *
	 * @return array{slug:string,label:string,callback:callable,order:int}
	 */
	public function dashboard_panel(): array {
		return array(
			'slug'     => 'recommendations',
			'label'    => __( 'AI Recommendations', 'shopwalk-for-woocommerce' ),
			'order'    => 40,
			'callback' => static function (): void {
				$view = __DIR__ . '/views/panel.php';
				if ( file_exists( $view ) ) {
					include $view;
				}
			},
		);
	}

	/**
	 * Render the single-product slot. Outputs the carousel container; the
	 * lazy-load JS hits /wp-json/shopwalk/v1/recommendations to populate.
	 *
	 * @return void
	 */
	public function render_single_product_slot(): void {
		global $product;
		if ( ! $product || ! is_object( $product ) ) {
			return;
		}
		$product_id = method_exists( $product, 'get_id' ) ? (int) $product->get_id() : 0;
		if ( $product_id <= 0 ) {
			return;
		}
		$settings = self::settings();
		echo Shopwalk_Recommendations_Block_Handler::render_container( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_container() returns fully escaped HTML scaffolding.
			array(
				'type'       => 'also_viewed',
				'product_id' => $product_id,
				'count'      => (int) ( $settings['default_count'] ?? 6 ),
				'layout'     => (string) ( $settings['default_layout'] ?? 'carousel' ),
				'title'      => __( 'Customers also viewed', 'shopwalk-for-woocommerce' ),
			)
		);
	}

	/**
	 * Render the cart "frequently bought together" slot. Fires inside
	 * woocommerce_cart_collaterals so the layout lands above the cart
	 * totals block.
	 *
	 * @return void
	 */
	public function render_cart_fbt_slot(): void {
		$cart = function_exists( 'WC' ) ? WC()->cart : null;
		if ( ! $cart || $cart->is_empty() ) {
			return;
		}
		// Use the highest-value cart item as the FBT context anchor.
		$anchor_product_id = 0;
		$anchor_value      = -1.0;
		foreach ( $cart->get_cart() as $item ) {
			$line_total = (float) ( $item['line_total'] ?? 0 );
			if ( $line_total > $anchor_value && ! empty( $item['product_id'] ) ) {
				$anchor_value      = $line_total;
				$anchor_product_id = (int) $item['product_id'];
			}
		}
		if ( $anchor_product_id <= 0 ) {
			return;
		}
		$settings = self::settings();
		echo Shopwalk_Recommendations_Block_Handler::render_container( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_container() returns fully escaped HTML scaffolding.
			array(
				'type'       => 'fbt',
				'product_id' => $anchor_product_id,
				'count'      => (int) ( $settings['default_count'] ?? 3 ),
				'layout'     => 'grid',
				'title'      => __( 'Frequently bought together', 'shopwalk-for-woocommerce' ),
			)
		);
	}

	/**
	 * Enqueue the storefront JS/CSS when the current request is likely to
	 * need them. We always enqueue on single product + cart (the
	 * auto-inject targets), and let the block-handler enqueue on-demand
	 * for shortcode/block usage via wp_enqueue_script() at render time.
	 *
	 * @return void
	 */
	public function maybe_enqueue_storefront_assets(): void {
		if ( ! self::is_enabled() ) {
			return;
		}
		$is_product_or_cart = ( function_exists( 'is_product' ) && is_product() )
			|| ( function_exists( 'is_cart' ) && is_cart() );
		if ( ! $is_product_or_cart ) {
			return;
		}
		self::enqueue_storefront_assets();
	}

	/**
	 * Public enqueue hook — also called from the block-render path when a
	 * block or shortcode is actually present on the page.
	 *
	 * @return void
	 */
	public static function enqueue_storefront_assets(): void {
		$version = defined( 'WOOCOMMERCE_SHOPWALK_VERSION' ) ? WOOCOMMERCE_SHOPWALK_VERSION : '0.0.0';
		$base    = defined( 'WOOCOMMERCE_SHOPWALK_PLUGIN_URL' ) ? WOOCOMMERCE_SHOPWALK_PLUGIN_URL : plugin_dir_url( dirname( __FILE__, 3 ) ) . '/';

		wp_enqueue_style(
			'shopwalk-recommendations',
			$base . 'assets/css/recommendations.css',
			array(),
			$version
		);
		wp_enqueue_script(
			'shopwalk-recommendations',
			$base . 'assets/js/recommendations.js',
			array(),
			$version,
			true
		);
		wp_localize_script(
			'shopwalk-recommendations',
			'ShopwalkRecommendations',
			array(
				'endpoint' => esc_url_raw( rest_url( 'shopwalk/v1/recommendations' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
			)
		);
	}

	/**
	 * Returns the persisted settings blob, merged with defaults so callers
	 * never have to null-check individual keys.
	 *
	 * @return array<string,mixed>
	 */
	public static function settings(): array {
		$stored = get_option( self::OPTION_SETTINGS, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		$defaults = array(
			'inject_single_product' => true,
			'inject_cart_fbt'       => true,
			'default_count'         => 6,
			'default_layout'        => 'carousel',
			'enabled_types'         => array( 'also_viewed', 'related', 'fbt' ),
		);
		return array_merge( $defaults, $stored );
	}

	/**
	 * Tier gate. Returns true only when a license is present AND the cached
	 * plan slug is one we treat as Pro-tier. Free tier resolves to false so
	 * the storefront slots stay dormant and the admin shows the upgrade
	 * nudge.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		if ( ! function_exists( 'get_option' ) ) {
			return false;
		}
		$key = (string) get_option( 'shopwalk_license_key', '' );
		if ( '' === $key ) {
			return false;
		}
		$plan = strtolower( (string) get_option( 'shopwalk_plan', '' ) );
		if ( '' === $plan ) {
			// Older API responses don't return a plan slug. We're
			// optimistic on those (Pro plugins predate Free) so the
			// feature still works on legacy installs; the dashboard
			// banner will correct course once the next /license/status
			// heartbeat lands.
			return true;
		}
		return in_array( $plan, self::ENABLED_TIERS, true );
	}

	/**
	 * Surface a degraded-state reason for the dashboard panel. The
	 * recommendations ranker needs both catalog sync (embeddings) AND
	 * order sync (purchase signals) to produce useful output. When either
	 * is missing we tell the merchant so they don't blame the feature for
	 * an upstream gap.
	 *
	 * @return string Empty string when healthy, otherwise a translated
	 *                explanation string.
	 */
	public static function degraded_reason(): string {
		// Catalog sync state — written by the sync feature on its last
		// successful batch. We accept either of the two option names the
		// sync feature has used historically.
		$last_catalog = (int) get_option( 'shopwalk_last_catalog_sync_ts', (int) get_option( 'shopwalk_last_sync_ts', 0 ) );
		$last_orders  = (int) get_option( 'shopwalk_last_order_sync_ts', 0 );

		if ( $last_catalog <= 0 ) {
			return __( 'Recommendations require catalog sync to have populated product embeddings. Start a catalog sync to enable.', 'shopwalk-for-woocommerce' );
		}
		if ( $last_orders <= 0 ) {
			return __( 'Recommendations work best after order sync has populated the purchase graph. Similarity-only fallback is in use until then.', 'shopwalk-for-woocommerce' );
		}
		return '';
	}
}

// ─── Self-registration ──────────────────────────────────────────────────────
//
// Prefer the central `shopwalk_register_feature()` helper when the core
// plugin exposes it. The helper hands the dashboard panel descriptor to the
// dashboard class and calls boot() at the right moment. When the helper
// isn't available (older plugin core, parallel branch state), wire our own
// boot on `plugins_loaded` so the feature still works standalone.

if ( function_exists( 'shopwalk_register_feature' ) ) {
	shopwalk_register_feature(
		array(
			'slug'      => 'recommendations',
			'instance'  => Shopwalk_Recommendations_Feature::instance(),
			'boot'      => array( Shopwalk_Recommendations_Feature::instance(), 'boot' ),
			'dashboard' => array( Shopwalk_Recommendations_Feature::instance(), 'dashboard_panel' ),
		)
	);
} elseif ( function_exists( 'add_action' ) ) {
	add_action(
		'plugins_loaded',
		static function (): void {
			Shopwalk_Recommendations_Feature::instance()->boot();
		},
		20
	);
}

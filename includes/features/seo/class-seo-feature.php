<?php
/**
 * Shopwalk_Seo_Feature — AI SEO + image optimization (v1.0 launch feature).
 *
 * Auto-generates meta titles, meta descriptions, image alt text, and an
 * on-page SEO audit checklist. Runs per-product (meta box) or as a bulk
 * Action Scheduler job. Pro / Pro+ tier only.
 *
 * Self-registers via `shopwalk_register_feature()` so the plugin bootstrap
 * does not need editing. A defensive function-exists stub provides a no-op
 * registrar so this file can ship before the central registry lands; once
 * the registry is in place the real function will be called instead.
 *
 * @package ShopwalkWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shopwalk_Seo_Feature — feature entry point + dashboard panel declaration.
 */
final class Shopwalk_Seo_Feature {

	/**
	 * Feature slug used for option keys, capability checks, and the dashboard
	 * panel id. Stable identifier — do not rename without a migration.
	 */
	public const SLUG = 'seo';

	/**
	 * Tiers that may use this feature. Free shows "Pro required" upsell.
	 */
	public const ALLOWED_TIERS = array( 'pro_lite', 'pro', 'pro_plus' );

	/**
	 * Singleton.
	 *
	 * @var Shopwalk_Seo_Feature|null
	 */
	private static ?Shopwalk_Seo_Feature $instance = null;

	/**
	 * Returns the singleton instance.
	 */
	public static function instance(): Shopwalk_Seo_Feature {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Wires hooks. Idempotent (singleton-guarded).
	 */
	public function boot(): void {
		// Meta box on product edit screen.
		add_action(
			'add_meta_boxes',
			static function (): void {
				Shopwalk_Seo_Meta_Box::register();
			}
		);

		// AJAX endpoints — per-product preview + apply.
		add_action( 'wp_ajax_shopwalk_seo_generate', array( Shopwalk_Seo_Meta_Box::class, 'ajax_generate' ) );
		add_action( 'wp_ajax_shopwalk_seo_apply', array( Shopwalk_Seo_Meta_Box::class, 'ajax_apply' ) );

		// Bulk Action Scheduler hooks.
		add_action( 'shopwalk_seo_bulk_process_item', array( Shopwalk_Seo_Bulk::class, 'process_item' ), 10, 2 );
		add_action( 'shopwalk_seo_bulk_kickoff', array( Shopwalk_Seo_Bulk::class, 'kickoff' ), 10, 1 );

		// Admin assets — only on our screens.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// Dashboard panel.
		add_action( 'admin_menu', array( Shopwalk_Seo_Admin::class, 'register_submenu' ), 20 );
	}

	/**
	 * Conditional asset enqueue for our admin screens (product editor + the
	 * SEO bulk panel). Avoids pushing CSS/JS onto unrelated WP admin pages.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public function enqueue_assets( string $hook ): void {
		$is_product_edit = in_array( $hook, array( 'post.php', 'post-new.php' ), true )
			&& isset( $GLOBALS['post_type'] ) && 'product' === $GLOBALS['post_type'];
		$is_seo_panel    = false !== strpos( $hook, 'shopwalk-seo' );

		if ( ! $is_product_edit && ! $is_seo_panel ) {
			return;
		}

		wp_enqueue_style(
			'shopwalk-seo',
			WOOCOMMERCE_SHOPWALK_PLUGIN_URL . 'assets/css/seo.css',
			array(),
			WOOCOMMERCE_SHOPWALK_VERSION
		);
		wp_enqueue_script(
			'shopwalk-seo',
			WOOCOMMERCE_SHOPWALK_PLUGIN_URL . 'assets/js/seo.js',
			array( 'jquery' ),
			WOOCOMMERCE_SHOPWALK_VERSION,
			true
		);
		wp_localize_script(
			'shopwalk-seo',
			'ShopwalkSeo',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'shopwalk_seo' ),
				'i18n'     => array(
					'generating'        => __( 'Generating SEO…', 'shopwalk-for-woocommerce' ),
					'apply'             => __( 'Apply', 'shopwalk-for-woocommerce' ),
					'reject'            => __( 'Reject', 'shopwalk-for-woocommerce' ),
					'overwrite_warning' => __( 'Existing alt text will be overwritten.', 'shopwalk-for-woocommerce' ),
				),
			)
		);
	}

	/**
	 * Returns whether the current site is licensed at a tier that may use SEO.
	 * Defensive — tolerates the license class being absent (e.g. during the
	 * shopwalk-tier-2 bootstrap not having loaded yet).
	 */
	public static function is_tier_allowed(): bool {
		if ( ! class_exists( 'Shopwalk_License' ) ) {
			return false;
		}
		if ( ! Shopwalk_License::is_valid() ) {
			return false;
		}
		$plan = (string) get_option( 'shopwalk_plan', '' );
		// Empty plan post-activation defaults to "pro" by convention in
		// existing features; mirror that here for consistency.
		if ( '' === $plan ) {
			$plan = 'pro';
		}
		return in_array( $plan, self::ALLOWED_TIERS, true );
	}

	/**
	 * Dashboard panel descriptor consumed by the central dashboard renderer.
	 * Returning an array keeps this file decoupled from the dashboard's
	 * concrete class.
	 *
	 * @return array{slug:string,label:string,callback:callable,order:int}
	 */
	public static function dashboard_panel(): array {
		return array(
			'slug'     => self::SLUG,
			'label'    => __( 'AI SEO', 'shopwalk-for-woocommerce' ),
			'callback' => array( Shopwalk_Seo_Admin::class, 'render_panel' ),
			'order'    => 40,
		);
	}
}

// ─── Self-registration ──────────────────────────────────────────────────────
//
// The central plugin bootstrap (separate session) exposes a
// shopwalk_register_feature() function that the dashboard reads from to wire
// each feature into the admin nav + lifecycle. Until that lands, we define a
// safe stub so this file is harmless to load on its own — the stub only
// remembers what was registered, in a global, for later pickup.

if ( ! function_exists( 'shopwalk_register_feature' ) ) {
	/**
	 * Defensive shim — collects feature descriptors until the real registrar
	 * (from the central bootstrap) replaces this function. Returns true so
	 * callers can treat the result as a success signal.
	 *
	 * @param array $feature Feature descriptor.
	 * @return bool
	 */
	function shopwalk_register_feature( array $feature ): bool { // phpcs:ignore
		if ( ! isset( $GLOBALS['shopwalk_pending_features'] ) || ! is_array( $GLOBALS['shopwalk_pending_features'] ) ) {
			$GLOBALS['shopwalk_pending_features'] = array();
		}
		$GLOBALS['shopwalk_pending_features'][ $feature['slug'] ?? wp_generate_uuid4() ] = $feature;
		return true;
	}
}

shopwalk_register_feature(
	array(
		'slug'      => Shopwalk_Seo_Feature::SLUG,
		'label'     => __( 'AI SEO', 'shopwalk-for-woocommerce' ),
		'version'   => '4.5.0',
		'tiers'     => Shopwalk_Seo_Feature::ALLOWED_TIERS,
		'requires'  => array( 'catalog_sync' ),
		'panel'     => array( Shopwalk_Seo_Feature::class, 'dashboard_panel' ),
		'boot'      => array( Shopwalk_Seo_Feature::instance(), 'boot' ),
		'singleton' => Shopwalk_Seo_Feature::class,
	)
);

// Boot directly as well — the central registry may or may not call boot()
// itself depending on when the parallel-session bootstrap lands. Boot is
// idempotent (singleton + add_action de-duplicates within a request).
add_action(
	'plugins_loaded',
	static function (): void {
		Shopwalk_Seo_Feature::instance()->boot();
	},
	20
);

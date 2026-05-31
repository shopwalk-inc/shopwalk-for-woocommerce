<?php
/**
 * Shopwalk_Seo_Admin — dashboard panel + bulk-run controls.
 *
 * Renders the "AI SEO" panel under WP-Admin → Shopwalk. Lets the merchant
 * pick a scope + fields, start / cancel a bulk run, and watch progress.
 *
 * @package ShopwalkWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shopwalk_Seo_Admin — submenu + panel.
 */
final class Shopwalk_Seo_Admin {

	public const PAGE_SLUG = 'shopwalk-seo';

	/**
	 * Register the submenu under Shopwalk. Hook target for `admin_menu`.
	 *
	 * If the central Shopwalk dashboard menu hasn't loaded yet (parallel
	 * sessions / fresh install with no license), the parent is the
	 * top-level "Shopwalk" slug; if that's absent, fall back to
	 * `woocommerce`.
	 */
	public static function register_submenu(): void {
		$parent = '';
		// Both parent candidates are global menu items registered by other
		// features; we can't know which is present, so we try both.
		$candidates = array( 'shopwalk-for-woocommerce', 'woocommerce' );
		foreach ( $candidates as $candidate ) {
			$parent = $candidate;
			break;
		}

		add_submenu_page(
			$parent,
			__( 'Shopwalk AI SEO', 'shopwalk-for-woocommerce' ),
			__( 'AI SEO', 'shopwalk-for-woocommerce' ),
			'manage_woocommerce',
			self::PAGE_SLUG,
			array( self::class, 'render_panel' )
		);

		// Lightweight POST handlers for "start" / "cancel" — keeps the
		// initial panel a single page with no extra REST surface.
		add_action( 'admin_post_shopwalk_seo_start', array( self::class, 'handle_start' ) );
		add_action( 'admin_post_shopwalk_seo_cancel', array( self::class, 'handle_cancel' ) );
	}

	/**
	 * Render the panel. Used both as a top-level admin page callback and
	 * as the dashboard-embed callback when the central dashboard mounts us.
	 */
	public static function render_panel(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'shopwalk-for-woocommerce' ) );
		}

		$tier_allowed = Shopwalk_Seo_Feature::is_tier_allowed();
		$state        = Shopwalk_Seo_Bulk::state();
		$target_label = Shopwalk_Seo_Conflict_Detector::active_target_label();
		$is_fallback  = Shopwalk_Seo_Conflict_Detector::is_fallback();
		$categories   = function_exists( 'get_terms' )
			? get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) )
			: array();
		if ( is_wp_error( $categories ) ) {
			$categories = array();
		}

		include WOOCOMMERCE_SHOPWALK_PLUGIN_DIR . 'includes/features/seo/views/panel.php';
	}

	/**
	 * Handle the "Start bulk run" form submission.
	 */
	public static function handle_start(): void {
		check_admin_referer( 'shopwalk_seo_start' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'shopwalk-for-woocommerce' ) );
		}

		$options = array(
			'scope'           => isset( $_POST['scope'] ) ? sanitize_key( wp_unslash( (string) $_POST['scope'] ) ) : 'all',
			'category_id'     => isset( $_POST['category_id'] ) ? (int) $_POST['category_id'] : 0,
			'fields'          => isset( $_POST['fields'] ) && is_array( $_POST['fields'] )
				? array_map( 'sanitize_key', wp_unslash( (array) $_POST['fields'] ) )
				: array(),
			'overwrite_alt'   => ! empty( $_POST['overwrite_alt'] ),
			'focus_keyphrase' => isset( $_POST['focus_keyphrase'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['focus_keyphrase'] ) ) : '',
		);

		Shopwalk_Seo_Bulk::start( $options );

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&started=1' ) );
		exit;
	}

	/**
	 * Handle the "Cancel" form submission.
	 */
	public static function handle_cancel(): void {
		check_admin_referer( 'shopwalk_seo_cancel' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'shopwalk-for-woocommerce' ) );
		}

		Shopwalk_Seo_Bulk::cancel();

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&cancelled=1' ) );
		exit;
	}
}

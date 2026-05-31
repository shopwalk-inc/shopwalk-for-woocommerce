<?php
/**
 * Admin surface for the recommendations feature.
 *
 * Renders the dashboard panel (placement settings, type toggles, default
 * count) and handles the POST that persists the settings option. When
 * the central plugin exposes `shopwalk_register_feature()` the dashboard
 * class picks up the panel via the descriptor — this class still owns
 * the form-submit handler so the dashboard doesn't have to know about
 * recommendations specifically.
 *
 * @package ShopwalkWooCommerce\Features\Recommendations
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin handler.
 */
final class Shopwalk_Recommendations_Admin {

	/**
	 * Nonce action for the settings form.
	 */
	private const NONCE_ACTION = 'shopwalk_recommendations_save';

	/**
	 * Nonce field name.
	 */
	private const NONCE_FIELD = '_shopwalk_recs_nonce';

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
	 * Wire admin hooks.
	 *
	 * @return void
	 */
	public function boot(): void {
		add_action( 'admin_post_shopwalk_recommendations_save', array( $this, 'handle_save' ) );
		add_action( 'admin_notices', array( $this, 'maybe_render_save_notice' ) );
	}

	/**
	 * Handle the settings form POST.
	 *
	 * @return void
	 */
	public function handle_save(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'shopwalk-for-woocommerce' ), '', array( 'response' => 403 ) );
		}
		$nonce = isset( $_POST[ self::NONCE_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			wp_die( esc_html__( 'Security check failed.', 'shopwalk-for-woocommerce' ), '', array( 'response' => 403 ) );
		}

		$inject_single        = ! empty( $_POST['inject_single_product'] );
		$inject_cart_fbt      = ! empty( $_POST['inject_cart_fbt'] );
		$default_count        = isset( $_POST['default_count'] ) ? max( 1, min( 24, (int) $_POST['default_count'] ) ) : 6;
		$default_layout_raw   = isset( $_POST['default_layout'] ) ? sanitize_text_field( wp_unslash( $_POST['default_layout'] ) ) : 'carousel';
		$default_layout       = in_array( $default_layout_raw, array( 'carousel', 'grid', 'list' ), true ) ? $default_layout_raw : 'carousel';

		$enabled_types  = array();
		$allowed_types  = array( 'also_viewed', 'related', 'fbt', 'personalized' );
		if ( isset( $_POST['enabled_types'] ) && is_array( $_POST['enabled_types'] ) ) {
			foreach ( $_POST['enabled_types'] as $t ) {
				$t = sanitize_text_field( wp_unslash( (string) $t ) );
				if ( in_array( $t, $allowed_types, true ) ) {
					$enabled_types[] = $t;
				}
			}
		}
		if ( empty( $enabled_types ) ) {
			// Never persist an empty allow-list — that effectively
			// disables the feature without a UI signal. Defaulting to
			// the spec's three v1 types keeps storefront slots populated.
			$enabled_types = array( 'also_viewed', 'related', 'fbt' );
		}

		update_option(
			Shopwalk_Recommendations_Feature::OPTION_SETTINGS,
			array(
				'inject_single_product' => $inject_single,
				'inject_cart_fbt'       => $inject_cart_fbt,
				'default_count'         => $default_count,
				'default_layout'        => $default_layout,
				'enabled_types'         => $enabled_types,
			),
			false
		);

		$referer = wp_get_referer();
		$target  = $referer ? add_query_arg( 'shopwalk_recs_saved', '1', $referer ) : admin_url( 'admin.php?page=shopwalk-for-woocommerce' );
		wp_safe_redirect( $target );
		exit;
	}

	/**
	 * Render the success notice after a save round-trip.
	 *
	 * @return void
	 */
	public function maybe_render_save_notice(): void {
		if ( empty( $_GET['shopwalk_recs_saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Notification trigger only.
			return;
		}
		echo '<div class="notice notice-success is-dismissible"><p>'
			. esc_html__( 'Recommendation settings saved.', 'shopwalk-for-woocommerce' )
			. '</p></div>';
	}

	/**
	 * Public helper — returns the nonce action + field names so the view
	 * file can render the form without duplicating constants.
	 *
	 * @return array{action:string,field:string}
	 */
	public static function nonce_meta(): array {
		return array(
			'action' => self::NONCE_ACTION,
			'field'  => self::NONCE_FIELD,
		);
	}
}

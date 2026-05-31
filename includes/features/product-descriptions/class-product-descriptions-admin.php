<?php
/**
 * Shopwalk_Product_Descriptions_Admin — dashboard panel + bulk runner UI.
 *
 * Provides:
 *
 *  - A standalone admin submenu page under "Shopwalk" → "Product Descriptions"
 *  - Bulk job kickoff handler (POSTs from the panel form)
 *  - AJAX/REST endpoint the panel polls for in-flight job progress
 *
 * @package ShopwalkWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shopwalk_Product_Descriptions_Admin — admin surface for the feature.
 */
final class Shopwalk_Product_Descriptions_Admin {

	public const MENU_SLUG    = 'shopwalk-product-descriptions';
	public const NONCE_ACTION = 'shopwalk_pd_bulk_kickoff';

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 20 );
		add_action( 'admin_post_shopwalk_pd_bulk_kickoff', array( $this, 'handle_bulk_kickoff' ) );
		add_action( 'admin_post_shopwalk_pd_bulk_cancel', array( $this, 'handle_bulk_cancel' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
	}

	/**
	 * Register the "Product Descriptions" subpage under the Shopwalk menu.
	 * Registered at priority 20 so the parent menu (in the central
	 * dashboard) has been added first.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		add_submenu_page(
			'shopwalk-for-woocommerce',
			__( 'AI Product Descriptions', 'shopwalk-for-woocommerce' ),
			__( 'Product Descriptions', 'shopwalk-for-woocommerce' ),
			'manage_woocommerce',
			self::MENU_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Render the admin page (delegates to the panel view).
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! Shopwalk_Product_Descriptions_Feature::is_pro() ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'AI Product Descriptions', 'shopwalk-for-woocommerce' ) . '</h1>';
			Shopwalk_Product_Descriptions_Feature::render_dashboard_panel();
			echo '</div>';
			return;
		}
		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'AI Product Descriptions', 'shopwalk-for-woocommerce' ) . '</h1>';
		Shopwalk_Product_Descriptions_Feature::render_dashboard_panel();
		echo '</div>';
	}

	/**
	 * Handle the bulk-kickoff form POST. Resolves the scope to a product
	 * id list, enqueues one AS task per product, and redirects back to
	 * the panel with the new job_id in the query string.
	 *
	 * @return void
	 */
	public function handle_bulk_kickoff(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! Shopwalk_Product_Descriptions_Feature::is_pro() ) {
			wp_die( esc_html__( 'Forbidden', 'shopwalk-for-woocommerce' ), 403 );
		}
		check_admin_referer( self::NONCE_ACTION );

		$scope     = (string) ( $_POST['scope'] ?? 'all' );
		$opts      = array(
			'fields'          => isset( $_POST['fields'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['fields'] ) ) : array( 'long', 'short' ),
			'tone'            => sanitize_key( (string) ( $_POST['tone'] ?? 'brand_voice' ) ),
			'length'          => sanitize_key( (string) ( $_POST['length'] ?? 'medium' ) ),
			'focus_keyphrase' => sanitize_text_field( (string) wp_unslash( $_POST['focus_keyphrase'] ?? '' ) ),
			'include_images'  => ! empty( $_POST['include_images'] ),
			'mode'            => sanitize_key( (string) ( $_POST['mode'] ?? 'review_queue' ) ),
		);

		$bulk        = new Shopwalk_Product_Descriptions_Bulk();
		$product_ids = $bulk->resolve_products( $scope );
		$job_id      = $bulk->enqueue_products( $product_ids, $opts );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                         => self::MENU_SLUG,
					'shopwalk_bulk_job'            => $job_id,
					'shopwalk_bulk_items_enqueued' => count( $product_ids ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Handle a cancel-job POST from the panel.
	 *
	 * @return void
	 */
	public function handle_bulk_cancel(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! Shopwalk_Product_Descriptions_Feature::is_pro() ) {
			wp_die( esc_html__( 'Forbidden', 'shopwalk-for-woocommerce' ), 403 );
		}
		check_admin_referer( self::NONCE_ACTION );
		$job_id = sanitize_text_field( (string) wp_unslash( $_POST['job_id'] ?? '' ) );
		if ( '' !== $job_id ) {
			$bulk = new Shopwalk_Product_Descriptions_Bulk();
			$bulk->cancel( $job_id );
		}
		wp_safe_redirect( add_query_arg( array( 'page' => self::MENU_SLUG, 'cancelled' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Register the panel-progress poll endpoint.
	 *
	 * @return void
	 */
	public function register_rest_routes(): void {
		register_rest_route(
			Shopwalk_Product_Descriptions_Meta_Box::REST_NS,
			'/descriptions/job/(?P<job_id>[A-Za-z0-9_-]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_job_status' ),
				'permission_callback' => array( $this, 'rest_permission_check' ),
			)
		);
	}

	/**
	 * REST permission check — manage_woocommerce + Pro tier.
	 *
	 * @return bool
	 */
	public function rest_permission_check(): bool {
		return current_user_can( 'manage_woocommerce' ) && Shopwalk_Product_Descriptions_Feature::is_pro();
	}

	/**
	 * REST handler — returns the persisted job descriptor for the panel
	 * to render progress.
	 *
	 * @param WP_REST_Request $req REST request.
	 * @return WP_REST_Response
	 */
	public function rest_job_status( $req ) {
		$job_id = sanitize_text_field( (string) $req->get_param( 'job_id' ) );
		$bulk   = new Shopwalk_Product_Descriptions_Bulk();
		$job    = $bulk->get_job( $job_id );
		if ( null === $job ) {
			return rest_ensure_response( array( 'ok' => false, 'error' => 'not_found' ) );
		}
		return rest_ensure_response( array( 'ok' => true, 'job' => $job ) );
	}
}

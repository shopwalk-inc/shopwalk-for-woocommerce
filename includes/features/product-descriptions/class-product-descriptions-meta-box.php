<?php
/**
 * Shopwalk_Product_Descriptions_Meta_Box — per-product editor UI.
 *
 * Renders a meta box on the WC product edit screen with:
 *
 *  - Generate button (long / short / both)
 *  - Tone selector (Brand voice / Friendly / Professional / Technical / Playful)
 *  - Length selector (short / medium / long)
 *  - Focus keyphrase field
 *  - Preview pane with accept / reject controls
 *  - Generation history list (last 5 versions, one-click revert)
 *
 * Handles two REST endpoints under `/wp-json/shopwalk/v1/descriptions/`:
 *
 *  - POST /generate  — synchronous per-product generation (4-8s typical)
 *  - POST /revert    — revert to a history entry
 *
 * @package ShopwalkWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shopwalk_Product_Descriptions_Meta_Box — product-edit-screen UI.
 */
final class Shopwalk_Product_Descriptions_Meta_Box {

	public const NONCE_ACTION = 'shopwalk_pd_meta_box';
	public const REST_NS      = 'shopwalk/v1';

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'register_meta_box' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
	}

	/**
	 * Register the meta box on the WC product edit screen.
	 *
	 * @return void
	 */
	public function register_meta_box(): void {
		if ( ! Shopwalk_Product_Descriptions_Feature::is_pro() ) {
			return;
		}
		add_meta_box(
			'shopwalk-product-descriptions',
			__( 'Shopwalk AI — Descriptions', 'shopwalk-for-woocommerce' ),
			array( $this, 'render' ),
			'product',
			'side',
			'high'
		);
	}

	/**
	 * Enqueue meta-box + panel CSS/JS on plugin-owned screens.
	 *
	 * @param string $hook Current admin hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook ): void {
		$is_product_edit = in_array( $hook, array( 'post.php', 'post-new.php' ), true )
			&& ( $_GET['post_type'] ?? get_post_type( (int) ( $_GET['post'] ?? 0 ) ) ) === 'product'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$is_panel        = ( $hook && false !== strpos( (string) $hook, 'shopwalk-product-descriptions' ) );

		if ( ! $is_product_edit && ! $is_panel ) {
			return;
		}

		$base = defined( 'WOOCOMMERCE_SHOPWALK_PLUGIN_URL' ) ? WOOCOMMERCE_SHOPWALK_PLUGIN_URL : plugin_dir_url( WOOCOMMERCE_SHOPWALK_PLUGIN_FILE ?? __FILE__ );

		wp_enqueue_style(
			'shopwalk-product-descriptions',
			$base . 'assets/css/product-descriptions.css',
			array(),
			Shopwalk_Product_Descriptions_Feature::VERSION
		);

		wp_enqueue_script(
			'shopwalk-product-descriptions',
			$base . 'assets/js/product-descriptions.js',
			array( 'jquery' ),
			Shopwalk_Product_Descriptions_Feature::VERSION,
			true
		);

		wp_localize_script(
			'shopwalk-product-descriptions',
			'ShopwalkProductDescriptions',
			array(
				'restRoot'   => esc_url_raw( rest_url( self::REST_NS . '/descriptions/' ) ),
				'restNonce'  => wp_create_nonce( 'wp_rest' ),
				'i18n'       => array(
					'generating'   => __( 'Generating with Shopwalk…', 'shopwalk-for-woocommerce' ),
					'error'        => __( 'Generation failed. Please try again.', 'shopwalk-for-woocommerce' ),
					'confirmApply' => __( 'Replace the current description with the generated text?', 'shopwalk-for-woocommerce' ),
					'reverted'     => __( 'Reverted to previous version.', 'shopwalk-for-woocommerce' ),
				),
			)
		);
	}

	/**
	 * Render the meta box.
	 *
	 * @param WP_Post $post The current post.
	 * @return void
	 */
	public function render( $post ): void {
		$product_id = isset( $post->ID ) ? (int) $post->ID : 0;
		$generator  = new Shopwalk_Product_Descriptions_Generator();
		$is_stale   = $generator->is_stale( $product_id );
		$brand_id   = $generator->brand_voice_id();
		$history    = (array) get_post_meta( $product_id, Shopwalk_Product_Descriptions_Generator::META_HISTORY, true );
		$pending    = (array) get_post_meta( $product_id, '_shopwalk_description_pending_review', true );

		include __DIR__ . '/views/meta-box.php';
	}

	/**
	 * Register `/wp-json/shopwalk/v1/descriptions/*` routes.
	 *
	 * @return void
	 */
	public function register_rest_routes(): void {
		register_rest_route(
			self::REST_NS,
			'/descriptions/generate',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_generate' ),
				'permission_callback' => array( $this, 'rest_permission_check' ),
				'args'                => array(
					'product_id'      => array( 'required' => true ),
					'fields'          => array( 'required' => false ),
					'tone'            => array( 'required' => false ),
					'length'          => array( 'required' => false ),
					'focus_keyphrase' => array( 'required' => false ),
					'include_images'  => array( 'required' => false ),
					'locale'          => array( 'required' => false ),
				),
			)
		);
		register_rest_route(
			self::REST_NS,
			'/descriptions/revert',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_revert' ),
				'permission_callback' => array( $this, 'rest_permission_check' ),
				'args'                => array(
					'product_id'    => array( 'required' => true ),
					'history_index' => array( 'required' => true ),
				),
			)
		);
		register_rest_route(
			self::REST_NS,
			'/descriptions/apply',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_apply' ),
				'permission_callback' => array( $this, 'rest_permission_check' ),
				'args'                => array(
					'product_id' => array( 'required' => true ),
					'long'       => array( 'required' => false ),
					'short'      => array( 'required' => false ),
				),
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
	 * Synchronous per-product generation handler.
	 *
	 * @param WP_REST_Request $req REST request.
	 * @return WP_REST_Response
	 */
	public function rest_generate( $req ) {
		$product_id = (int) $req->get_param( 'product_id' );
		if ( $product_id <= 0 ) {
			return rest_ensure_response( array( 'ok' => false, 'error' => 'invalid_product_id' ) );
		}

		$fields = (array) ( $req->get_param( 'fields' ) ?? array( 'long', 'short' ) );
		$opts   = array(
			'fields'          => $fields,
			'tone'            => (string) ( $req->get_param( 'tone' ) ?? 'brand_voice' ),
			'length'          => (string) ( $req->get_param( 'length' ) ?? 'medium' ),
			'focus_keyphrase' => (string) ( $req->get_param( 'focus_keyphrase' ) ?? '' ),
			'include_images'  => (bool) ( $req->get_param( 'include_images' ) ?? true ),
		);
		$locale = (string) ( $req->get_param( 'locale' ) ?? '' );
		if ( '' !== $locale && Shopwalk_Product_Descriptions_Feature::is_pro_plus() ) {
			$opts['locale'] = $locale;
		}

		$generator = new Shopwalk_Product_Descriptions_Generator();
		$result    = $generator->generate( $product_id, $opts );

		return rest_ensure_response( $result );
	}

	/**
	 * Apply a generation result to a product.
	 *
	 * @param WP_REST_Request $req REST request.
	 * @return WP_REST_Response
	 */
	public function rest_apply( $req ) {
		$product_id = (int) $req->get_param( 'product_id' );
		if ( $product_id <= 0 ) {
			return rest_ensure_response( array( 'ok' => false, 'error' => 'invalid_product_id' ) );
		}
		$generator = new Shopwalk_Product_Descriptions_Generator();
		$ok        = $generator->apply(
			$product_id,
			array(
				'long'  => (string) ( $req->get_param( 'long' ) ?? '' ),
				'short' => (string) ( $req->get_param( 'short' ) ?? '' ),
			)
		);
		return rest_ensure_response( array( 'ok' => $ok ) );
	}

	/**
	 * Revert a product to a prior history entry.
	 *
	 * @param WP_REST_Request $req REST request.
	 * @return WP_REST_Response
	 */
	public function rest_revert( $req ) {
		$product_id = (int) $req->get_param( 'product_id' );
		$idx        = (int) $req->get_param( 'history_index' );
		if ( $product_id <= 0 ) {
			return rest_ensure_response( array( 'ok' => false, 'error' => 'invalid_product_id' ) );
		}

		$history = (array) get_post_meta( $product_id, Shopwalk_Product_Descriptions_Generator::META_HISTORY, true );
		if ( ! isset( $history[ $idx ] ) || ! is_array( $history[ $idx ] ) ) {
			return rest_ensure_response( array( 'ok' => false, 'error' => 'history_not_found' ) );
		}
		$entry = $history[ $idx ];

		$generator = new Shopwalk_Product_Descriptions_Generator();
		$ok        = $generator->apply(
			$product_id,
			array(
				'long'  => (string) ( $entry['long'] ?? '' ),
				'short' => (string) ( $entry['short'] ?? '' ),
			)
		);
		return rest_ensure_response( array( 'ok' => $ok ) );
	}
}

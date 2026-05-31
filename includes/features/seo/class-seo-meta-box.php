<?php
/**
 * Shopwalk_Seo_Meta_Box — per-product "Generate SEO" meta box.
 *
 * Renders a small panel on the WC product edit screen. The merchant clicks
 * "Generate SEO" → AJAX call to /plugin/ai/seo/generate → preview pane →
 * "Apply" persists via Shopwalk_Seo_Generator::apply().
 *
 * Same shape as the product-descriptions meta box (preview → accept /
 * reject before applying) so the merchant has a consistent mental model
 * across AI features.
 *
 * @package ShopwalkWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shopwalk_Seo_Meta_Box — meta box registration + AJAX handlers.
 */
final class Shopwalk_Seo_Meta_Box {

	public const META_BOX_ID = 'shopwalk_seo_meta_box';

	/**
	 * Register the meta box on the WC product edit screen.
	 */
	public static function register(): void {
		if ( ! Shopwalk_Seo_Feature::is_tier_allowed() ) {
			// Free tier: register a tiny meta box that shows the upsell.
			add_meta_box(
				self::META_BOX_ID,
				__( 'Shopwalk AI SEO', 'shopwalk-for-woocommerce' ),
				array( self::class, 'render_upsell' ),
				'product',
				'side',
				'default'
			);
			return;
		}

		add_meta_box(
			self::META_BOX_ID,
			__( 'Shopwalk AI SEO', 'shopwalk-for-woocommerce' ),
			array( self::class, 'render' ),
			'product',
			'side',
			'default'
		);
	}

	/**
	 * Render the active meta box.
	 *
	 * @param WP_Post $post Current post object.
	 */
	public static function render( $post ): void {
		$product_id = (int) ( $post->ID ?? 0 );
		$target     = Shopwalk_Seo_Conflict_Detector::active_target_label();
		include WOOCOMMERCE_SHOPWALK_PLUGIN_DIR . 'includes/features/seo/views/meta-box.php';
	}

	/**
	 * Render the Free-tier upsell.
	 */
	public static function render_upsell(): void {
		echo '<p>' . esc_html__( 'AI SEO is a Pro feature. Upgrade to auto-generate meta titles, meta descriptions, and image alt text.', 'shopwalk-for-woocommerce' ) . '</p>';
		echo '<p><a class="button button-primary" href="' . esc_url( SHOPWALK_PARTNERS_URL ) . '" target="_blank" rel="noopener">' . esc_html__( 'Upgrade to Pro', 'shopwalk-for-woocommerce' ) . '</a></p>';
	}

	/**
	 * AJAX: generate (preview-only — does not write to the DB).
	 */
	public static function ajax_generate(): void {
		self::ajax_guard();

		$product_id      = isset( $_POST['product_id'] ) ? (int) $_POST['product_id'] : 0;
		$focus_keyphrase = isset( $_POST['focus_keyphrase'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['focus_keyphrase'] ) ) : '';
		$fields          = isset( $_POST['fields'] ) && is_array( $_POST['fields'] )
			? array_map( 'sanitize_key', wp_unslash( (array) $_POST['fields'] ) )
			: array( 'meta_title', 'meta_description', 'image_alt', 'seo_checklist' );

		if ( $product_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Missing product id.', 'shopwalk-for-woocommerce' ) ) );
		}

		$result = Shopwalk_Seo_Generator::generate( $product_id, $fields, $focus_keyphrase );
		if ( empty( $result['ok'] ) ) {
			wp_send_json_error( array( 'message' => $result['message'] ?? 'Unknown error.' ) );
		}

		wp_send_json_success( $result['data'] ?? array() );
	}

	/**
	 * AJAX: apply previewed generation to the product. The client sends back
	 * the generated payload it received from /generate so we don't have to
	 * re-call the backend just to persist.
	 */
	public static function ajax_apply(): void {
		self::ajax_guard();

		$product_id = isset( $_POST['product_id'] ) ? (int) $_POST['product_id'] : 0;
		if ( $product_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Missing product id.', 'shopwalk-for-woocommerce' ) ) );
		}

		if ( ! current_user_can( 'edit_post', $product_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'shopwalk-for-woocommerce' ) ) );
		}

		$raw       = isset( $_POST['generated'] ) ? wp_unslash( (string) $_POST['generated'] ) : '';
		$generated = json_decode( $raw, true );
		if ( ! is_array( $generated ) ) {
			wp_send_json_error( array( 'message' => __( 'Missing generated payload.', 'shopwalk-for-woocommerce' ) ) );
		}

		$apply_options = array(
			'meta_title'       => ! empty( $_POST['apply_meta_title'] ),
			'meta_description' => ! empty( $_POST['apply_meta_description'] ),
			'focus_keyphrase'  => ! empty( $_POST['apply_focus_keyphrase'] ),
			'image_alt'        => ! empty( $_POST['apply_image_alt'] ),
			'overwrite_alt'    => ! empty( $_POST['overwrite_alt'] ),
		);

		$result = Shopwalk_Seo_Generator::apply( $product_id, $generated, $apply_options );
		if ( empty( $result['ok'] ) ) {
			wp_send_json_error( array( 'message' => $result['message'] ?? 'Unknown error.' ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Shared AJAX guard — nonce + tier + capability for editing products.
	 */
	private static function ajax_guard(): void {
		check_ajax_referer( 'shopwalk_seo', 'nonce' );

		if ( ! current_user_can( 'edit_products' ) && ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'shopwalk-for-woocommerce' ) ) );
		}

		if ( ! Shopwalk_Seo_Feature::is_tier_allowed() ) {
			wp_send_json_error( array( 'message' => __( 'AI SEO requires a Pro license.', 'shopwalk-for-woocommerce' ) ) );
		}
	}
}

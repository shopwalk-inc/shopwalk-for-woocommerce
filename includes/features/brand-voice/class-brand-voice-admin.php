<?php
/**
 * Shopwalk_Brand_Voice_Admin — WP-Admin surface for brand-voice training.
 *
 * Renders the brand-voice submenu page under the Shopwalk top-level menu
 * and serves the AJAX endpoints the JS module on that page calls into.
 *
 * Capability requirement throughout: `manage_woocommerce` (matches the
 * top-level dashboard). Tier requirement: Pro or Pro+ — Free / Pro Lite
 * see an upsell page, not the corpus picker.
 *
 * @package ShopwalkWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shopwalk_Brand_Voice_Admin — submenu page + AJAX handlers.
 */
final class Shopwalk_Brand_Voice_Admin {

	private const CAP = 'manage_woocommerce';

	private const SUBMENU_SLUG = 'shopwalk-brand-voice';

	/**
	 * Register the submenu under the Shopwalk top-level menu. The parent
	 * slug ('shopwalk-for-woocommerce') matches what the dashboard class
	 * registers — we self-register without modifying the dashboard.
	 */
	public static function register_submenu(): void {
		add_submenu_page(
			'shopwalk-for-woocommerce',
			__( 'Brand Voice', 'shopwalk-for-woocommerce' ),
			__( 'Brand Voice', 'shopwalk-for-woocommerce' ),
			self::CAP,
			self::SUBMENU_SLUG,
			array( __CLASS__, 'render_panel' )
		);
	}

	/**
	 * Render the panel. Dispatches to the upsell view for non-eligible tiers
	 * and to the corpus-management view for Pro / Pro+.
	 */
	public static function render_panel(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'shopwalk-for-woocommerce' ) );
		}

		if ( ! Shopwalk_Brand_Voice_Feature::is_tier_allowed() ) {
			self::render_upsell();
			return;
		}

		$view = __DIR__ . '/views/panel.php';
		if ( file_exists( $view ) ) {
			$status  = Shopwalk_Brand_Voice::get_status();
			$profile = Shopwalk_Brand_Voice::get_profile_summary();
			$paste   = Shopwalk_Brand_Voice_Corpus_Manager::get_paste();
			$uploads = Shopwalk_Brand_Voice_Corpus_Manager::get_uploads();
			$word_ct = Shopwalk_Brand_Voice_Corpus_Manager::total_word_count();
			$doc_ct  = Shopwalk_Brand_Voice_Corpus_Manager::total_doc_count();
			$min_ok  = Shopwalk_Brand_Voice_Corpus_Manager::meets_minimum();
			include $view; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable
		}
	}

	/**
	 * Render the "Pro required" upsell. Mirrors the SEO feature's tone.
	 */
	private static function render_upsell(): void {
		?>
		<div class="wrap sw-brand-voice-wrap">
			<h1><?php esc_html_e( 'AI Brand Voice', 'shopwalk-for-woocommerce' ); ?></h1>
			<div class="sw-card sw-upsell">
				<h2><?php esc_html_e( 'Pro required', 'shopwalk-for-woocommerce' ); ?></h2>
				<p>
					<?php esc_html_e( 'Train Shopwalk on your writing voice, then have every AI-generated product description, SEO meta tag, blog post, and email read like you wrote it yourself. Available on the Pro and Pro+ plans.', 'shopwalk-for-woocommerce' ); ?>
				</p>
				<p>
					<a class="button button-primary" href="<?php echo esc_url( SHOPWALK_PARTNERS_URL ); ?>" target="_blank" rel="noopener">
						<?php esc_html_e( 'See Pro plans', 'shopwalk-for-woocommerce' ); ?>
					</a>
				</p>
			</div>
		</div>
		<?php
	}

	// ── AJAX handlers ───────────────────────────────────────────────────────

	/**
	 * AJAX — return the auto-discovered candidate list for the corpus picker.
	 */
	public static function ajax_list_corpus(): void {
		self::guard();
		$candidates = Shopwalk_Brand_Voice_Corpus_Manager::discover_candidates();
		wp_send_json_success(
			array(
				'candidates' => $candidates,
				'word_count' => Shopwalk_Brand_Voice_Corpus_Manager::total_word_count(),
				'doc_count'  => Shopwalk_Brand_Voice_Corpus_Manager::total_doc_count(),
				'min_met'    => Shopwalk_Brand_Voice_Corpus_Manager::meets_minimum(),
				'status'     => Shopwalk_Brand_Voice::get_status(),
			)
		);
	}

	/**
	 * AJAX — persist the approve/reject decisions from the picker.
	 */
	public static function ajax_save_selection(): void {
		self::guard();
		$raw       = isset( $_POST['selection'] ) ? wp_unslash( $_POST['selection'] ) : '[]'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$decoded   = json_decode( is_string( $raw ) ? $raw : '[]', true );
		$selection = is_array( $decoded ) ? $decoded : array();
		Shopwalk_Brand_Voice_Corpus_Manager::save_selection( $selection );
		wp_send_json_success(
			array(
				'word_count' => Shopwalk_Brand_Voice_Corpus_Manager::total_word_count(),
				'doc_count'  => Shopwalk_Brand_Voice_Corpus_Manager::total_doc_count(),
				'min_met'    => Shopwalk_Brand_Voice_Corpus_Manager::meets_minimum(),
			)
		);
	}

	/**
	 * AJAX — accept an uploaded .txt / .md file and add it to the corpus.
	 */
	public static function ajax_upload_file(): void {
		self::guard();

		if ( empty( $_FILES['file'] ) ) {
			wp_send_json_error( array( 'error' => __( 'No file uploaded.', 'shopwalk-for-woocommerce' ) ) );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- $_FILES is sanitized below.
		$file = $_FILES['file'];
		if ( ! is_array( $file ) || ! isset( $file['name'], $file['tmp_name'], $file['error'] ) ) {
			wp_send_json_error( array( 'error' => __( 'Invalid upload.', 'shopwalk-for-woocommerce' ) ) );
		}
		if ( UPLOAD_ERR_OK !== (int) $file['error'] ) {
			wp_send_json_error( array( 'error' => __( 'Upload error.', 'shopwalk-for-woocommerce' ) ) );
		}

		$name = sanitize_file_name( (string) $file['name'] );
		$ext  = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, array( 'txt', 'md' ), true ) ) {
			wp_send_json_error( array( 'error' => __( 'Only .txt and .md files are supported.', 'shopwalk-for-woocommerce' ) ) );
		}

		$tmp = (string) $file['tmp_name'];
		if ( ! is_uploaded_file( $tmp ) ) {
			wp_send_json_error( array( 'error' => __( 'Upload source unavailable.', 'shopwalk-for-woocommerce' ) ) );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- WP_Filesystem path is overkill for an admin-side text upload.
		$contents = (string) file_get_contents( $tmp );

		$res = Shopwalk_Brand_Voice_Corpus_Manager::add_upload( $name, $contents );
		if ( ! $res['ok'] ) {
			wp_send_json_error( array( 'error' => $res['error'] ?? __( 'Upload rejected.', 'shopwalk-for-woocommerce' ) ) );
		}
		wp_send_json_success(
			array(
				'source'     => $res['source'] ?? '',
				'word_count' => Shopwalk_Brand_Voice_Corpus_Manager::total_word_count(),
				'min_met'    => Shopwalk_Brand_Voice_Corpus_Manager::meets_minimum(),
			)
		);
	}

	/**
	 * AJAX — save the merchant's free-form pasted text.
	 */
	public static function ajax_save_paste(): void {
		self::guard();
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- preserved verbatim; only stored, never rendered as HTML.
		$text = isset( $_POST['paste'] ) ? wp_unslash( (string) $_POST['paste'] ) : '';
		Shopwalk_Brand_Voice_Corpus_Manager::save_paste( $text );
		wp_send_json_success(
			array(
				'word_count' => Shopwalk_Brand_Voice_Corpus_Manager::total_word_count(),
				'min_met'    => Shopwalk_Brand_Voice_Corpus_Manager::meets_minimum(),
			)
		);
	}

	/**
	 * AJAX — kick off a training run.
	 */
	public static function ajax_train(): void {
		self::guard();
		$result = Shopwalk_Brand_Voice_Training_Orchestrator::start();
		if ( ! $result['ok'] ) {
			wp_send_json_error( $result );
		}
		wp_send_json_success( $result );
	}

	/**
	 * AJAX — poll the cross-feature state for the dashboard UI. Cheap; the
	 * JS module hits this every 30s while a training run is active.
	 */
	public static function ajax_status(): void {
		self::guard();
		wp_send_json_success(
			array(
				'status'  => Shopwalk_Brand_Voice::get_status(),
				'profile' => Shopwalk_Brand_Voice::get_profile_summary(),
				'error'   => (string) get_option( 'shopwalk_brand_voice_last_error', '' ),
			)
		);
	}

	/**
	 * AJAX — reset everything: cancel pending AS jobs, delete server-side
	 * voice + samples, wipe local options.
	 */
	public static function ajax_reset(): void {
		self::guard();
		Shopwalk_Brand_Voice_Training_Orchestrator::cancel_pending();
		Shopwalk_Brand_Voice_API_Client::delete_profile(); // Best-effort; backend MAY 404.
		Shopwalk_Brand_Voice::_reset();
		Shopwalk_Brand_Voice_Corpus_Manager::reset();
		wp_send_json_success();
	}

	/**
	 * Common guard for every AJAX handler — capability + nonce.
	 */
	private static function guard(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'error' => 'forbidden' ), 403 );
		}
		if ( ! Shopwalk_Brand_Voice_Feature::is_tier_allowed() ) {
			wp_send_json_error( array( 'error' => 'tier_required' ), 403 );
		}
		check_ajax_referer( 'shopwalk_brand_voice', 'nonce' );
	}
}

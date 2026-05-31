<?php
/**
 * Semantic_Search_Admin — WP-Admin surface for the AI semantic search
 * feature: settings form (mode/scope/limit), synonym CSV upload, and
 * tier-gating banner.
 *
 * Renders into the existing Shopwalk dashboard via a panel callback. If the
 * central dashboard adds an action hook for feature panels we attach to
 * it; otherwise we register a standalone subpage under Shopwalk so the
 * panel is reachable in isolation.
 *
 * @package ShopwalkWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Semantic_Search_Admin — settings UI + tier gate + form handler.
 */
final class Semantic_Search_Admin {

	private const NONCE_ACTION = 'shopwalk_semsearch_save';
	private const NONCE_FIELD  = 'shopwalk_semsearch_nonce';
	private const PAGE_SLUG    = 'shopwalk-semantic-search';

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_init', array( $this, 'maybe_handle_post' ) );
		add_action( 'admin_menu', array( $this, 'register_subpage' ), 30 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// Attach to the central dashboard panel hook if it exists. Using a
		// filter rather than coupling to the dashboard class directly keeps
		// the feature directory self-contained.
		add_action( 'shopwalk_dashboard_feature_panels', array( __CLASS__, 'render_panel' ) );
	}

	/**
	 * Register a Shopwalk → Search subpage so the panel is always reachable
	 * even when the central dashboard doesn't fire the panel hook.
	 */
	public function register_subpage(): void {
		add_submenu_page(
			'shopwalk-for-woocommerce',
			__( 'AI Search', 'shopwalk-for-woocommerce' ),
			__( 'AI Search', 'shopwalk-for-woocommerce' ),
			'manage_woocommerce',
			self::PAGE_SLUG,
			array( $this, 'render_subpage' )
		);
	}

	public function enqueue_assets( string $hook ): void {
		if ( false === strpos( (string) $hook, self::PAGE_SLUG ) &&
			'toplevel_page_shopwalk-for-woocommerce' !== $hook
		) {
			return;
		}
		wp_enqueue_style(
			'shopwalk-semantic-search',
			WOOCOMMERCE_SHOPWALK_PLUGIN_URL . 'assets/css/semantic-search.css',
			array(),
			WOOCOMMERCE_SHOPWALK_VERSION
		);
	}

	/**
	 * Public so the central dashboard can call it directly via the
	 * descriptor's panel_callback, or via the
	 * `shopwalk_dashboard_feature_panels` action hook.
	 */
	public static function render_panel(): void {
		$ctx                          = self::context();
		$ctx['form_action']           = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		$ctx['nonce_field']           = self::NONCE_FIELD;
		$ctx['nonce_action']          = self::NONCE_ACTION;
		$ctx['embedded_in_dashboard'] = true;
		include __DIR__ . '/views/panel.php';
	}

	/**
	 * Standalone subpage — same panel content with a wrap header.
	 */
	public function render_subpage(): void {
		$ctx                          = self::context();
		$ctx['form_action']           = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		$ctx['nonce_field']           = self::NONCE_FIELD;
		$ctx['nonce_action']          = self::NONCE_ACTION;
		$ctx['embedded_in_dashboard'] = false;
		echo '<div class="wrap sw-wrap">';
		echo '<h1>' . esc_html__( 'AI Semantic Search', 'shopwalk-for-woocommerce' ) . '</h1>';
		include __DIR__ . '/views/panel.php';
		echo '</div>';
	}

	/**
	 * Persist the settings form on POST. Always runs through admin_init so
	 * the redirect-after-save flow happens before headers are sent.
	 */
	public function maybe_handle_post(): void {
		$method = isset( $_SERVER['REQUEST_METHOD'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) )
			: '';
		if ( 'POST' !== $method ) {
			return;
		}
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		if ( self::PAGE_SLUG !== $page ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to change Shopwalk settings.', 'shopwalk-for-woocommerce' ) );
		}
		$nonce = isset( $_POST[ self::NONCE_FIELD ] )
			? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) )
			: '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			wp_die( esc_html__( 'Security check failed.', 'shopwalk-for-woocommerce' ) );
		}

		// Mode.
		$mode_raw     = isset( $_POST['shopwalk_semsearch_mode'] )
			? sanitize_text_field( wp_unslash( $_POST['shopwalk_semsearch_mode'] ) )
			: Semantic_Search_Query_Handler::MODE_REPLACE;
		$allowed_mode = array(
			Semantic_Search_Query_Handler::MODE_OFF,
			Semantic_Search_Query_Handler::MODE_AUGMENT,
			Semantic_Search_Query_Handler::MODE_REPLACE,
		);
		if ( ! in_array( $mode_raw, $allowed_mode, true ) ) {
			$mode_raw = Semantic_Search_Query_Handler::MODE_REPLACE;
		}
		update_option( 'shopwalk_semsearch_mode', $mode_raw );

		// Scope.
		$scope_in  = isset( $_POST['shopwalk_semsearch_scope'] ) && is_array( $_POST['shopwalk_semsearch_scope'] )
			? array_map( 'sanitize_text_field', wp_unslash( $_POST['shopwalk_semsearch_scope'] ) )
			: array();
		$scope_out = array();
		foreach ( array( 'product', 'post', 'page' ) as $type ) {
			if ( in_array( $type, $scope_in, true ) ) {
				$scope_out[] = $type;
			}
		}
		if ( empty( $scope_out ) ) {
			$scope_out = array( 'product' );
		}
		update_option( 'shopwalk_semsearch_scope', $scope_out );

		// Limit.
		$limit_raw = isset( $_POST['shopwalk_semsearch_limit'] )
			? (int) $_POST['shopwalk_semsearch_limit']
			: 24;
		if ( $limit_raw < 1 || $limit_raw > 100 ) {
			$limit_raw = 24;
		}
		update_option( 'shopwalk_semsearch_limit', $limit_raw );

		// Synonym dictionary CSV upload (optional — empty file leaves the
		// existing dict intact).
		if ( isset( $_FILES['shopwalk_semsearch_synonyms_csv'] ) &&
			is_array( $_FILES['shopwalk_semsearch_synonyms_csv'] ) &&
			! empty( $_FILES['shopwalk_semsearch_synonyms_csv']['tmp_name'] )
		) {
			$tmp = sanitize_text_field( wp_unslash( $_FILES['shopwalk_semsearch_synonyms_csv']['tmp_name'] ) );
			if ( is_uploaded_file( $tmp ) ) {
				$parsed = self::parse_synonyms_csv( $tmp );
				update_option( 'shopwalk_semsearch_synonyms', $parsed );
			}
		}

		// Bust any cached search results so the new settings take effect on
		// the next shopper query.
		self::flush_cache();

		wp_safe_redirect( add_query_arg( 'sw_saved', '1', admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ) );
		exit;
	}

	/**
	 * Parse a synonym CSV file into a normalized array-of-rows. Each row is
	 * a list of equivalent terms, e.g. `running shoes,sneakers,trainers`.
	 *
	 * Public for tests.
	 *
	 * @return array<int,array<int,string>>
	 */
	public static function parse_synonyms_csv( string $path ): array {
		$rows = array();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- CSV parsing on uploaded file
		$fp = fopen( $path, 'r' );
		if ( ! is_resource( $fp ) ) {
			return $rows;
		}
		while ( ( $line = fgetcsv( $fp ) ) !== false ) {
			if ( ! is_array( $line ) ) {
				continue;
			}
			$terms = array();
			foreach ( $line as $cell ) {
				$cell = trim( (string) $cell );
				if ( '' !== $cell ) {
					$terms[] = $cell;
				}
			}
			if ( count( $terms ) >= 2 ) {
				$rows[] = $terms;
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- pairs with fopen above
		fclose( $fp );
		return $rows;
	}

	/**
	 * Best-effort cache flush — drops every per-(query, scope) transient we
	 * own. Implemented inline so settings changes always invalidate the
	 * cache without a separate code path.
	 */
	private static function flush_cache(): void {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_shopwalk_semsearch_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_shopwalk_semsearch_' ) . '%'
			)
		);
	}

	/**
	 * Build the template context. Pulled out of render so tests / future
	 * panels can reuse the same shape.
	 *
	 * @return array<string,mixed>
	 */
	private static function context(): array {
		$tier         = self::tier();
		$mode         = (string) get_option( 'shopwalk_semsearch_mode', Semantic_Search_Query_Handler::MODE_REPLACE );
		$scope        = (array) get_option( 'shopwalk_semsearch_scope', array( 'product' ) );
		$limit        = (int) get_option( 'shopwalk_semsearch_limit', 24 );
		$synonyms     = (array) get_option( 'shopwalk_semsearch_synonyms', array() );
		$catalog_ok   = self::catalog_synced();
		$pro_required = ! in_array( $tier, array( 'pro', 'pro_plus' ), true );

		return array(
			'tier'           => $tier,
			'pro_required'   => $pro_required,
			'mode'           => $mode,
			'scope'          => $scope,
			'limit'          => $limit,
			'synonyms'       => $synonyms,
			'synonyms_count' => count( $synonyms ),
			'catalog_ok'     => $catalog_ok,
			'saved'          => isset( $_GET['sw_saved'] ),
		);
	}

	/**
	 * Read the current tier from Shopwalk_License if available, else from
	 * the plain option. Returns 'unlicensed' | 'free' | 'pro' | 'pro_plus'.
	 */
	private static function tier(): string {
		if ( class_exists( 'Shopwalk_License' ) && method_exists( 'Shopwalk_License', 'plan' ) ) {
			$plan = (string) call_user_func( array( 'Shopwalk_License', 'plan' ) );
			if ( '' !== $plan ) {
				return $plan;
			}
		}
		$key = (string) get_option( 'shopwalk_license_key', '' );
		if ( '' === $key ) {
			return 'unlicensed';
		}
		return (string) get_option( 'shopwalk_plan', 'free' );
	}

	/**
	 * "Has the catalog completed at least one sync to the backend?" — used
	 * for the gentle warning in the panel when semantic search will return
	 * zero results because the embeddings index hasn't been built yet.
	 */
	private static function catalog_synced(): bool {
		$state = (array) get_option( 'shopwalk_sync_state', array() );
		return ! empty( $state['completed_at'] );
	}
}

<?php
/**
 * Shopwalk_Catalog_Sync_Admin — dashboard panel + AJAX actions.
 *
 * Renders the panel (or the "Pro upgrade required" stub when unlicensed),
 * handles the "Run full sync" + "Pause/Resume" buttons, enqueues the
 * panel's minimal CSS/JS, and self-registers as a Shopwalk sub-page so
 * the panel is reachable even before the foundation's feature-router
 * lands.
 *
 * @package ShopwalkWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shopwalk_Catalog_Sync_Admin — admin surface.
 */
final class Shopwalk_Catalog_Sync_Admin {

	/** WP submenu slug. */
	private const MENU_SLUG = 'shopwalk-catalog-sync';

	/** Capability required to view/operate the panel — matches the rest of the plugin. */
	private const CAPABILITY = 'manage_woocommerce';

	/** Nonce action name for the panel's POST handlers. */
	private const NONCE_ACTION = 'shopwalk_catalog_sync_panel';

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Get or create the singleton instance.
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
	 */
	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 60 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_shopwalk_catalog_sync_run', array( $this, 'handle_run_full_sync' ) );
		add_action( 'admin_post_shopwalk_catalog_sync_toggle_pause', array( $this, 'handle_toggle_pause' ) );
	}

	/**
	 * Register the submenu. The foundation owns the top-level menu; we
	 * attach as a child. If the parent slug doesn't exist yet, WP silently
	 * orphans the child (visible in the URL only) — which is fine for a
	 * pre-foundation PR.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		add_submenu_page(
			'shopwalk-for-woocommerce',
			__( 'AI Catalog Sync', 'shopwalk-for-woocommerce' ),
			__( 'AI Catalog Sync', 'shopwalk-for-woocommerce' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Render the submenu page wrapper. Delegates to render_panel() so the
	 * foundation can also call render_panel() directly when composing a
	 * multi-feature dashboard.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		echo '<div class="wrap shopwalk-catalog-sync-wrap">';
		echo '<h1>' . esc_html__( 'AI Catalog Sync', 'shopwalk-for-woocommerce' ) . '</h1>';
		self::render_panel();
		echo '</div>';
	}

	/**
	 * Render the panel body. Public + static so the foundation can call
	 * this without instantiating the singleton.
	 *
	 * @return void
	 */
	public static function render_panel(): void {
		$is_pro      = Shopwalk_Catalog_Sync_Feature::is_pro_active();
		$stats       = Shopwalk_Catalog_Sync_Scheduler::stats();
		$log         = (array) get_option( Shopwalk_Catalog_Sync_Scheduler::OPTION_LOG, array() );
		$nonce       = wp_create_nonce( self::NONCE_ACTION );
		$action_url  = admin_url( 'admin-post.php' );

		// View template just reads the variables in scope above.
		include __DIR__ . '/views/panel.php';
	}

	/**
	 * Enqueue panel assets only on this page.
	 *
	 * @param string $hook_suffix Current admin page.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ): void {
		if ( false === strpos( (string) $hook_suffix, self::MENU_SLUG ) ) {
			return;
		}
		$base = defined( 'WOOCOMMERCE_SHOPWALK_PLUGIN_URL' ) ? WOOCOMMERCE_SHOPWALK_PLUGIN_URL : '';
		$ver  = defined( 'WOOCOMMERCE_SHOPWALK_VERSION' ) ? WOOCOMMERCE_SHOPWALK_VERSION : '0.0.0';
		wp_enqueue_style(
			'shopwalk-catalog-sync',
			$base . 'assets/css/catalog-sync.css',
			array(),
			$ver
		);
		wp_enqueue_script(
			'shopwalk-catalog-sync',
			$base . 'assets/js/catalog-sync.js',
			array(),
			$ver,
			true
		);
	}

	// ─── POST handlers (admin-post.php) ─────────────────────────────────

	/**
	 * Handle the "Run full sync now" submission.
	 *
	 * @return void
	 */
	public function handle_run_full_sync(): void {
		$this->verify_post();
		Shopwalk_Catalog_Sync_Scheduler::start_full_sync();
		$this->redirect_back( 'full_sync_started' );
	}

	/**
	 * Handle the "Pause/Resume sync" toggle.
	 *
	 * @return void
	 */
	public function handle_toggle_pause(): void {
		$this->verify_post();
		$current = Shopwalk_Catalog_Sync_Feature::is_paused();
		update_option( 'shopwalk_catalog_sync_paused', $current ? '' : '1' );
		$this->redirect_back( $current ? 'resumed' : 'paused' );
	}

	/**
	 * Validate nonce + capability for POST handlers.
	 *
	 * @return void
	 */
	private function verify_post(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'shopwalk-for-woocommerce' ), '', array( 'response' => 403 ) );
		}
		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			wp_die( esc_html__( 'Invalid request.', 'shopwalk-for-woocommerce' ), '', array( 'response' => 400 ) );
		}
	}

	/**
	 * Redirect back to the panel with a notice marker.
	 *
	 * @param string $notice Notice key.
	 * @return void
	 */
	private function redirect_back( string $notice ): void {
		$url = add_query_arg(
			array(
				'page'                       => self::MENU_SLUG,
				'shopwalk_catalog_sync_msg'  => rawurlencode( $notice ),
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}
}

// Bootstrap when loaded directly (the foundation's instantiation path
// already runs `shopwalk_register_feature`, which spins up the feature
// class; the feature class's constructor doesn't reach into admin, so
// we instantiate the admin singleton on `admin_init` to be sure).
add_action(
	'admin_init',
	static function () {
		Shopwalk_Catalog_Sync_Admin::instance();
	}
);

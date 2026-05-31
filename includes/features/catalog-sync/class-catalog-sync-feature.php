<?php
/**
 * Shopwalk_Catalog_Sync_Feature — feature entry point.
 *
 * Pro-tier AI catalog sync feature. Pushes the merchant's WC catalog
 * (products + orders) to Shopwalk's backend so descriptions, search,
 * recommendations, SEO, and brand-voice features have data to work with.
 *
 * The feature self-registers via the foundation's `shopwalk_register_feature()`
 * global if it exists. If the foundation hasn't merged yet, this file defines
 * a defensive stub of that function so this PR works standalone — the
 * foundation's eventual implementation will silently take precedence (the
 * stub uses `function_exists()` guards).
 *
 * @package ShopwalkWooCommerce
 */

defined( 'ABSPATH' ) || exit;

// Defensive stub for the foundation's feature-registry global. The foundation
// PR is expected to declare this function ahead of feature loading; when it
// lands the `function_exists` guard prevents a redefine fatal. Until then
// the stub keeps a process-local registry so this feature still boots.
if ( ! function_exists( 'shopwalk_register_feature' ) ) {
	/**
	 * Register a feature class with the (eventual) foundation registry.
	 *
	 * Stub behaviour: stash the class name in a static array so the feature
	 * can self-instantiate. The foundation will replace this with a richer
	 * lifecycle (dependency checks, panel discovery, license-tier gating)
	 * but the contract is "pass a fully-qualified class name and we'll take
	 * care of the rest."
	 *
	 * @param string $class_name Fully-qualified feature class name.
	 * @return void
	 */
	function shopwalk_register_feature( string $class_name ): void {
		static $registry = array();
		if ( in_array( $class_name, $registry, true ) ) {
			return;
		}
		$registry[] = $class_name;

		// Best-effort instantiation: the foundation will own this, but in
		// the stub path we kick the feature so it can wire its hooks. The
		// feature class is expected to expose ::instance() (singleton) or
		// ::bootstrap() (idempotent setup).
		if ( method_exists( $class_name, 'instance' ) ) {
			call_user_func( array( $class_name, 'instance' ) );
		} elseif ( method_exists( $class_name, 'bootstrap' ) ) {
			call_user_func( array( $class_name, 'bootstrap' ) );
		}
	}
}

require_once __DIR__ . '/class-catalog-sync-api-client.php';
require_once __DIR__ . '/class-catalog-sync-batcher.php';
require_once __DIR__ . '/class-catalog-sync-scheduler.php';
if ( is_admin() ) {
	require_once __DIR__ . '/class-catalog-sync-admin.php';
}

/**
 * Shopwalk_Catalog_Sync_Feature — entry point for the catalog-sync feature.
 *
 * Responsible for:
 *   - Wiring WC catalog + order hooks into the Action Scheduler queue
 *   - Registering the dashboard panel (Pro upgrade prompt when unlicensed)
 *   - Owning the feature's metadata (slug, label, version, panel callable)
 */
final class Shopwalk_Catalog_Sync_Feature {

	/**
	 * Feature slug. Stable identifier the foundation panel uses for routing.
	 */
	public const SLUG = 'catalog-sync';

	/**
	 * Feature version. Bumped independently of the plugin version when the
	 * feature changes the wire payload shape.
	 */
	public const VERSION = '1.0.0';

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
	 * Constructor — wire hooks. Idempotent: hooks are added with the
	 * feature class as the owner so the foundation's hot-reload path
	 * (if any) can re-instantiate without leaking duplicate handlers.
	 */
	private function __construct() {
		// Schedule recurring jobs (delta sync, reconciliation) once WP is
		// fully up so as_schedule_recurring_action is available.
		add_action( 'init', array( $this, 'maybe_schedule_recurring_jobs' ), 20 );

		// WC catalog → AS queue.
		add_action( 'save_post_product', array( $this, 'on_product_save' ), 20, 1 );
		add_action( 'save_post_product_variation', array( $this, 'on_variation_save' ), 20, 1 );
		add_action( 'wp_trash_post', array( $this, 'on_product_trash' ), 10, 1 );
		add_action( 'before_delete_post', array( $this, 'on_product_delete' ), 10, 1 );
		add_action( 'woocommerce_product_set_stock_status', array( $this, 'on_stock_change' ), 10, 1 );
		add_action( 'woocommerce_product_set_stock', array( $this, 'on_stock_change' ), 10, 1 );

		// WC orders → AS queue.
		add_action( 'woocommerce_new_order', array( $this, 'on_order_event' ), 10, 1 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_order_status_change' ), 10, 1 );
		add_action( 'woocommerce_order_refunded', array( $this, 'on_order_refund' ), 10, 2 );

		// AS action handlers (the actual workers).
		add_action( Shopwalk_Catalog_Sync_Scheduler::ACTION_SYNC_PRODUCT, array( $this, 'handle_sync_product' ), 10, 1 );
		add_action( Shopwalk_Catalog_Sync_Scheduler::ACTION_SYNC_ORDER, array( $this, 'handle_sync_order' ), 10, 2 );
		add_action( Shopwalk_Catalog_Sync_Scheduler::ACTION_DELTA_SYNC, array( $this, 'handle_delta_sync' ) );
		add_action( Shopwalk_Catalog_Sync_Scheduler::ACTION_FULL_SYNC_PAGE, array( $this, 'handle_full_sync_page' ), 10, 1 );

		// Pro activation trigger — kick the first full sync when the
		// license flips to active. The foundation fires this; the
		// `shopwalk_oauth_connected` action is the documented signal.
		add_action( 'shopwalk_oauth_connected', array( $this, 'on_pro_activated' ) );
		add_action( 'shopwalk_catalog_sync_pro_activated', array( $this, 'on_pro_activated' ) );
	}

	/**
	 * Foundation/registry-friendly metadata describing the dashboard panel
	 * this feature contributes. The foundation reads this via reflection to
	 * compose the Shopwalk → Features admin page; until the foundation lands
	 * the admin class self-registers in its own bootstrap.
	 *
	 * @return array{slug:string,label:string,version:string,panel_callable:callable,requires_pro:bool}
	 */
	public static function describe(): array {
		return array(
			'slug'           => self::SLUG,
			'label'          => __( 'AI Catalog Sync', 'shopwalk-for-woocommerce' ),
			'version'        => self::VERSION,
			'panel_callable' => array( 'Shopwalk_Catalog_Sync_Admin', 'render_panel' ),
			'requires_pro'   => true,
		);
	}

	// ─── Pro-tier gating ────────────────────────────────────────────────

	/**
	 * Whether the merchant has an active Pro (or any active) license.
	 *
	 * Prefers the foundation's helper if available; falls back to a direct
	 * option read so this PR can land before the foundation. The semantics
	 * are: "do we have any signal at all that this merchant is connected?"
	 * — fine-grained tier gating (Pro vs Pro+ etc.) is left to the
	 * foundation's richer helper once it lands.
	 *
	 * @return bool
	 */
	public static function is_pro_active(): bool {
		if ( function_exists( 'shopwalk_license_is_pro_active' ) ) {
			return (bool) shopwalk_license_is_pro_active();
		}
		if ( class_exists( 'Shopwalk_License' ) && method_exists( 'Shopwalk_License', 'is_connected' ) ) {
			return (bool) Shopwalk_License::is_connected();
		}
		$status = (string) get_option( 'shopwalk_license_status', '' );
		return 'active' === $status;
	}

	// ─── Recurring job scheduling ───────────────────────────────────────

	/**
	 * Register the recurring delta-sync job. Idempotent — Action Scheduler
	 * dedupes recurring registrations by hook + group, so calling this on
	 * every `init` is safe.
	 *
	 * @return void
	 */
	public function maybe_schedule_recurring_jobs(): void {
		if ( ! function_exists( 'as_next_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}
		if ( ! self::is_pro_active() ) {
			return;
		}
		Shopwalk_Catalog_Sync_Scheduler::ensure_recurring();
	}

	// ─── WC hook → AS enqueue (debounced) ───────────────────────────────

	/**
	 * Enqueue a product sync (debounced via the unique-action pattern).
	 *
	 * @param int $product_id Product post ID.
	 * @return void
	 */
	public function on_product_save( $product_id ): void {
		if ( ! self::should_enqueue() ) {
			return;
		}
		$product_id = (int) $product_id;
		if ( $product_id <= 0 ) {
			return;
		}
		// Skip autosaves / revisions — WC fires save_post for both.
		if ( wp_is_post_autosave( $product_id ) || wp_is_post_revision( $product_id ) ) {
			return;
		}
		Shopwalk_Catalog_Sync_Scheduler::enqueue_product( $product_id );
	}

	/**
	 * Variations roll up to their parent product — enqueue the parent.
	 *
	 * @param int $variation_id Variation post ID.
	 * @return void
	 */
	public function on_variation_save( $variation_id ): void {
		if ( ! self::should_enqueue() ) {
			return;
		}
		$variation_id = (int) $variation_id;
		$parent_id    = (int) wp_get_post_parent_id( $variation_id );
		if ( $parent_id > 0 ) {
			Shopwalk_Catalog_Sync_Scheduler::enqueue_product( $parent_id );
		}
	}

	/**
	 * Enqueue a trash-state sync for a product.
	 *
	 * @param int $post_id Post ID being trashed.
	 * @return void
	 */
	public function on_product_trash( $post_id ): void {
		if ( ! self::should_enqueue() ) {
			return;
		}
		if ( 'product' !== get_post_type( $post_id ) ) {
			return;
		}
		Shopwalk_Catalog_Sync_Scheduler::enqueue_product( (int) $post_id );
	}

	/**
	 * Enqueue a deletion-record sync for a product.
	 *
	 * @param int $post_id Post ID being deleted.
	 * @return void
	 */
	public function on_product_delete( $post_id ): void {
		if ( ! self::should_enqueue() ) {
			return;
		}
		if ( 'product' !== get_post_type( $post_id ) ) {
			return;
		}
		Shopwalk_Catalog_Sync_Scheduler::enqueue_product_delete( (int) $post_id );
	}

	/**
	 * Stock-status / stock-quantity change → debounced product re-sync.
	 *
	 * @param int|\WC_Product $product_or_id Product or product ID (WC fires both shapes).
	 * @return void
	 */
	public function on_stock_change( $product_or_id ): void {
		if ( ! self::should_enqueue() ) {
			return;
		}
		$product_id = is_object( $product_or_id ) && method_exists( $product_or_id, 'get_id' )
			? (int) $product_or_id->get_id()
			: (int) $product_or_id;
		if ( $product_id > 0 ) {
			Shopwalk_Catalog_Sync_Scheduler::enqueue_product( $product_id );
		}
	}

	/**
	 * New order → enqueue order sync (event_type=created).
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	public function on_order_event( $order_id ): void {
		if ( ! self::should_enqueue() ) {
			return;
		}
		Shopwalk_Catalog_Sync_Scheduler::enqueue_order( (int) $order_id, 'created' );
	}

	/**
	 * Order status change → enqueue order sync (event_type=status_changed).
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	public function on_order_status_change( $order_id ): void {
		if ( ! self::should_enqueue() ) {
			return;
		}
		Shopwalk_Catalog_Sync_Scheduler::enqueue_order( (int) $order_id, 'status_changed' );
	}

	/**
	 * Order refund → enqueue order sync (event_type=refunded).
	 *
	 * @param int $order_id     Order ID.
	 * @param int $refund_id    Refund post ID (unused — full order state is re-pushed).
	 * @return void
	 */
	public function on_order_refund( $order_id, $refund_id = 0 ): void {
		if ( ! self::should_enqueue() ) {
			return;
		}
		unset( $refund_id );
		Shopwalk_Catalog_Sync_Scheduler::enqueue_order( (int) $order_id, 'refunded' );
	}

	// ─── AS action handlers ─────────────────────────────────────────────

	/**
	 * Push a single product to the API.
	 *
	 * @param int $product_id Product ID.
	 * @return void
	 */
	public function handle_sync_product( $product_id ): void {
		$batcher = new Shopwalk_Catalog_Sync_Batcher();
		$batch   = $batcher->collect_products( array( (int) $product_id ) );
		if ( empty( $batch['items'] ) ) {
			return;
		}
		$client = new Shopwalk_Catalog_Sync_API_Client();
		$result = $client->send_products_batch( $batch );
		Shopwalk_Catalog_Sync_Scheduler::log_result( 'sync_product', $result, count( $batch['items'] ) );
		self::handle_api_failure( $result );
	}

	/**
	 * Push a single order to the API.
	 *
	 * @param int    $order_id   Order ID.
	 * @param string $event_type Event type — created|status_changed|refunded|completed.
	 * @return void
	 */
	public function handle_sync_order( $order_id, $event_type = 'created' ): void {
		$batcher = new Shopwalk_Catalog_Sync_Batcher();
		$batch   = $batcher->collect_orders( array( (int) $order_id ), (string) $event_type );
		if ( empty( $batch['items'] ) ) {
			return;
		}
		$client = new Shopwalk_Catalog_Sync_API_Client();
		$result = $client->send_orders_batch( $batch );
		Shopwalk_Catalog_Sync_Scheduler::log_result( 'sync_order', $result, count( $batch['items'] ) );
		self::handle_api_failure( $result );
	}

	/**
	 * Recurring delta-sync handler. Scans for products + orders modified
	 * since the last successful run and enqueues per-item sync jobs.
	 *
	 * @return void
	 */
	public function handle_delta_sync(): void {
		if ( ! self::is_pro_active() || self::is_paused() ) {
			return;
		}
		$batcher = new Shopwalk_Catalog_Sync_Batcher();
		$since   = (int) get_option( Shopwalk_Catalog_Sync_Scheduler::OPTION_LAST_DELTA_AT, 0 );
		if ( 0 === $since ) {
			$since = time() - 6 * HOUR_IN_SECONDS;
		}

		$product_ids = $batcher->find_products_modified_since( $since );
		foreach ( $product_ids as $pid ) {
			Shopwalk_Catalog_Sync_Scheduler::enqueue_product( (int) $pid );
		}

		$order_ids = $batcher->find_orders_modified_since( $since );
		foreach ( $order_ids as $oid ) {
			Shopwalk_Catalog_Sync_Scheduler::enqueue_order( (int) $oid, 'delta' );
		}

		update_option( Shopwalk_Catalog_Sync_Scheduler::OPTION_LAST_DELTA_AT, time() );
		Shopwalk_Catalog_Sync_Scheduler::log_result(
			'delta_sync',
			array( 'ok' => true ),
			count( $product_ids ) + count( $order_ids )
		);
	}

	/**
	 * Full-sync paginator. Pages through every published product in chunks
	 * of FULL_SYNC_PAGE_SIZE; schedules the next page if more remain.
	 *
	 * @param int $page 1-based page number.
	 * @return void
	 */
	public function handle_full_sync_page( $page = 1 ): void {
		if ( ! self::is_pro_active() || self::is_paused() ) {
			return;
		}
		$page    = max( 1, (int) $page );
		$batcher = new Shopwalk_Catalog_Sync_Batcher();
		$ids     = $batcher->find_all_product_ids( $page, Shopwalk_Catalog_Sync_Scheduler::FULL_SYNC_PAGE_SIZE );

		if ( empty( $ids ) ) {
			update_option( Shopwalk_Catalog_Sync_Scheduler::OPTION_FULL_SYNC_STATE, 'complete' );
			update_option( Shopwalk_Catalog_Sync_Scheduler::OPTION_FULL_SYNC_FINISHED_AT, time() );
			Shopwalk_Catalog_Sync_Scheduler::log_result( 'full_sync_complete', array( 'ok' => true ), 0 );
			return;
		}

		foreach ( $ids as $pid ) {
			Shopwalk_Catalog_Sync_Scheduler::enqueue_product( (int) $pid );
		}
		update_option( Shopwalk_Catalog_Sync_Scheduler::OPTION_FULL_SYNC_PAGE, $page );
		Shopwalk_Catalog_Sync_Scheduler::schedule_full_sync_page( $page + 1 );
	}

	/**
	 * Pro activation trigger — kick a full sync.
	 *
	 * @return void
	 */
	public function on_pro_activated(): void {
		Shopwalk_Catalog_Sync_Scheduler::ensure_recurring();
		Shopwalk_Catalog_Sync_Scheduler::start_full_sync();
	}

	// ─── Helpers ────────────────────────────────────────────────────────

	/**
	 * Whether the feature is currently allowed to enqueue work — gated on
	 * Pro active AND not paused. Cheap check; called from every WC hook.
	 *
	 * @return bool
	 */
	private static function should_enqueue(): bool {
		if ( self::is_paused() ) {
			return false;
		}
		if ( ! self::is_pro_active() ) {
			return false;
		}
		return true;
	}

	/**
	 * Whether the merchant has paused sync via the admin toggle.
	 *
	 * @return bool
	 */
	public static function is_paused(): bool {
		return '1' === (string) get_option( 'shopwalk_catalog_sync_paused', '' );
	}

	/**
	 * Inspect an API client result and react to non-recoverable failures.
	 *
	 * - 401/403: flip the license to "needs re-validation" and stop syncing.
	 * - 5xx: do nothing — Action Scheduler's retry/backoff handles it.
	 * - 4xx (other): logged by the caller; the item is dropped (it's wrong,
	 *   not the network's fault).
	 *
	 * @param array{ok:bool,status?:int,error?:string} $result Client result envelope.
	 * @return void
	 */
	private static function handle_api_failure( array $result ): void {
		if ( ! empty( $result['ok'] ) ) {
			return;
		}
		$status = (int) ( $result['status'] ?? 0 );
		if ( 401 === $status || 403 === $status ) {
			update_option( 'shopwalk_license_status', 'needs_revalidation' );
			update_option( 'shopwalk_catalog_sync_paused', '1' );
			Shopwalk_Catalog_Sync_Scheduler::cancel_all();
		}
		if ( $status >= 500 ) {
			// Throw so Action Scheduler captures the failure + retries.
			throw new RuntimeException(
				sprintf( 'shopwalk-api returned %d: %s', $status, (string) ( $result['error'] ?? 'unknown' ) )
			);
		}
	}
}

// Self-register with the foundation (or the stub registry above). The
// `shopwalk_register_feature` global handles the instance lifecycle.
add_action(
	'plugins_loaded',
	static function () {
		shopwalk_register_feature( 'Shopwalk_Catalog_Sync_Feature' );
	},
	20
);

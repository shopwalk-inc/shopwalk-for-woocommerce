<?php
/**
 * Shopwalk_Product_Descriptions_Feature — AI product descriptions feature entry.
 *
 * v1.0 launch feature. Generates / rewrites a WC product's long description
 * (`post_content`) and short description (`post_excerpt`) in the merchant's
 * brand voice, one product at a time or in bulk.
 *
 * Self-registers via the `shopwalk_register_feature()` cross-feature hook
 * (defined defensively below) so the central dashboard surfaces a panel
 * without this feature needing to edit any other class.
 *
 * Pro-only. Free / unlicensed installs see a "Pro required" stub in place
 * of the generator UI.
 *
 * @package ShopwalkWooCommerce
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-product-descriptions-api-client.php';
require_once __DIR__ . '/class-product-descriptions-generator.php';
require_once __DIR__ . '/class-product-descriptions-bulk.php';

if ( is_admin() ) {
	require_once __DIR__ . '/class-product-descriptions-meta-box.php';
	require_once __DIR__ . '/class-product-descriptions-admin.php';
}

/**
 * Defensive stub for the cross-feature registration hook. The central
 * dashboard class is expected to define the real `shopwalk_register_feature`
 * in a future commit (and discover registered features via the corresponding
 * filter). Until then this stub keeps the feature self-contained — calling
 * it is a no-op outside of the dashboard's later wiring.
 */
if ( ! function_exists( 'shopwalk_register_feature' ) ) {
	/**
	 * Register a Shopwalk feature with the central dashboard.
	 *
	 * The expected contract (per the feature-modular split):
	 *
	 *   shopwalk_register_feature( [
	 *     'slug'         => 'product-descriptions',
	 *     'label'        => 'AI Product Descriptions',
	 *     'tier'         => 'pro',           // 'free' | 'pro' | 'pro_plus'
	 *     'panel_render' => callable,        // void(): echoes the dashboard panel
	 *     'menu_slug'    => 'shopwalk-product-descriptions',
	 *     'version'      => '4.2.0',
	 *   ] );
	 *
	 * The dashboard renders registered panels in order of registration and
	 * gates display by tier vs. the active license plan.
	 *
	 * @param array<string,mixed> $descriptor Feature descriptor.
	 * @return void
	 */
	function shopwalk_register_feature( array $descriptor ): void {
		// Persist into a global so the dashboard (when it ships) can read
		// the set of registered features via `apply_filters` or directly.
		global $shopwalk_registered_features;
		if ( ! is_array( $shopwalk_registered_features ) ) {
			$shopwalk_registered_features = array();
		}
		$slug = (string) ( $descriptor['slug'] ?? '' );
		if ( '' === $slug ) {
			return;
		}
		$shopwalk_registered_features[ $slug ] = $descriptor;
	}
}

/**
 * Shopwalk_Product_Descriptions_Feature — feature entry singleton.
 */
final class Shopwalk_Product_Descriptions_Feature {

	public const SLUG    = 'product-descriptions';
	public const VERSION = '4.2.0';

	/**
	 * Action Scheduler group name for bulk generation jobs.
	 */
	public const AS_GROUP = 'shopwalk-bulk-generation';

	/**
	 * Action Scheduler hook for a single per-product bulk generation.
	 */
	public const AS_HOOK_BULK = 'shopwalk_bulk_generate_descriptions';

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
	 * Wire WP/WC hooks for the feature.
	 */
	private function __construct() {
		// Self-register with the central dashboard. The stub above persists
		// the descriptor into a global so the dashboard (when its
		// cross-feature wiring lands) can render it.
		shopwalk_register_feature(
			array(
				'slug'         => self::SLUG,
				'label'        => __( 'AI Product Descriptions', 'shopwalk-for-woocommerce' ),
				'tier'         => 'pro',
				'panel_render' => array( __CLASS__, 'render_dashboard_panel' ),
				'menu_slug'    => 'shopwalk-product-descriptions',
				'version'      => self::VERSION,
			)
		);

		// Action Scheduler handler for bulk per-product generation.
		add_action( self::AS_HOOK_BULK, array( $this, 'handle_bulk_action' ), 10, 3 );

		if ( is_admin() ) {
			// Per-product meta box on the product edit screen.
			Shopwalk_Product_Descriptions_Meta_Box::instance();

			// Dashboard admin: settings + bulk runner UI.
			Shopwalk_Product_Descriptions_Admin::instance();

			// WC product list bulk-action — wired by the meta-box class
			// so it can share asset enqueueing with the per-product UI.
			add_filter( 'bulk_actions-edit-product', array( $this, 'register_bulk_action' ) );
			add_filter( 'handle_bulk_actions-edit-product', array( $this, 'handle_list_bulk_action' ), 10, 3 );
		}
	}

	/**
	 * Render the dashboard panel for this feature. Called by the central
	 * dashboard via the `panel_render` callback in the registered descriptor.
	 *
	 * @return void
	 */
	public static function render_dashboard_panel(): void {
		if ( ! self::is_pro() ) {
			self::render_pro_required_panel();
			return;
		}
		include __DIR__ . '/views/panel.php';
	}

	/**
	 * Render a "Pro required" stub when the active license is free /
	 * unlicensed.
	 *
	 * @return void
	 */
	private static function render_pro_required_panel(): void {
		?>
		<div class="ucp-card shopwalk-pd-card shopwalk-pd-pro-required">
			<h2><?php esc_html_e( 'AI Product Descriptions', 'shopwalk-for-woocommerce' ); ?>
				<span class="status-pill warn"><?php esc_html_e( 'Pro required', 'shopwalk-for-woocommerce' ); ?></span>
			</h2>
			<p><?php esc_html_e( 'Generate or rewrite product descriptions in your brand voice — one product at a time or in bulk. Available on Pro Lite, Pro, and Pro+ plans.', 'shopwalk-for-woocommerce' ); ?></p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( SHOPWALK_PARTNERS_URL . '/upgrade' ); ?>" target="_blank" rel="noopener">
					<?php esc_html_e( 'Upgrade to Pro', 'shopwalk-for-woocommerce' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Whether the current site has a Pro-tier license.
	 *
	 * Uses the foundation's Shopwalk_License helper when available, else
	 * falls back to a defensive `get_option()` read.
	 *
	 * @return bool
	 */
	public static function is_pro(): bool {
		if ( ! self::is_licensed() ) {
			return false;
		}
		$plan = '';
		if ( class_exists( 'Shopwalk_License' ) && method_exists( 'Shopwalk_License', 'plan' ) ) {
			$plan = (string) call_user_func( array( 'Shopwalk_License', 'plan' ) );
		}
		if ( '' === $plan ) {
			$plan = (string) get_option( 'shopwalk_plan', '' );
		}
		// Every paid tier qualifies: pro_lite / pro / pro_plus. Legacy
		// `partner_*` slugs from the prior pricing model also count.
		return (bool) preg_match( '/^(pro|partner)/', $plan );
	}

	/**
	 * Whether multi-language generation is available (Pro+ only).
	 *
	 * @return bool
	 */
	public static function is_pro_plus(): bool {
		if ( ! self::is_pro() ) {
			return false;
		}
		$plan = (string) get_option( 'shopwalk_plan', '' );
		return 'pro_plus' === $plan;
	}

	/**
	 * Whether any active license is present (any paid tier or the legacy
	 * free key format).
	 *
	 * @return bool
	 */
	public static function is_licensed(): bool {
		if ( class_exists( 'Shopwalk_License' ) && method_exists( 'Shopwalk_License', 'status' ) ) {
			return 'active' === (string) call_user_func( array( 'Shopwalk_License', 'status' ) );
		}
		return 'active' === (string) get_option( 'shopwalk_license_status', '' );
	}

	/**
	 * Bulk action label for the WC product list dropdown.
	 *
	 * @param array<string,string> $actions Existing bulk actions.
	 * @return array<string,string>
	 */
	public function register_bulk_action( array $actions ): array {
		if ( ! self::is_pro() ) {
			return $actions;
		}
		$actions[ self::SLUG . '_generate' ] = __( 'Generate Shopwalk descriptions', 'shopwalk-for-woocommerce' );
		return $actions;
	}

	/**
	 * Handle the WC product list bulk-action submission. Enqueues one
	 * Action Scheduler task per selected product, then redirects to the
	 * dashboard panel where the merchant watches progress.
	 *
	 * @param string     $redirect_to The redirect URL.
	 * @param string     $action      The bulk action slug.
	 * @param array<int> $post_ids    Selected post IDs.
	 * @return string
	 */
	public function handle_list_bulk_action( string $redirect_to, string $action, array $post_ids ): string {
		if ( self::SLUG . '_generate' !== $action ) {
			return $redirect_to;
		}
		if ( ! self::is_pro() || ! current_user_can( 'manage_woocommerce' ) ) {
			return $redirect_to;
		}

		$bulk    = new Shopwalk_Product_Descriptions_Bulk();
		$options = array(
			'fields' => array( 'long', 'short' ),
			'tone'   => 'brand_voice',
			'length' => 'medium',
			'mode'   => 'review_queue',
		);
		$job_id  = $bulk->enqueue_products( array_map( 'intval', $post_ids ), $options );

		return add_query_arg(
			array(
				'page'                            => 'shopwalk-product-descriptions',
				'shopwalk_bulk_job'               => $job_id,
				'shopwalk_bulk_items_enqueued'    => count( $post_ids ),
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Action Scheduler handler for `shopwalk_bulk_generate_descriptions`.
	 * Called once per enqueued product (one AS task per product, per spec).
	 *
	 * @param int                  $product_id Product ID to generate for.
	 * @param array<string,mixed>  $options    Per-job generation options.
	 * @param string               $job_id     Logical bulk-job identifier.
	 * @return void
	 */
	public function handle_bulk_action( $product_id, $options = array(), $job_id = '' ): void {
		$product_id = (int) $product_id;
		$options    = is_array( $options ) ? $options : array();
		$job_id     = (string) $job_id;

		if ( $product_id <= 0 ) {
			return;
		}
		if ( ! self::is_pro() ) {
			return;
		}

		$bulk = new Shopwalk_Product_Descriptions_Bulk();
		$bulk->run_one( $product_id, $options, $job_id );
	}
}

Shopwalk_Product_Descriptions_Feature::instance();

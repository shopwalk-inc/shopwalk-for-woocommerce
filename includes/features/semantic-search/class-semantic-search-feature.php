<?php
/**
 * Semantic_Search_Feature — feature entry. Owns lifecycle (load + hook),
 * declares the dashboard panel, and self-registers with the central
 * feature registry when one is present.
 *
 * Self-contained: every file this feature needs lives under
 * includes/features/semantic-search/. The central plugin bootstrap is not
 * modified — this feature opt-in-loads itself via
 * `shopwalk_register_feature()` if the helper exists, and otherwise loads
 * defensively on `plugins_loaded` so it's still functional when running
 * against the legacy bootstrap.
 *
 * @package ShopwalkWooCommerce
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-semantic-search-api-client.php';
require_once __DIR__ . '/class-semantic-search-query-handler.php';

/**
 * Semantic_Search_Feature — load + register hooks for v1.0 semantic search.
 */
final class Semantic_Search_Feature {

	/**
	 * Feature identifier — matches the on-disk slug + dashboard panel key.
	 */
	public const ID = 'semantic-search';

	/**
	 * Required tier. Free shows "Pro required" in the dashboard panel.
	 */
	public const REQUIRED_TIER = 'pro';

	/**
	 * Singleton instance.
	 */
	private static ?self $instance = null;

	private ?Semantic_Search_Query_Handler $query_handler = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Bootstrap entry. Wires storefront hooks unconditionally so the
	 * feature can answer "off" with zero overhead, and lazy-loads the
	 * admin panel only when WP-Admin renders.
	 */
	public function boot(): void {
		$this->query_handler = new Semantic_Search_Query_Handler();
		$this->query_handler->register();

		if ( is_admin() ) {
			$this->boot_admin();
		}
	}

	/**
	 * Load the admin-side dashboard panel.
	 */
	private function boot_admin(): void {
		require_once __DIR__ . '/class-semantic-search-admin.php';
		Semantic_Search_Admin::instance();
	}

	/**
	 * Declarative descriptor used by the central dashboard registry when
	 * one is available (`shopwalk_register_feature()`).
	 *
	 * Kept on the feature class so adding/removing the feature is a
	 * single-directory operation.
	 *
	 * @return array<string,mixed>
	 */
	public static function descriptor(): array {
		return array(
			'id'             => self::ID,
			'label'          => __( 'AI Semantic Search', 'shopwalk-for-woocommerce' ),
			'required_tier'  => self::REQUIRED_TIER,
			'panel_callback' => array( 'Semantic_Search_Admin', 'render_panel' ),
		);
	}
}

// ─── Defensive registration stub ─────────────────────────────────────────────
//
// Parallel feature agents are landing alongside this one, and any of them may
// be the first to introduce `shopwalk_register_feature()` into the central
// bootstrap. We tolerate both worlds: if the helper exists, register through
// it (so the dashboard picks up our panel automatically); if it doesn't,
// boot directly on plugins_loaded so the feature is still functional in
// isolation.
//
// All boot logic stays on the Semantic_Search_Feature class so this file
// remains OO-only (no top-level function declarations) and we never collide
// with another feature on a global symbol.

add_action(
	'plugins_loaded',
	static function (): void {
		$boot = array( 'Semantic_Search_Feature', 'instance' );
		if ( function_exists( 'shopwalk_register_feature' ) ) {
			shopwalk_register_feature(
				Semantic_Search_Feature::descriptor(),
				static function () use ( $boot ): void {
					call_user_func( $boot )->boot();
				}
			);
			return;
		}
		call_user_func( $boot )->boot();
	},
	20
);

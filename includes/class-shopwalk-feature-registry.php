<?php
/**
 * Shopwalk_Feature_Registry — the v4.0 feature-registration contract.
 *
 * In v4 the plugin is a thin foundation that hosts a suite of AI features
 * (descriptions, search, recommendations, brand voice, SEO, etc.). Each
 * feature lives in its own includes/features/<name>/ directory and ships
 * as a self-contained class that:
 *
 *   1. Registers itself by calling `shopwalk_register_feature( My_Class::class )`
 *      in response to the `shopwalk_features_register` action.
 *   2. Implements an optional `panel()` static method that returns a panel
 *      descriptor (array with `slug`, `label`, `render_callback`, `tier`).
 *
 * The dashboard reads the registry to render one tab per registered feature.
 *
 * Foundation-only — no concrete feature classes ship in this PR. Feature
 * agents add their own files under includes/features/ and register through
 * this entry point. See partner/plugin/scaffold.md in shopwalk-infra/
 * shopwalk-woocommerce/.
 *
 * @package ShopwalkWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Feature registry singleton. Holds the list of registered feature class
 * names and resolves them to panel descriptors on demand.
 */
final class Shopwalk_Feature_Registry {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Fully-qualified class names of registered features, in registration
	 * order. Feature classes call register() via the global helper below.
	 *
	 * @var array<int,string>
	 */
	private array $features = array();

	/**
	 * Get or create the singleton.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Add a feature class to the registry. Called via the global
	 * `shopwalk_register_feature()` helper below — feature code should
	 * not interact with the singleton directly.
	 *
	 * @param string $class_name Fully-qualified class name of the feature.
	 * @return void
	 */
	public function register( string $class_name ): void {
		if ( '' === $class_name ) {
			return;
		}
		// Dedupe — calling register() twice with the same class is a no-op
		// rather than producing duplicate dashboard tabs. Useful when an
		// activation pass + a `plugins_loaded` pass both fire the registration
		// helper for the same class.
		if ( in_array( $class_name, $this->features, true ) ) {
			return;
		}
		$this->features[] = $class_name;
	}

	/**
	 * Returns the list of registered feature class names in registration
	 * order. The dashboard iterates this list to build feature tabs.
	 *
	 * @return array<int,string>
	 */
	public function all(): array {
		return $this->features;
	}

	/**
	 * Collect the panel descriptors from every registered feature.
	 *
	 * Each feature class may define a static `panel()` method that returns
	 * an associative array describing its dashboard tab:
	 *   - slug:            string  Unique tab identifier (e.g. "descriptions").
	 *   - label:           string  Human-readable tab label.
	 *   - render_callback: callable Invoked with no args to render the tab body.
	 *   - tier:            string  Optional. "free" | "pro" | "pro_plus". Defaults to "pro".
	 *
	 * Features without a `panel()` method are skipped (a feature may exist
	 * for non-dashboard reasons — e.g. background sync). The
	 * `shopwalk_dashboard_panels` filter lets third-party code mutate the
	 * final panel list (insert custom tabs, reorder, hide).
	 *
	 * @return array<int,array{slug:string,label:string,render_callback:callable,tier:string}>
	 */
	public function panels(): array {
		$panels = array();
		foreach ( $this->features as $class_name ) {
			if ( ! class_exists( $class_name ) ) {
				continue;
			}
			if ( ! method_exists( $class_name, 'panel' ) ) {
				continue;
			}
			$panel = call_user_func( array( $class_name, 'panel' ) );
			if ( ! is_array( $panel ) ) {
				continue;
			}
			if ( empty( $panel['slug'] ) || empty( $panel['label'] ) || empty( $panel['render_callback'] ) ) {
				continue;
			}
			if ( ! is_callable( $panel['render_callback'] ) ) {
				continue;
			}
			$panels[] = array(
				'slug'            => (string) $panel['slug'],
				'label'           => (string) $panel['label'],
				'render_callback' => $panel['render_callback'],
				'tier'            => isset( $panel['tier'] ) ? (string) $panel['tier'] : 'pro',
			);
		}

		/**
		 * Filter the dashboard panel list before rendering.
		 *
		 * Use this to inject panels for features that don't follow the
		 * registry pattern, reorder existing panels, or hide them per-site.
		 *
		 * @param array<int,array{slug:string,label:string,render_callback:callable,tier:string}> $panels
		 */
		$panels = apply_filters( 'shopwalk_dashboard_panels', $panels );

		return is_array( $panels ) ? $panels : array();
	}
}

/**
 * Register a feature class with the dashboard.
 *
 * Feature code should call this in response to the
 * `shopwalk_features_register` action, e.g.:
 *
 *     add_action( 'shopwalk_features_register', function () {
 *         shopwalk_register_feature( Shopwalk_Descriptions_Feature::class );
 *     } );
 *
 * The class may optionally define `public static function panel(): array`
 * to render a tab on the dashboard; see Shopwalk_Feature_Registry::panels().
 *
 * @param string $class_name Fully-qualified class name of the feature.
 * @return void
 */
function shopwalk_register_feature( string $class_name ): void {
	Shopwalk_Feature_Registry::instance()->register( $class_name );
}

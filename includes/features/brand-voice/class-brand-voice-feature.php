<?php
/**
 * Shopwalk_Brand_Voice_Feature — AI brand-voice training (v1.0 launch feature).
 *
 * Trains a per-merchant brand-voice profile on Shopwalk's backend from a
 * merchant-curated corpus of existing site content, uploaded text files, and
 * pasted samples. The trained voice is consumed by other generation features
 * (product descriptions, SEO meta) via the stable cross-feature interface in
 * `class-brand-voice-cross-feature.php`. Long-form authoring (blogs, email
 * copy, ad copy, marketing pages) lands in v1.1+ and also reads through that
 * same interface.
 *
 * Tier: Pro and Pro+ (Pro Lite excluded — see ALLOWED_TIERS below). Free
 * tier shows a "Pro required" upsell in the dashboard panel.
 *
 * Self-registers via `shopwalk_register_feature()` so the plugin bootstrap
 * does not need editing. A defensive function-exists stub provides a no-op
 * registrar so this file can ship before the central registry lands; once
 * the registry is in place the real function will be called instead.
 *
 * @package ShopwalkWooCommerce
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-brand-voice-cross-feature.php';
require_once __DIR__ . '/class-brand-voice-api-client.php';
require_once __DIR__ . '/class-brand-voice-corpus-manager.php';
require_once __DIR__ . '/class-brand-voice-training-orchestrator.php';

if ( is_admin() ) {
	require_once __DIR__ . '/class-brand-voice-admin.php';
}

/**
 * Shopwalk_Brand_Voice_Feature — feature entry point + dashboard panel declaration.
 */
final class Shopwalk_Brand_Voice_Feature {

	/**
	 * Feature slug used for option keys, capability checks, AS group names,
	 * and the dashboard panel id. Stable identifier — do not rename without
	 * a migration of every `shopwalk_brand_voice_*` option key.
	 */
	public const SLUG = 'brand_voice';

	/**
	 * Tiers that may train and use a brand voice. Free shows "Pro required".
	 * Pro Lite excluded — brand-voice training is a heavier compute path and
	 * is bundled with the full Pro generation suite per the pricing memo.
	 */
	public const ALLOWED_TIERS = array( 'pro', 'pro_plus' );

	/**
	 * Action Scheduler group used for ALL brand-voice jobs. Lets ops cancel
	 * an entire run from WP-Admin → Tools → Scheduled Actions in one click
	 * without grepping individual action hook names.
	 */
	public const AS_GROUP = 'shopwalk-brand-voice';

	/**
	 * Singleton.
	 *
	 * @var Shopwalk_Brand_Voice_Feature|null
	 */
	private static ?Shopwalk_Brand_Voice_Feature $instance = null;

	/**
	 * Returns the singleton instance.
	 */
	public static function instance(): Shopwalk_Brand_Voice_Feature {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Wires hooks. Idempotent (singleton-guarded + WP's own
	 * has_action de-duplication on identical callbacks).
	 */
	public function boot(): void {
		// Training pipeline — Action Scheduler hooks. Registered unconditionally
		// (not gated on is_admin) because Action Scheduler runs jobs in the WP
		// cron context, not the admin context.
		add_action(
			'shopwalk_brand_voice_upload_batch',
			array( Shopwalk_Brand_Voice_Training_Orchestrator::class, 'handle_upload_batch' ),
			10,
			1
		);
		add_action(
			'shopwalk_brand_voice_poll_status',
			array( Shopwalk_Brand_Voice_Training_Orchestrator::class, 'handle_poll_status' ),
			10,
			1
		);

		// Dashboard submenu page + admin assets — only relevant inside wp-admin.
		if ( is_admin() ) {
			add_action( 'admin_menu', array( Shopwalk_Brand_Voice_Admin::class, 'register_submenu' ), 20 );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

			// AJAX endpoints — corpus management + training kickoff + status poll.
			add_action( 'wp_ajax_shopwalk_brand_voice_list_corpus', array( Shopwalk_Brand_Voice_Admin::class, 'ajax_list_corpus' ) );
			add_action( 'wp_ajax_shopwalk_brand_voice_save_selection', array( Shopwalk_Brand_Voice_Admin::class, 'ajax_save_selection' ) );
			add_action( 'wp_ajax_shopwalk_brand_voice_upload_file', array( Shopwalk_Brand_Voice_Admin::class, 'ajax_upload_file' ) );
			add_action( 'wp_ajax_shopwalk_brand_voice_save_paste', array( Shopwalk_Brand_Voice_Admin::class, 'ajax_save_paste' ) );
			add_action( 'wp_ajax_shopwalk_brand_voice_train', array( Shopwalk_Brand_Voice_Admin::class, 'ajax_train' ) );
			add_action( 'wp_ajax_shopwalk_brand_voice_status', array( Shopwalk_Brand_Voice_Admin::class, 'ajax_status' ) );
			add_action( 'wp_ajax_shopwalk_brand_voice_reset', array( Shopwalk_Brand_Voice_Admin::class, 'ajax_reset' ) );
		}
	}

	/**
	 * Conditional asset enqueue — only on the brand-voice submenu page.
	 * Avoids pushing CSS/JS onto unrelated WP admin pages.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public function enqueue_assets( string $hook ): void {
		if ( false === strpos( (string) $hook, 'shopwalk-brand-voice' ) ) {
			return;
		}

		wp_enqueue_style(
			'shopwalk-brand-voice',
			WOOCOMMERCE_SHOPWALK_PLUGIN_URL . 'assets/css/brand-voice.css',
			array(),
			WOOCOMMERCE_SHOPWALK_VERSION
		);
		wp_enqueue_script(
			'shopwalk-brand-voice',
			WOOCOMMERCE_SHOPWALK_PLUGIN_URL . 'assets/js/brand-voice.js',
			array( 'jquery' ),
			WOOCOMMERCE_SHOPWALK_VERSION,
			true
		);
		wp_localize_script(
			'shopwalk-brand-voice',
			'ShopwalkBrandVoice',
			array(
				'ajax_url'       => admin_url( 'admin-ajax.php' ),
				'nonce'          => wp_create_nonce( 'shopwalk_brand_voice' ),
				'min_word_count' => Shopwalk_Brand_Voice_Corpus_Manager::MIN_WORD_COUNT,
				'poll_interval'  => 30, // seconds — matches AS recurring schedule
				'i18n'           => array(
					'loading'           => __( 'Loading…', 'shopwalk-for-woocommerce' ),
					'corpus_too_small'  => __( 'Add more content — minimum 5,000 words required to train a useful voice.', 'shopwalk-for-woocommerce' ),
					'corpus_dirty'      => __( 'Corpus changed since last training — retrain to update your brand voice.', 'shopwalk-for-woocommerce' ),
					'training_started'  => __( 'Training started. This usually takes 1–3 minutes.', 'shopwalk-for-woocommerce' ),
					'training_complete' => __( 'Brand voice trained. Preview below.', 'shopwalk-for-woocommerce' ),
					'training_failed'   => __( 'Training failed.', 'shopwalk-for-woocommerce' ),
					'confirm_reset'     => __( 'Reset your brand voice? Source samples and the trained voice will be deleted from Shopwalk. This cannot be undone.', 'shopwalk-for-woocommerce' ),
				),
			)
		);
	}

	/**
	 * Returns whether the current site is licensed at a tier that may train
	 * a brand voice. Defensive — tolerates the license class being absent
	 * (e.g. during the shopwalk-tier-2 bootstrap not having loaded yet).
	 */
	public static function is_tier_allowed(): bool {
		if ( ! class_exists( 'Shopwalk_License' ) ) {
			return false;
		}
		if ( ! Shopwalk_License::is_valid() ) {
			return false;
		}
		$plan = (string) get_option( 'shopwalk_plan', '' );
		if ( '' === $plan ) {
			// Mirror SEO + descriptions defaulting convention — empty plan
			// post-activation is treated as Pro until the hourly tier poll
			// writes a concrete value.
			$plan = 'pro';
		}
		return in_array( $plan, self::ALLOWED_TIERS, true );
	}

	/**
	 * Dashboard panel descriptor consumed by the central dashboard renderer.
	 * Returning an array keeps this file decoupled from the dashboard's
	 * concrete class.
	 *
	 * @return array{slug:string,label:string,callback:callable,order:int}
	 */
	public static function dashboard_panel(): array {
		return array(
			'slug'     => self::SLUG,
			'label'    => __( 'AI Brand Voice', 'shopwalk-for-woocommerce' ),
			'callback' => array( Shopwalk_Brand_Voice_Admin::class, 'render_panel' ),
			'order'    => 50,
		);
	}
}

// ─── Self-registration ──────────────────────────────────────────────────────
//
// Mirrors the SEO feature's contract: register a descriptor with the central
// `shopwalk_register_feature()` registrar. Defensive stub below lets this file
// load standalone before the central registry lands.

if ( ! function_exists( 'shopwalk_register_feature' ) ) {
	/**
	 * Defensive shim — collects feature descriptors until the real registrar
	 * (from the central bootstrap) replaces this function. Returns true so
	 * callers can treat the result as a success signal.
	 *
	 * @param array $feature Feature descriptor.
	 * @return bool
	 */
	function shopwalk_register_feature( array $feature ): bool { // phpcs:ignore
		if ( ! isset( $GLOBALS['shopwalk_pending_features'] ) || ! is_array( $GLOBALS['shopwalk_pending_features'] ) ) {
			$GLOBALS['shopwalk_pending_features'] = array();
		}
		$GLOBALS['shopwalk_pending_features'][ $feature['slug'] ?? wp_generate_uuid4() ] = $feature;
		return true;
	}
}

shopwalk_register_feature(
	array(
		'slug'      => Shopwalk_Brand_Voice_Feature::SLUG,
		'label'     => __( 'AI Brand Voice', 'shopwalk-for-woocommerce' ),
		'version'   => '4.6.0',
		'tiers'     => Shopwalk_Brand_Voice_Feature::ALLOWED_TIERS,
		// Brand voice has no hard prerequisites — corpus discovery walks WP
		// content directly without going through catalog_sync.
		'requires'  => array(),
		// Other features (descriptions, seo, blog-authoring) declare a soft
		// dependency on brand_voice by checking `Shopwalk_Brand_Voice::is_trained()`
		// at call time — they degrade gracefully when no voice is trained yet.
		'provides'  => array( 'brand_voice' ),
		'panel'     => array( Shopwalk_Brand_Voice_Feature::class, 'dashboard_panel' ),
		'boot'      => array( Shopwalk_Brand_Voice_Feature::instance(), 'boot' ),
		'singleton' => Shopwalk_Brand_Voice_Feature::class,
	)
);

// Boot directly as well — the central registry may or may not call boot()
// itself depending on when the parallel-session bootstrap lands. Boot is
// idempotent (singleton + add_action de-duplicates within a request).
add_action(
	'plugins_loaded',
	static function (): void {
		Shopwalk_Brand_Voice_Feature::instance()->boot();
	},
	20
);

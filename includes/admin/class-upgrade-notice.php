<?php
/**
 * Shopwalk_Upgrade_Notice — one-time post-update admin notice for the
 * v3.x → v4.0 direction shift.
 *
 * v3.x was a UCP-purchasable + ACP + direct-checkout plugin. v4.0 is an
 * AI-features-for-WooCommerce plugin with Google UCP setup as the Free
 * tier and the AI suite (descriptions, search, recommendations, brand
 * voice, SEO, KB) as Pro features. The shift is large enough that
 * existing installs deserve an explicit heads-up.
 *
 * The notice fires once after the first activation on v4.0, then
 * dismisses itself when the merchant clicks the dismiss link. State is
 * persisted in the `shopwalk_v4_upgrade_notice_dismissed` option so the
 * notice survives admin nav but never reappears after dismissal.
 *
 * @package ShopwalkWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shopwalk_Upgrade_Notice — admin notice controller.
 */
final class Shopwalk_Upgrade_Notice {

	private const OPTION_DISMISSED = 'shopwalk_v4_upgrade_notice_dismissed';
	private const NONCE_ACTION     = 'shopwalk_v4_dismiss_upgrade_notice';
	private const DISMISS_ACTION   = 'shopwalk_dismiss_v4_upgrade_notice';

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_notices', array( $this, 'maybe_render' ) );
		add_action( 'admin_post_' . self::DISMISS_ACTION, array( $this, 'handle_dismiss' ) );
	}

	/**
	 * Render the notice when (a) the user can see it, (b) it has not been
	 * dismissed, and (c) the plugin is on v4.x. Older installs that never
	 * had v3 will still see this on first activation — which is fine; it
	 * doubles as a "what this plugin does" intro on fresh installs.
	 *
	 * @return void
	 */
	public function maybe_render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		if ( (bool) get_option( self::OPTION_DISMISSED, false ) ) {
			return;
		}

		$dismiss_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=' . self::DISMISS_ACTION ),
			self::NONCE_ACTION
		);
		?>
		<div class="notice notice-info" style="border-left-color:#6366f1;">
			<h3 style="margin-bottom:4px;">
				<?php esc_html_e( 'Shopwalk for WooCommerce v4.0 — new direction', 'shopwalk-for-woocommerce' ); ?>
			</h3>
			<p>
				<?php esc_html_e( 'The plugin has been refocused as an AI features suite for WooCommerce — semantic search, recommendations, AI descriptions, brand-voice content, SEO + image optimization — all running on Shopwalk\'s own AI stack. The Free tier wires up Google\'s Universal Commerce Protocol (UCP) so AI shopping agents can discover your store.', 'shopwalk-for-woocommerce' ); ?>
			</p>
			<p>
				<strong><?php esc_html_e( 'What\'s removed in v4.0:', 'shopwalk-for-woocommerce' ); ?></strong>
				<?php
				/* translators: list of features removed in v4.0 */
				esc_html_e( 'ACP (Agentic Commerce Protocol) merchant opt-in, direct-checkout payment authorization, and the agent-native payment router. The plugin no longer participates in checkout — AI agents are handed off to your existing WooCommerce checkout via the standard payment URL.', 'shopwalk-for-woocommerce' );
				?>
			</p>
			<p>
				<strong><?php esc_html_e( 'What\'s new in v4.0:', 'shopwalk-for-woocommerce' ); ?></strong>
				<?php esc_html_e( 'A tabbed dashboard with one panel per AI feature, a feature-registration hook for AI feature modules (loaded in subsequent releases), and free Google UCP setup as the baseline value of the Free tier.', 'shopwalk-for-woocommerce' ); ?>
			</p>
			<p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=shopwalk-for-woocommerce' ) ); ?>" class="button button-primary">
					<?php esc_html_e( 'Open Shopwalk dashboard', 'shopwalk-for-woocommerce' ); ?>
				</a>
				<a href="<?php echo esc_url( $dismiss_url ); ?>" class="button button-secondary" style="margin-left:8px;">
					<?php esc_html_e( 'Dismiss', 'shopwalk-for-woocommerce' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * admin-post.php handler for the Dismiss link. Verifies the nonce,
	 * sets the option, then redirects back to wherever the merchant was.
	 *
	 * @return void
	 */
	public function handle_dismiss(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'shopwalk-for-woocommerce' ) );
		}
		check_admin_referer( self::NONCE_ACTION );
		update_option( self::OPTION_DISMISSED, true, false );

		$redirect = wp_get_referer();
		if ( ! $redirect ) {
			$redirect = admin_url( 'admin.php?page=shopwalk-for-woocommerce' );
		}
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Re-arm the notice for a future major-version bump. Not called from
	 * v4.0 — included so the v5.0+ upgrade pass has a clean entry point.
	 *
	 * @return void
	 */
	public static function rearm(): void {
		delete_option( self::OPTION_DISMISSED );
	}
}

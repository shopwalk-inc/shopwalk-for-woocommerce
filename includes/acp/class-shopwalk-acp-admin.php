<?php
/**
 * Shopwalk_ACP_Admin — "Shopwalk → AI Channels → ChatGPT (ACP)" admin page.
 *
 * Phase 1 Agent D of the ACP build plan
 * (`shopwalk-infra/AI/markdown/platform/ACP_BUILD_PLAN.md`). Implements the
 * merchant opt-in / pause / status UI described in
 * `shopwalk-infra/AI/markdown/platform/SHOPWALK_ACP_INTEGRATION.md` §5.
 *
 * The page lives under the main `shopwalk-for-woocommerce` top-level menu
 * via two submenu entries:
 *   - "AI Channels" — a label-only parent that links to this page (the
 *     ChatGPT ACP one) and reserves room for future channels (Claude,
 *     Gemini) without restructuring the menu.
 *   - "ChatGPT (ACP)" — this page itself.
 *
 * Loaded only when the optional Shopwalk integration is connected
 * (Tier 2). Without a license there is no partner record to opt in.
 *
 * @package ShopwalkWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shopwalk_ACP_Admin — admin page controller for the ChatGPT (ACP) channel.
 */
final class Shopwalk_ACP_Admin {

	private const MENU_SLUG    = 'shopwalk-acp';
	private const NONCE_ACTION = 'shopwalk_acp_admin';
	private const NONCE_FIELD  = '_shopwalk_acp_nonce';

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
		add_action( 'admin_menu', array( $this, 'register_menu' ), 20 );
		add_action( 'admin_post_shopwalk_acp_opt_in', array( $this, 'handle_opt_in' ) );
		add_action( 'admin_post_shopwalk_acp_pause', array( $this, 'handle_pause_toggle' ) );
	}

	/**
	 * Register the "AI Channels → ChatGPT (ACP)" submenu under the existing
	 * Shopwalk top-level menu.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		add_submenu_page(
			'shopwalk-for-woocommerce',
			__( 'ChatGPT (ACP)', 'shopwalk-for-woocommerce' ),
			__( 'ChatGPT (ACP)', 'shopwalk-for-woocommerce' ),
			'manage_woocommerce',
			self::MENU_SLUG,
			array( $this, 'render_page' )
		);
	}

	// ── Payment-processor compatibility check ────────────────────────────

	/**
	 * Detect whether a WC payment gateway suitable for in-chat ACP checkout
	 * is active + configured on this store. ACP currently requires Stripe
	 * shared payment tokens, so we check for the active WooPayments or
	 * Stripe gateways.
	 *
	 * Returns `'full'` (in-chat checkout available) or `'deep_link'` (no
	 * compatible gateway — buyers will be redirected to the merchant's
	 * own checkout page).
	 *
	 * @return string One of 'full' | 'deep_link'.
	 */
	public static function detect_payment_compat(): string {
		if ( ! function_exists( 'WC' ) ) {
			return 'deep_link';
		}
		$gateways = WC()->payment_gateways()->get_available_payment_gateways();
		if ( ! is_array( $gateways ) ) {
			return 'deep_link';
		}
		foreach ( $gateways as $gateway ) {
			$id      = isset( $gateway->id ) ? (string) $gateway->id : '';
			$enabled = isset( $gateway->enabled ) && 'yes' === $gateway->enabled;
			if ( ! $enabled ) {
				continue;
			}
			// WooPayments registers as `woocommerce_payments`; the Stripe
			// official plugin registers as `stripe`. Either is sufficient
			// for ACP shared-payment-token flows.
			if ( 'woocommerce_payments' === $id || 'stripe' === $id ) {
				return 'full';
			}
		}
		return 'deep_link';
	}

	/**
	 * Returns the human-readable label for a payment_compat tag.
	 */
	private function payment_compat_label( string $compat ): string {
		if ( 'full' === $compat ) {
			return __( 'Full in-chat checkout available.', 'shopwalk-for-woocommerce' );
		}
		return __( 'Deep-link only (buyer redirected to your checkout page).', 'shopwalk-for-woocommerce' );
	}

	// ── ToS loader ───────────────────────────────────────────────────────

	/**
	 * Load the ToS addendum HTML from the bundled static file. Falls back
	 * to a one-line stub if the file is missing (the checkbox still gates
	 * opt-in so a missing ToS file fails closed).
	 *
	 * @return string Sanitized HTML safe to render with wp_kses_post.
	 */
	private function tos_html(): string {
		$path = WOOCOMMERCE_SHOPWALK_PLUGIN_DIR . 'includes/acp/tos-' . Shopwalk_ACP_Client::TOS_VERSION . '.html';
		if ( ! is_readable( $path ) ) {
			return '<p>' . esc_html__( 'ACP terms not available. Contact support@shopwalk.com.', 'shopwalk-for-woocommerce' ) . '</p>';
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- bundled static ToS file shipped with the plugin.
		$raw = (string) file_get_contents( $path );
		return wp_kses_post( $raw );
	}

	// ── Page render ──────────────────────────────────────────────────────

	/**
	 * Top-level page renderer. Decides whether to show pre-opt-in or
	 * post-opt-in based on the latest /status payload.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'shopwalk-for-woocommerce' ) );
		}

		$status         = Shopwalk_ACP_Client::status();
		$payment_compat = self::detect_payment_compat();
		$state          = isset( $status['status'] ) ? (string) $status['status'] : 'not_offered';

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'ChatGPT (ACP)', 'shopwalk-for-woocommerce' ) . '</h1>';

		$this->render_action_notice();

		if ( ! $status['ok'] ) {
			$this->render_status_error( $status );
		}

		if ( 'opted_in' === $state || 'paused' === $state ) {
			$this->render_post_opt_in_view( $status, $payment_compat );
		} else {
			$this->render_pre_opt_in_view( $payment_compat );
		}

		echo '</div>';
	}

	/**
	 * Render a one-shot success/error notice after admin_post handlers
	 * redirect back with `?sw_acp=ok|error&sw_acp_msg=...`.
	 */
	private function render_action_notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only notice rendered from query args set by our own admin_post handler.
		if ( ! isset( $_GET['sw_acp'] ) ) {
			return;
		}
		$result  = sanitize_text_field( wp_unslash( $_GET['sw_acp'] ) );
		$message = isset( $_GET['sw_acp_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['sw_acp_msg'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$class = 'ok' === $result ? 'notice-success' : 'notice-error';
		printf(
			'<div class="notice %1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $class ),
			esc_html( '' !== $message ? $message : ( 'ok' === $result ? __( 'Done.', 'shopwalk-for-woocommerce' ) : __( 'Action failed.', 'shopwalk-for-woocommerce' ) ) )
		);
	}

	/**
	 * Render an error banner when /status failed to load. We still render
	 * the pre-opt-in view below so the merchant can retry.
	 */
	private function render_status_error( array $status ): void {
		$message = isset( $status['message'] ) ? (string) $status['message'] : __( 'Could not reach Shopwalk.', 'shopwalk-for-woocommerce' );
		printf(
			'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s</p></div>',
			esc_html__( 'ACP status unavailable:', 'shopwalk-for-woocommerce' ),
			esc_html( $message )
		);
	}

	// ── Pre-opt-in view ──────────────────────────────────────────────────

	/**
	 * Pre-opt-in screen. Explains ACP, shows payment-processor compat, the
	 * ToS addendum + checkbox, and the Enable button.
	 *
	 * @param string $payment_compat 'full' | 'deep_link'.
	 */
	private function render_pre_opt_in_view( string $payment_compat ): void {
		?>
		<div class="sw-card" style="background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:20px;margin:16px 0;max-width:820px;">
			<h2><?php esc_html_e( 'Enable ChatGPT shopping for your store', 'shopwalk-for-woocommerce' ); ?></h2>
			<p>
				<?php esc_html_e( 'When you enable the ChatGPT channel, Shopwalk publishes your WooCommerce products into the OpenAI Agentic Commerce Protocol (ACP) feed under our partnership with OpenAI. ChatGPT can then surface your products inside the chat experience and — when a compatible payment processor is configured — complete the checkout in-chat. Orders flow back into your existing WooCommerce order pipeline, so reports, fulfillment, refunds, and reviews work exactly as they do today.', 'shopwalk-for-woocommerce' ); ?>
			</p>

			<h3><?php esc_html_e( 'Payment-processor compatibility', 'shopwalk-for-woocommerce' ); ?></h3>
			<p>
				<?php if ( 'full' === $payment_compat ) : ?>
					<span style="color:#137333;font-weight:600;">&#x2705;</span>
					<?php echo esc_html( $this->payment_compat_label( $payment_compat ) ); ?>
					<br>
					<span class="description">
						<?php esc_html_e( 'We detected an active Stripe or WooPayments gateway. ACP shared payment tokens can be settled directly in your existing processor.', 'shopwalk-for-woocommerce' ); ?>
					</span>
				<?php else : ?>
					<span style="color:#b26200;font-weight:600;">&#x26A0;&#xFE0F;</span>
					<?php echo esc_html( $this->payment_compat_label( $payment_compat ) ); ?>
					<br>
					<span class="description">
						<?php esc_html_e( 'No active Stripe or WooPayments gateway found. ChatGPT will deep-link buyers to your existing checkout instead of finalizing the order in chat.', 'shopwalk-for-woocommerce' ); ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout' ) ); ?>"><?php esc_html_e( 'Configure payments →', 'shopwalk-for-woocommerce' ); ?></a>
					</span>
				<?php endif; ?>
			</p>

			<h3><?php esc_html_e( 'ACP Terms Addendum', 'shopwalk-for-woocommerce' ); ?></h3>
			<div style="border:1px solid #dcdcde;border-radius:4px;padding:12px;max-height:280px;overflow:auto;background:#f6f7f7;">
				<?php
				// $this->tos_html() returns wp_kses_post-sanitized HTML.
				echo $this->tos_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already sanitized via wp_kses_post.
				?>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:16px;">
				<input type="hidden" name="action" value="shopwalk_acp_opt_in" />
				<input type="hidden" name="payment_compat" value="<?php echo esc_attr( $payment_compat ); ?>" />
				<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>

				<p>
					<label>
						<input type="checkbox" name="acp_tos_ack" value="1" required />
						<?php
						printf(
							/* translators: %s: ToS version string, e.g. v1. */
							esc_html__( 'I have read and accept the Shopwalk ACP Terms Addendum (%s) on behalf of my store.', 'shopwalk-for-woocommerce' ),
							esc_html( Shopwalk_ACP_Client::TOS_VERSION )
						);
						?>
					</label>
				</p>

				<p>
					<button type="submit" class="button button-primary">
						<?php esc_html_e( 'Enable ChatGPT channel', 'shopwalk-for-woocommerce' ); ?>
					</button>
				</p>
			</form>
		</div>
		<?php
	}

	// ── Post-opt-in view ─────────────────────────────────────────────────

	/**
	 * Post-opt-in screen. Shows live status, pause/resume button, feed item
	 * count, and any active moderation flags.
	 */
	private function render_post_opt_in_view( array $status, string $payment_compat ): void {
		$state              = isset( $status['status'] ) ? (string) $status['status'] : 'opted_in';
		$is_paused          = 'paused' === $state;
		$feed_item_count    = isset( $status['feed_item_count'] ) ? (int) $status['feed_item_count'] : 0;
		$moderation_flags   = isset( $status['moderation_flags'] ) && is_array( $status['moderation_flags'] ) ? $status['moderation_flags'] : array();
		$server_compat      = isset( $status['payment_compat'] ) ? (string) $status['payment_compat'] : $payment_compat;
		$server_tos_version = isset( $status['tos_version'] ) ? (string) $status['tos_version'] : '';
		?>
		<div class="sw-card" style="background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:20px;margin:16px 0;max-width:820px;">
			<h2>
				<?php esc_html_e( 'ChatGPT channel status', 'shopwalk-for-woocommerce' ); ?>
				<?php if ( $is_paused ) : ?>
					<span style="background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:4px;font-size:13px;margin-left:8px;"><?php esc_html_e( 'Paused', 'shopwalk-for-woocommerce' ); ?></span>
				<?php else : ?>
					<span style="background:#d1fae5;color:#065f46;padding:2px 8px;border-radius:4px;font-size:13px;margin-left:8px;"><?php esc_html_e( 'Active', 'shopwalk-for-woocommerce' ); ?></span>
				<?php endif; ?>
			</h2>

			<table class="widefat striped" style="max-width:600px;margin-bottom:16px;">
				<tbody>
					<tr>
						<th scope="row" style="width:200px;"><?php esc_html_e( 'Status', 'shopwalk-for-woocommerce' ); ?></th>
						<td><?php echo esc_html( $is_paused ? __( 'Paused', 'shopwalk-for-woocommerce' ) : __( 'Opted in', 'shopwalk-for-woocommerce' ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Items in ACP feed', 'shopwalk-for-woocommerce' ); ?></th>
						<td><?php echo esc_html( number_format_i18n( $feed_item_count ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Payment compatibility', 'shopwalk-for-woocommerce' ); ?></th>
						<td><?php echo esc_html( $this->payment_compat_label( $server_compat ) ); ?></td>
					</tr>
					<?php if ( '' !== $server_tos_version ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'ToS version accepted', 'shopwalk-for-woocommerce' ); ?></th>
						<td><?php echo esc_html( $server_tos_version ); ?></td>
					</tr>
					<?php endif; ?>
				</tbody>
			</table>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="shopwalk_acp_pause" />
				<input type="hidden" name="paused" value="<?php echo $is_paused ? '0' : '1'; ?>" />
				<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
				<p>
					<button type="submit" class="button">
						<?php echo esc_html( $is_paused ? __( 'Resume ChatGPT channel', 'shopwalk-for-woocommerce' ) : __( 'Pause ChatGPT channel', 'shopwalk-for-woocommerce' ) ); ?>
					</button>
					<span class="description" style="margin-left:8px;">
						<?php esc_html_e( 'Pausing removes your products from the next feed publish. In-flight checkouts are allowed to complete.', 'shopwalk-for-woocommerce' ); ?>
					</span>
				</p>
			</form>
		</div>

		<?php $this->render_moderation_flags( $moderation_flags ); ?>
		<?php
	}

	/**
	 * Render the active-moderation-flags table. Empty list = no panel.
	 *
	 * @param array<int,array<string,mixed>> $flags Flags as returned by /status.
	 */
	private function render_moderation_flags( array $flags ): void {
		if ( empty( $flags ) ) {
			return;
		}
		?>
		<div class="sw-card" style="background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:20px;margin:16px 0;max-width:820px;">
			<h2><?php esc_html_e( 'Active moderation flags', 'shopwalk-for-woocommerce' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'These products were excluded from the ACP feed by Shopwalk\'s pre-publish moderation. Fix the underlying issue and the next publish will pick them up automatically.', 'shopwalk-for-woocommerce' ); ?>
			</p>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Product', 'shopwalk-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Reason', 'shopwalk-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Detected', 'shopwalk-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Fix', 'shopwalk-for-woocommerce' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $flags as $flag ) : ?>
					<?php
					$product_id   = isset( $flag['product_id'] ) ? (string) $flag['product_id'] : '';
					$product_name = isset( $flag['product_name'] ) ? (string) $flag['product_name'] : $product_id;
					$reason       = isset( $flag['reason'] ) ? (string) $flag['reason'] : '';
					$detail       = isset( $flag['detail'] ) ? (string) $flag['detail'] : '';
					$created_at   = isset( $flag['created_at'] ) ? (string) $flag['created_at'] : '';
					$edit_url     = '';
					if ( '' !== $product_id && ctype_digit( $product_id ) ) {
						$edit_url = get_edit_post_link( (int) $product_id );
					}
					?>
					<tr>
						<td>
							<?php if ( $edit_url ) : ?>
								<a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $product_name ); ?></a>
							<?php else : ?>
								<?php echo esc_html( $product_name ); ?>
							<?php endif; ?>
						</td>
						<td>
							<code><?php echo esc_html( $reason ); ?></code>
							<?php if ( '' !== $detail ) : ?>
								<br><span class="description"><?php echo esc_html( $detail ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $created_at ); ?></td>
						<td>
							<?php if ( $edit_url ) : ?>
								<a href="<?php echo esc_url( $edit_url ); ?>" class="button button-small"><?php esc_html_e( 'Edit product', 'shopwalk-for-woocommerce' ); ?></a>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	// ── admin-post handlers ──────────────────────────────────────────────

	/**
	 * Handle the "Enable ChatGPT channel" form submission. Verifies nonce
	 * + capability + ToS checkbox, then forwards to shopwalk-api.
	 *
	 * @return void
	 */
	public function handle_opt_in(): void {
		$this->verify_request();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above in verify_request().
		$tos_ack        = isset( $_POST['acp_tos_ack'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['acp_tos_ack'] ) );
		$payment_compat = isset( $_POST['payment_compat'] ) ? sanitize_text_field( wp_unslash( $_POST['payment_compat'] ) ) : 'deep_link';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( ! in_array( $payment_compat, array( 'full', 'deep_link' ), true ) ) {
			$payment_compat = 'deep_link';
		}

		if ( ! $tos_ack ) {
			$this->redirect_back( 'error', __( 'You must acknowledge the ACP Terms Addendum to enable the channel.', 'shopwalk-for-woocommerce' ) );
			return;
		}

		$result = Shopwalk_ACP_Client::opt_in( $payment_compat );
		if ( $result['ok'] ) {
			$this->redirect_back( 'ok', __( 'ChatGPT channel enabled. Your products will appear in the next feed publish.', 'shopwalk-for-woocommerce' ) );
			return;
		}
		$this->redirect_back( 'error', (string) ( $result['message'] ?? __( 'Opt-in failed.', 'shopwalk-for-woocommerce' ) ) );
	}

	/**
	 * Handle pause/resume toggle.
	 *
	 * @return void
	 */
	public function handle_pause_toggle(): void {
		$this->verify_request();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above in verify_request().
		$paused = isset( $_POST['paused'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['paused'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$result = Shopwalk_ACP_Client::set_paused( $paused );
		if ( $result['ok'] ) {
			$message = $paused
				? __( 'ChatGPT channel paused.', 'shopwalk-for-woocommerce' )
				: __( 'ChatGPT channel resumed.', 'shopwalk-for-woocommerce' );
			$this->redirect_back( 'ok', $message );
			return;
		}
		$this->redirect_back( 'error', (string) ( $result['message'] ?? __( 'Pause toggle failed.', 'shopwalk-for-woocommerce' ) ) );
	}

	/**
	 * Shared request validation for the admin-post handlers — nonce,
	 * capability, request method. Dies on failure.
	 *
	 * @return void
	 */
	private function verify_request(): void {
		if ( 'POST' !== strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) ) {
			wp_die( esc_html__( 'Invalid request method.', 'shopwalk-for-woocommerce' ), '', array( 'response' => 405 ) );
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'shopwalk-for-woocommerce' ), '', array( 'response' => 403 ) );
		}
		$nonce = isset( $_POST[ self::NONCE_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			wp_die( esc_html__( 'Security check failed.', 'shopwalk-for-woocommerce' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Redirect back to the ACP admin page with a status + message in the
	 * query string for render_action_notice() to surface.
	 *
	 * @param string $result  'ok' | 'error'.
	 * @param string $message Human-readable message.
	 * @return void
	 */
	private function redirect_back( string $result, string $message ): void {
		$url = add_query_arg(
			array(
				'page'       => self::MENU_SLUG,
				'sw_acp'     => $result,
				'sw_acp_msg' => rawurlencode( $message ),
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}
}

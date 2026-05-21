<?php
/**
 * Shopwalk_ACP_Admin — "Shopwalk → AI Channels → ChatGPT (ACP)" admin page.
 *
 * Per `platform/SHOPWALK_ACP_INTEGRATION.md` §5 (post-spec-revision):
 * connecting to Shopwalk = opted in to ACP. There is no separate ACP
 * opt-in flow. This page renders a single-state status view: current
 * status, pause/resume toggle, payment-compat indicator, feed count,
 * and any active moderation flags.
 *
 * Unlicensed / disconnected stores see a "connect to Shopwalk first" hint.
 *
 * Loaded only when the optional Shopwalk integration is connected
 * (Tier 2). Without a license there is no partner record to surface.
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
		add_action( 'admin_post_shopwalk_acp_pause', array( $this, 'handle_pause_toggle' ) );
	}

	/**
	 * Register the "ChatGPT (ACP)" submenu under the existing Shopwalk
	 * top-level menu.
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
	 * Informational only — does NOT gate ACP eligibility. A store with no
	 * Stripe gateway is still in the feed; ChatGPT just deep-links to the
	 * store's own checkout instead of finishing in-chat.
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
		return __( 'Deep-link handoff (buyer redirected to your checkout page).', 'shopwalk-for-woocommerce' );
	}

	// ── Page render ──────────────────────────────────────────────────────

	/**
	 * Top-level page renderer.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'shopwalk-for-woocommerce' ) );
		}

		$status         = Shopwalk_ACP_Client::status();
		$payment_compat = self::detect_payment_compat();
		$status_code    = isset( $status['status_code'] ) ? (int) $status['status_code'] : 0;
		$state          = isset( $status['status'] ) ? (string) $status['status'] : '';

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'ChatGPT (ACP)', 'shopwalk-for-woocommerce' ) . '</h1>';

		$this->render_action_notice();

		// Not connected to Shopwalk — show a connect hint and stop.
		if ( ! $status['ok'] && ( 0 === $status_code || 401 === $status_code || 404 === $status_code ) ) {
			$this->render_not_connected_view();
			echo '</div>';
			return;
		}

		// Transient server error — surface and let the merchant retry.
		if ( ! $status['ok'] ) {
			$this->render_status_error( $status );
			echo '</div>';
			return;
		}

		$this->render_status_view( $state, $status, $payment_compat );

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
	 * Render an error banner when /status failed with a non-recoverable
	 * server error (5xx, timeouts).
	 */
	private function render_status_error( array $status ): void {
		$message = isset( $status['message'] ) ? (string) $status['message'] : __( 'Could not reach Shopwalk.', 'shopwalk-for-woocommerce' );
		printf(
			'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s</p></div>',
			esc_html__( 'ACP status unavailable:', 'shopwalk-for-woocommerce' ),
			esc_html( $message )
		);
	}

	// ── Not-connected view ───────────────────────────────────────────────

	/**
	 * Render the placeholder for unlicensed / disconnected stores. ACP only
	 * applies once the store is connected to Shopwalk.
	 */
	private function render_not_connected_view(): void {
		$connect_url = admin_url( 'admin.php?page=shopwalk-for-woocommerce' );
		?>
		<div class="sw-card" style="background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:20px;margin:16px 0;max-width:820px;">
			<h2><?php esc_html_e( 'Connect to Shopwalk to enable AI channels', 'shopwalk-for-woocommerce' ); ?></h2>
			<p>
				<?php esc_html_e( 'ChatGPT and other AI shopping agents access your products through Shopwalk. Connect your store to Shopwalk to make your products discoverable in ChatGPT and let AI agents complete checkouts on your behalf.', 'shopwalk-for-woocommerce' ); ?>
			</p>
			<p>
				<?php esc_html_e( 'There is no separate ChatGPT opt-in. Connecting to Shopwalk covers it. You can pause the AI channel from this page at any time.', 'shopwalk-for-woocommerce' ); ?>
			</p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( $connect_url ); ?>">
					<?php esc_html_e( 'Connect to Shopwalk →', 'shopwalk-for-woocommerce' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	// ── Status view ──────────────────────────────────────────────────────

	/**
	 * Render the single-state status panel — current state, pause/resume,
	 * payment-compat, feed count, moderation flags.
	 *
	 * @param string $state         'opted_in' | 'paused' | '' (unknown — defaults to opted_in).
	 * @param array  $status        Decoded /status response body.
	 * @param string $payment_compat Locally-detected compat tag.
	 */
	private function render_status_view( string $state, array $status, string $payment_compat ): void {
		$is_paused        = 'paused' === $state;
		$feed_item_count  = isset( $status['feed_item_count'] ) ? (int) $status['feed_item_count'] : 0;
		$moderation_flags = isset( $status['moderation_flags'] ) && is_array( $status['moderation_flags'] ) ? $status['moderation_flags'] : array();
		$server_compat    = isset( $status['payment_compat'] ) ? (string) $status['payment_compat'] : $payment_compat;
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

			<?php if ( $is_paused ) : ?>
				<p>
					<?php esc_html_e( 'Paused. Your products are temporarily hidden from ChatGPT and other AI agents through Shopwalk. In-flight ACP checkouts are allowed to complete; no new ones will be accepted.', 'shopwalk-for-woocommerce' ); ?>
				</p>
			<?php else : ?>
				<p>
					<?php esc_html_e( 'Active. Your products are accessible via ChatGPT and other AI agents through Shopwalk.', 'shopwalk-for-woocommerce' ); ?>
				</p>
			<?php endif; ?>

			<table class="widefat striped" style="max-width:600px;margin-bottom:16px;">
				<tbody>
					<tr>
						<th scope="row" style="width:200px;"><?php esc_html_e( 'Items in ACP feed', 'shopwalk-for-woocommerce' ); ?></th>
						<td><?php echo esc_html( number_format_i18n( $feed_item_count ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Payment compatibility', 'shopwalk-for-woocommerce' ); ?></th>
						<td>
							<?php echo esc_html( $this->payment_compat_label( $server_compat ) ); ?>
							<?php if ( 'full' !== $server_compat ) : ?>
								<br><span class="description">
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout' ) ); ?>">
										<?php esc_html_e( 'Configure WooPayments or Stripe to enable in-chat checkout →', 'shopwalk-for-woocommerce' ); ?>
									</a>
								</span>
							<?php endif; ?>
						</td>
					</tr>
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
						<?php if ( $is_paused ) : ?>
							<?php esc_html_e( 'Resume to re-include your products in the next feed publish.', 'shopwalk-for-woocommerce' ); ?>
						<?php else : ?>
							<?php esc_html_e( 'Pausing removes your products from the next feed publish. In-flight checkouts are allowed to complete.', 'shopwalk-for-woocommerce' ); ?>
						<?php endif; ?>
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

	// ── admin-post handler ───────────────────────────────────────────────

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
	 * Shared request validation for the admin-post handler — nonce,
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

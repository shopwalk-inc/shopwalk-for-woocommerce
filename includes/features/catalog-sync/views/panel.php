<?php
/**
 * View template for the AI Catalog Sync admin panel.
 *
 * Expects the following in scope (set by Shopwalk_Catalog_Sync_Admin::render_panel):
 *   $is_pro     bool   Whether the Pro license is active.
 *   $stats      array  Snapshot from Shopwalk_Catalog_Sync_Scheduler::stats().
 *   $log        array  Rolling event log (newest last).
 *   $nonce      string Nonce for POST handlers.
 *   $action_url string Target for the form submissions (admin-post.php).
 *
 * @package ShopwalkWooCommerce
 */

defined( 'ABSPATH' ) || exit;

// Notice flag from the redirect-back POST handlers.
$notice_key = isset( $_GET['shopwalk_catalog_sync_msg'] )
	? sanitize_key( wp_unslash( (string) $_GET['shopwalk_catalog_sync_msg'] ) )
	: '';
$notice_map = array(
	'full_sync_started' => __( 'Full sync started. Items will appear in the log as they push.', 'shopwalk-for-woocommerce' ),
	'paused'            => __( 'Sync paused. New changes will queue but won\'t push until you resume.', 'shopwalk-for-woocommerce' ),
	'resumed'           => __( 'Sync resumed.', 'shopwalk-for-woocommerce' ),
);
if ( '' !== $notice_key && isset( $notice_map[ $notice_key ] ) ) {
	echo '<div class="notice notice-success is-dismissible shopwalk-catalog-sync-notice"><p>'
		. esc_html( $notice_map[ $notice_key ] ) . '</p></div>';
}
?>

<div class="shopwalk-catalog-sync-panel">

	<?php if ( ! $is_pro ) : ?>
		<div class="shopwalk-catalog-sync-upgrade">
			<h2><?php esc_html_e( 'Pro upgrade required', 'shopwalk-for-woocommerce' ); ?></h2>
			<p>
				<?php esc_html_e( 'AI Catalog Sync pushes your products and orders to Shopwalk so AI descriptions, search, recommendations, SEO, and brand-voice features have something to work with. It\'s included with any active Shopwalk Pro subscription.', 'shopwalk-for-woocommerce' ); ?>
			</p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=shopwalk-for-woocommerce' ) ); ?>">
					<?php esc_html_e( 'Connect to Shopwalk', 'shopwalk-for-woocommerce' ); ?>
				</a>
			</p>
		</div>
		<?php return; ?>
	<?php endif; ?>

	<table class="widefat striped shopwalk-catalog-sync-stats">
		<tbody>
			<tr>
				<th scope="row"><?php esc_html_e( 'Full sync status', 'shopwalk-for-woocommerce' ); ?></th>
				<td>
					<?php
					$state_labels = array(
						'idle'     => __( 'Idle — no full sync running', 'shopwalk-for-woocommerce' ),
						'running'  => __( 'Running', 'shopwalk-for-woocommerce' ),
						'complete' => __( 'Complete', 'shopwalk-for-woocommerce' ),
					);
					$state        = (string) ( $stats['full_sync_state'] ?? 'idle' );
					echo esc_html( $state_labels[ $state ] ?? $state );
					if ( 'running' === $state && ! empty( $stats['full_sync_page'] ) ) {
						echo ' <span class="shopwalk-catalog-sync-muted">(' .
							sprintf(
								/* translators: %d: 1-based page number */
								esc_html__( 'page %d', 'shopwalk-for-woocommerce' ),
								(int) $stats['full_sync_page']
							) . ')</span>';
					}
					if ( 'complete' === $state && ! empty( $stats['full_sync_finished'] ) ) {
						echo ' <span class="shopwalk-catalog-sync-muted">' .
							esc_html( gmdate( 'Y-m-d H:i \U\T\C', (int) $stats['full_sync_finished'] ) ) . '</span>';
					}
					?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Last delta sync', 'shopwalk-for-woocommerce' ); ?></th>
				<td>
					<?php
					$last = (int) ( $stats['last_delta_at'] ?? 0 );
					if ( $last > 0 ) {
						echo esc_html( gmdate( 'Y-m-d H:i \U\T\C', $last ) ) .
							' <span class="shopwalk-catalog-sync-muted">(' .
							esc_html( human_time_diff( $last, time() ) ) . ' ' .
							esc_html__( 'ago', 'shopwalk-for-woocommerce' ) . ')</span>';
					} else {
						echo '<em>' . esc_html__( 'Never — waiting for first delta tick.', 'shopwalk-for-woocommerce' ) . '</em>';
					}
					?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Items synced today', 'shopwalk-for-woocommerce' ); ?></th>
				<td><?php echo esc_html( (string) (int) ( $stats['items_synced_today'] ?? 0 ) ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Pending in queue', 'shopwalk-for-woocommerce' ); ?></th>
				<td><?php echo esc_html( (string) (int) ( $stats['pending'] ?? 0 ) ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Sync state', 'shopwalk-for-woocommerce' ); ?></th>
				<td>
					<?php if ( ! empty( $stats['paused'] ) ) : ?>
						<span class="shopwalk-catalog-sync-pill shopwalk-catalog-sync-pill-warn"><?php esc_html_e( 'Paused', 'shopwalk-for-woocommerce' ); ?></span>
					<?php else : ?>
						<span class="shopwalk-catalog-sync-pill shopwalk-catalog-sync-pill-ok"><?php esc_html_e( 'Active', 'shopwalk-for-woocommerce' ); ?></span>
					<?php endif; ?>
				</td>
			</tr>
		</tbody>
	</table>

	<div class="shopwalk-catalog-sync-actions">
		<form method="post" action="<?php echo esc_url( $action_url ); ?>" class="shopwalk-catalog-sync-form">
			<input type="hidden" name="action" value="shopwalk_catalog_sync_run" />
			<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>" />
			<button type="submit" class="button button-primary">
				<?php esc_html_e( 'Run full sync now', 'shopwalk-for-woocommerce' ); ?>
			</button>
		</form>

		<form method="post" action="<?php echo esc_url( $action_url ); ?>" class="shopwalk-catalog-sync-form">
			<input type="hidden" name="action" value="shopwalk_catalog_sync_toggle_pause" />
			<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>" />
			<button type="submit" class="button">
				<?php echo esc_html( ! empty( $stats['paused'] ) ? __( 'Resume sync', 'shopwalk-for-woocommerce' ) : __( 'Pause sync', 'shopwalk-for-woocommerce' ) ); ?>
			</button>
		</form>
	</div>

	<h2><?php esc_html_e( 'Recent events', 'shopwalk-for-woocommerce' ); ?></h2>
	<?php if ( empty( $log ) ) : ?>
		<p><em><?php esc_html_e( 'No events recorded yet.', 'shopwalk-for-woocommerce' ); ?></em></p>
	<?php else : ?>
		<table class="widefat striped shopwalk-catalog-sync-log">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Time (UTC)', 'shopwalk-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Action', 'shopwalk-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Items', 'shopwalk-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Status', 'shopwalk-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Detail', 'shopwalk-for-woocommerce' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( array_reverse( $log ) as $row ) : ?>
					<tr>
						<td><?php echo esc_html( gmdate( 'Y-m-d H:i:s', (int) ( $row['ts'] ?? 0 ) ) ); ?></td>
						<td><?php echo esc_html( (string) ( $row['action'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( (string) (int) ( $row['count'] ?? 0 ) ); ?></td>
						<td>
							<?php $status = (string) ( $row['status'] ?? '' ); ?>
							<span class="shopwalk-catalog-sync-pill shopwalk-catalog-sync-pill-<?php echo 'ok' === $status ? 'ok' : 'err'; ?>">
								<?php echo esc_html( $status ); ?>
							</span>
						</td>
						<td>
							<?php
							$http  = (int) ( $row['http'] ?? 0 );
							$error = (string) ( $row['error'] ?? '' );
							$bits  = array();
							if ( $http > 0 ) {
								$bits[] = 'HTTP ' . $http;
							}
							if ( '' !== $error ) {
								$bits[] = $error;
							}
							echo esc_html( implode( ' — ', $bits ) );
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

</div>

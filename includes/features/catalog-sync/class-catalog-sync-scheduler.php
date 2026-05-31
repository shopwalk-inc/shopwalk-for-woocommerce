<?php
/**
 * Shopwalk_Catalog_Sync_Scheduler — Action Scheduler queue management.
 *
 * Owns:
 *   - Per-item enqueue helpers (debounced via the unique-action pattern)
 *   - Recurring delta-sync registration
 *   - Full-sync pagination state
 *   - The rolling event log surfaced in the admin panel
 *
 * No work runs inline — every public enqueue method just schedules an AS
 * action. Failure handling (backoff, retries) is delegated to Action
 * Scheduler's defaults. See `platform/woocommerce/action-scheduler.md`.
 *
 * @package ShopwalkWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shopwalk_Catalog_Sync_Scheduler — AS-backed sync queue.
 */
final class Shopwalk_Catalog_Sync_Scheduler {

	/** AS group — keeps the plugin's actions filterable in WC → Status → Scheduled Actions. */
	public const GROUP = 'shopwalk-catalog-sync';

	/** AS action: push one product. Args: [product_id]. */
	public const ACTION_SYNC_PRODUCT = 'shopwalk_catalog_sync_product';

	/** AS action: push one order. Args: [order_id, event_type]. */
	public const ACTION_SYNC_ORDER = 'shopwalk_catalog_sync_order';

	/** AS action: recurring delta sweep. No args. */
	public const ACTION_DELTA_SYNC = 'shopwalk_catalog_sync_delta';

	/** AS action: full-sync one page of products. Args: [page]. */
	public const ACTION_FULL_SYNC_PAGE = 'shopwalk_catalog_sync_full_page';

	/** Default delta-sync interval (5 minutes). Filterable via `shopwalk_catalog_sync_delta_interval`. */
	public const DEFAULT_DELTA_INTERVAL = 300;

	/** Debounce window for per-item enqueues — collapses edit-storms. */
	public const DEBOUNCE_SECONDS = 5;

	/** Full-sync page size — bounded so a single AS job fits comfortably under the API body limit. */
	public const FULL_SYNC_PAGE_SIZE = 100;

	/** Option: ISO timestamp of the last successful delta-sync sweep. */
	public const OPTION_LAST_DELTA_AT = 'shopwalk_catalog_sync_last_delta_at';

	/** Option: current full-sync state — "idle" | "running" | "complete". */
	public const OPTION_FULL_SYNC_STATE = 'shopwalk_catalog_sync_full_state';

	/** Option: page cursor for an in-progress full sync. */
	public const OPTION_FULL_SYNC_PAGE = 'shopwalk_catalog_sync_full_page';

	/** Option: UNIX timestamp the last full sync finished. */
	public const OPTION_FULL_SYNC_FINISHED_AT = 'shopwalk_catalog_sync_full_finished_at';

	/** Option: rolling event log (last 100 events). */
	public const OPTION_LOG = 'shopwalk_catalog_sync_log';

	/** Option: per-day items-synced counter, keyed by YYYY-MM-DD. */
	public const OPTION_DAILY_COUNT = 'shopwalk_catalog_sync_daily';

	/** Cap on the rolling event log. */
	private const LOG_CAP = 100;

	/**
	 * Enqueue a single-product sync. Debounced — repeated saves within
	 * DEBOUNCE_SECONDS collapse to one push.
	 *
	 * @param int $product_id Product ID.
	 * @return void
	 */
	public static function enqueue_product( int $product_id ): void {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}
		as_schedule_single_action(
			time() + self::DEBOUNCE_SECONDS,
			self::ACTION_SYNC_PRODUCT,
			array( $product_id ),
			self::GROUP,
			true /* unique */
		);
	}

	/**
	 * Enqueue a product deletion record.
	 *
	 * @param int $product_id Product ID.
	 * @return void
	 */
	public static function enqueue_product_delete( int $product_id ): void {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}
		// Deletion needs to fire promptly (debounce defeats the purpose
		// when the row is about to disappear) — schedule immediately.
		as_schedule_single_action(
			time() + 1,
			self::ACTION_SYNC_PRODUCT,
			array( $product_id ),
			self::GROUP,
			true
		);
	}

	/**
	 * Enqueue an order sync.
	 *
	 * @param int    $order_id   Order ID.
	 * @param string $event_type Event-type tag (created|status_changed|refunded|delta).
	 * @return void
	 */
	public static function enqueue_order( int $order_id, string $event_type ): void {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}
		as_schedule_single_action(
			time() + self::DEBOUNCE_SECONDS,
			self::ACTION_SYNC_ORDER,
			array( $order_id, $event_type ),
			self::GROUP,
			true
		);
	}

	/**
	 * Ensure the recurring delta-sync action is scheduled. Idempotent —
	 * Action Scheduler dedupes recurring schedules by hook + group.
	 *
	 * @return void
	 */
	public static function ensure_recurring(): void {
		if ( ! function_exists( 'as_next_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}
		$interval = self::DEFAULT_DELTA_INTERVAL;
		if ( function_exists( 'apply_filters' ) ) {
			$interval = (int) apply_filters( 'shopwalk_catalog_sync_delta_interval', $interval );
		}
		$interval = max( 60, $interval );

		if ( false === as_next_scheduled_action( self::ACTION_DELTA_SYNC, array(), self::GROUP ) ) {
			as_schedule_recurring_action( time() + $interval, $interval, self::ACTION_DELTA_SYNC, array(), self::GROUP );
		}
	}

	/**
	 * Kick off a full sync. Sets the state cursor and schedules page 1.
	 *
	 * @return void
	 */
	public static function start_full_sync(): void {
		update_option( self::OPTION_FULL_SYNC_STATE, 'running' );
		update_option( self::OPTION_FULL_SYNC_PAGE, 0 );
		self::schedule_full_sync_page( 1 );
		self::log_result( 'full_sync_start', array( 'ok' => true ), 0 );
	}

	/**
	 * Schedule the next full-sync page.
	 *
	 * @param int $page Page number.
	 * @return void
	 */
	public static function schedule_full_sync_page( int $page ): void {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}
		as_schedule_single_action(
			time() + 1,
			self::ACTION_FULL_SYNC_PAGE,
			array( $page ),
			self::GROUP,
			true
		);
	}

	/**
	 * Cancel every scheduled action in the catalog-sync group. Called
	 * on auth-fail (to stop the retry storm) and on Pro deactivation.
	 *
	 * @return void
	 */
	public static function cancel_all(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', array(), self::GROUP );
		}
		update_option( self::OPTION_FULL_SYNC_STATE, 'idle' );
	}

	/**
	 * Append a single event to the rolling log (capped at LOG_CAP).
	 *
	 * @param string $action  Short action identifier.
	 * @param array  $result  Result envelope from the API client.
	 * @param int    $count   Item count for this batch.
	 * @return void
	 */
	public static function log_result( string $action, array $result, int $count ): void {
		$log = (array) get_option( self::OPTION_LOG, array() );
		$log[] = array(
			'ts'     => time(),
			'action' => $action,
			'count'  => $count,
			'status' => empty( $result['ok'] ) ? 'error' : 'ok',
			'http'   => isset( $result['status'] ) ? (int) $result['status'] : 0,
			'error'  => isset( $result['error'] ) ? (string) $result['error'] : '',
		);
		if ( count( $log ) > self::LOG_CAP ) {
			$log = array_slice( $log, -self::LOG_CAP );
		}
		update_option( self::OPTION_LOG, $log, false );

		if ( ! empty( $result['ok'] ) && $count > 0 ) {
			self::bump_daily_count( $count );
		}
	}

	/**
	 * Per-day counter — drives the "items synced today" admin stat.
	 *
	 * @param int $count How many to add.
	 * @return void
	 */
	private static function bump_daily_count( int $count ): void {
		$key   = gmdate( 'Y-m-d' );
		$state = (array) get_option( self::OPTION_DAILY_COUNT, array() );
		$state[ $key ] = (int) ( $state[ $key ] ?? 0 ) + $count;
		// Keep only the last 14 days.
		if ( count( $state ) > 14 ) {
			ksort( $state );
			$state = array_slice( $state, -14, null, true );
		}
		update_option( self::OPTION_DAILY_COUNT, $state, false );
	}

	/**
	 * Stats snapshot for the admin panel.
	 *
	 * @return array<string,mixed>
	 */
	public static function stats(): array {
		$state    = (string) get_option( self::OPTION_FULL_SYNC_STATE, 'idle' );
		$page     = (int) get_option( self::OPTION_FULL_SYNC_PAGE, 0 );
		$finished = (int) get_option( self::OPTION_FULL_SYNC_FINISHED_AT, 0 );
		$delta    = (int) get_option( self::OPTION_LAST_DELTA_AT, 0 );
		$daily    = (array) get_option( self::OPTION_DAILY_COUNT, array() );
		$today    = (int) ( $daily[ gmdate( 'Y-m-d' ) ] ?? 0 );
		$pending  = self::count_pending();

		return array(
			'full_sync_state'    => $state,
			'full_sync_page'     => $page,
			'full_sync_finished' => $finished,
			'last_delta_at'      => $delta,
			'items_synced_today' => $today,
			'pending'            => $pending,
			'paused'             => Shopwalk_Catalog_Sync_Feature::is_paused(),
		);
	}

	/**
	 * Count pending AS actions in our group — best-effort, returns 0 if AS
	 * helpers are unavailable.
	 *
	 * @return int
	 */
	private static function count_pending(): int {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return 0;
		}
		$ids = as_get_scheduled_actions(
			array(
				'group'    => self::GROUP,
				'status'   => 'pending',
				'per_page' => 1000,
			),
			'ids'
		);
		return is_array( $ids ) ? count( $ids ) : 0;
	}
}

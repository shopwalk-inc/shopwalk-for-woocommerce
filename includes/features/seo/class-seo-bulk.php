<?php
/**
 * Shopwalk_Seo_Bulk — Action Scheduler bulk runner for catalog-wide SEO.
 *
 * Schedules one `shopwalk_seo_bulk_process_item` job per product matched by
 * the scope filter (all / by-category / missing-alt-text / etc.). Each job
 * runs Shopwalk_Seo_Generator::generate() + apply() with the merchant's
 * selected fields, throttled by Action Scheduler concurrency.
 *
 * The kickoff job (`shopwalk_seo_bulk_kickoff`) enumerates the catalog in
 * pages, enqueuing per-item jobs in batches, then chaining itself for the
 * next page. This avoids enumerating millions of products into a single
 * request and lets the merchant abort mid-run cleanly.
 *
 * @package ShopwalkWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shopwalk_Seo_Bulk — bulk-run orchestrator.
 */
final class Shopwalk_Seo_Bulk {

	/**
	 * Action Scheduler group used to corral our scheduled jobs.
	 */
	public const GROUP = 'shopwalk-seo';

	/**
	 * Page size for the kickoff enumeration. Tuned to keep each kickoff job
	 * well under the action-scheduler 30s default timeout.
	 */
	public const PAGE_SIZE = 50;

	/**
	 * Option key used to track an in-flight bulk run's high-level state
	 * (totals, progress, started_at, options).
	 */
	public const OPTION_STATE = 'shopwalk_seo_bulk_state';

	/**
	 * Start a new bulk run. Validates input, persists state, and schedules
	 * the first kickoff job.
	 *
	 * @param array $options {
	 *   scope:string,                 // all|category|missing_alt|missing_meta_title|missing_meta_desc
	 *   category_id?:int,             // when scope=category
	 *   fields:string[],              // subset of ["meta_title","meta_description","image_alt","seo_checklist"]
	 *   overwrite_alt?:bool,          // force-overwrite non-empty alt text
	 *   focus_keyphrase?:string       // applied uniformly across the run (rare; per-product UI is better)
	 * }
	 * @return array {ok:bool, message?:string}
	 */
	public static function start( array $options ): array {
		if ( ! Shopwalk_Seo_Feature::is_tier_allowed() ) {
			return array(
				'ok'      => false,
				'message' => __( 'AI SEO requires a Pro license.', 'shopwalk-for-woocommerce' ),
			);
		}
		if ( ! function_exists( 'as_enqueue_async_action' ) && ! function_exists( 'as_schedule_single_action' ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'Action Scheduler is not available — install WooCommerce 8.0+.', 'shopwalk-for-woocommerce' ),
			);
		}

		$scope  = isset( $options['scope'] ) ? (string) $options['scope'] : 'all';
		$fields = isset( $options['fields'] ) && is_array( $options['fields'] )
			? array_values( array_unique( array_map( 'sanitize_key', $options['fields'] ) ) )
			: array( 'meta_title', 'meta_description', 'image_alt', 'seo_checklist' );

		$state = array(
			'scope'           => $scope,
			'category_id'     => isset( $options['category_id'] ) ? (int) $options['category_id'] : 0,
			'fields'          => $fields,
			'overwrite_alt'   => ! empty( $options['overwrite_alt'] ),
			'focus_keyphrase' => isset( $options['focus_keyphrase'] ) ? (string) $options['focus_keyphrase'] : '',
			'started_at'      => time(),
			'page'            => 1,
			'enqueued'        => 0,
			'completed'       => 0,
			'errors'          => 0,
		);
		update_option( self::OPTION_STATE, $state, false );

		self::schedule_kickoff( 1 );

		return array( 'ok' => true );
	}

	/**
	 * Schedule the kickoff job for a given page. Uses
	 * as_enqueue_async_action when available, falls back to a 1-second
	 * single action.
	 *
	 * @param int $page Page number.
	 */
	public static function schedule_kickoff( int $page ): void {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( 'shopwalk_seo_bulk_kickoff', array( $page ), self::GROUP );
		} elseif ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + 1, 'shopwalk_seo_bulk_kickoff', array( $page ), self::GROUP );
		}
	}

	/**
	 * Kickoff handler — enumerate the next page of matching products and
	 * enqueue per-item jobs. Re-schedules itself for the next page.
	 *
	 * @param int $page Page to process.
	 */
	public static function kickoff( int $page ): void {
		$state = get_option( self::OPTION_STATE, array() );
		if ( ! is_array( $state ) || empty( $state ) ) {
			return; // bulk run was cancelled.
		}

		$ids = self::query_product_ids( $state, $page );
		if ( empty( $ids ) ) {
			// Done enumerating. Per-item jobs may still be running; their
			// completion handler decrements `completed` until everything's
			// done. We don't clear state here.
			return;
		}

		foreach ( $ids as $product_id ) {
			if ( function_exists( 'as_enqueue_async_action' ) ) {
				as_enqueue_async_action(
					'shopwalk_seo_bulk_process_item',
					array( (int) $product_id, $state ),
					self::GROUP
				);
			} elseif ( function_exists( 'as_schedule_single_action' ) ) {
				as_schedule_single_action(
					time() + 1,
					'shopwalk_seo_bulk_process_item',
					array( (int) $product_id, $state ),
					self::GROUP
				);
			}
		}

		$state['enqueued'] += count( $ids );
		$state['page']      = $page + 1;
		update_option( self::OPTION_STATE, $state, false );

		// Schedule the next page.
		self::schedule_kickoff( $page + 1 );
	}

	/**
	 * Process a single product. Called by Action Scheduler.
	 *
	 * @param int   $product_id Product to process.
	 * @param array $state      Snapshot of the bulk-run state at enqueue time.
	 */
	public static function process_item( int $product_id, array $state ): void {
		$fields          = isset( $state['fields'] ) && is_array( $state['fields'] ) ? $state['fields'] : array();
		$overwrite_alt   = ! empty( $state['overwrite_alt'] );
		$focus_keyphrase = isset( $state['focus_keyphrase'] ) ? (string) $state['focus_keyphrase'] : '';

		$result = Shopwalk_Seo_Generator::generate( $product_id, $fields, $focus_keyphrase, $overwrite_alt );

		$live = get_option( self::OPTION_STATE, array() );
		if ( ! is_array( $live ) ) {
			$live = array();
		}

		if ( empty( $result['ok'] ) ) {
			$live['errors'] = ( $live['errors'] ?? 0 ) + 1;
			update_option( self::OPTION_STATE, $live, false );
			return;
		}

		$apply_options = array(
			'meta_title'       => in_array( 'meta_title', $fields, true ),
			'meta_description' => in_array( 'meta_description', $fields, true ),
			'focus_keyphrase'  => in_array( 'focus_keyphrase', $fields, true ),
			'image_alt'        => in_array( 'image_alt', $fields, true ),
			'overwrite_alt'    => $overwrite_alt,
		);

		Shopwalk_Seo_Generator::apply( $product_id, $result['data'] ?? array(), $apply_options );

		$live['completed'] = ( $live['completed'] ?? 0 ) + 1;
		update_option( self::OPTION_STATE, $live, false );
	}

	/**
	 * Translate a scope into WP_Query / meta_query and return the next
	 * page of product ids.
	 *
	 * @param array $state Bulk-run state.
	 * @param int   $page  1-indexed page.
	 * @return int[]
	 */
	public static function query_product_ids( array $state, int $page ): array {
		$args = array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'fields'         => 'ids',
			'posts_per_page' => self::PAGE_SIZE,
			'paged'          => $page,
			'orderby'        => 'ID',
			'order'          => 'ASC',
		);

		switch ( $state['scope'] ?? 'all' ) {
			case 'category':
				$cat_id = (int) ( $state['category_id'] ?? 0 );
				if ( $cat_id > 0 ) {
					$args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery
						array(
							'taxonomy' => 'product_cat',
							'field'    => 'term_id',
							'terms'    => $cat_id,
						),
					);
				}
				break;

			case 'missing_meta_title':
				$key               = Shopwalk_Seo_Conflict_Detector::field_key( 'title' );
				$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery
					'relation' => 'OR',
					array(
						'key'     => $key,
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => $key,
						'value'   => '',
						'compare' => '=',
					),
				);
				break;

			case 'missing_meta_desc':
				$key               = Shopwalk_Seo_Conflict_Detector::field_key( 'description' );
				$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery
					'relation' => 'OR',
					array(
						'key'     => $key,
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => $key,
						'value'   => '',
						'compare' => '=',
					),
				);
				break;

			case 'missing_alt':
				// "missing alt" is hard to express as a WP_Query meta filter
				// on the *product* (the alt lives on the attachment). We use
				// a hook so callers and tests can plug in the real query
				// without forcing every bulk run to walk every attachment.
				$ids = apply_filters( 'shopwalk_seo_missing_alt_product_ids', null, $page, self::PAGE_SIZE );
				if ( is_array( $ids ) ) {
					return array_map( 'intval', $ids );
				}
				// Fallback: walk all products and let the per-item handler
				// be a no-op for products whose images already have alt
				// text (overwrite_alt=false respects this).
				break;
		}

		$query = function_exists( 'get_posts' ) ? get_posts( $args ) : array();
		return is_array( $query ) ? array_map( 'intval', $query ) : array();
	}

	/**
	 * Cancel the in-flight bulk run. Removes the state option and
	 * unschedules any queued Action Scheduler jobs for our group.
	 */
	public static function cancel(): void {
		delete_option( self::OPTION_STATE );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'shopwalk_seo_bulk_kickoff', array(), self::GROUP );
			as_unschedule_all_actions( 'shopwalk_seo_bulk_process_item', array(), self::GROUP );
		}
	}

	/**
	 * Read the current bulk-run state. Returns an empty array when nothing
	 * is in flight.
	 *
	 * @return array
	 */
	public static function state(): array {
		$state = get_option( self::OPTION_STATE, array() );
		return is_array( $state ) ? $state : array();
	}
}

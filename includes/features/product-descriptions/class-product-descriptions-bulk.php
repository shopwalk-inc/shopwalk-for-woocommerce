<?php
/**
 * Shopwalk_Product_Descriptions_Bulk — Action Scheduler bulk runner.
 *
 * Enqueues one AS task per product (group `shopwalk-bulk-generation`,
 * hook `shopwalk_bulk_generate_descriptions`). Tracks per-job progress in
 * the `shopwalk_pd_bulk_jobs` option keyed by job_id.
 *
 * @package ShopwalkWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shopwalk_Product_Descriptions_Bulk — bulk orchestration.
 */
final class Shopwalk_Product_Descriptions_Bulk {

	public const JOBS_OPTION  = 'shopwalk_pd_bulk_jobs';
	public const JOB_TTL_DAYS = 30;

	/**
	 * Generator instance (injectable for tests).
	 */
	private Shopwalk_Product_Descriptions_Generator $generator;

	/**
	 * Constructor.
	 *
	 * @param Shopwalk_Product_Descriptions_Generator|null $generator Optional injected generator.
	 */
	public function __construct( ?Shopwalk_Product_Descriptions_Generator $generator = null ) {
		$this->generator = $generator ?? new Shopwalk_Product_Descriptions_Generator();
	}

	/**
	 * Resolve a product-id list from a scope descriptor. Supported scopes:
	 *
	 *  - all                  — every published product
	 *  - category:<term_id>   — products in a category term
	 *  - tag:<term_id>        — products with a tag term
	 *  - empty                — products with empty long description
	 *  - shorter_than:<chars> — products with long description shorter than N chars
	 *
	 * @param string $scope Scope descriptor.
	 * @return array<int>
	 */
	public function resolve_products( string $scope ): array {
		$args = array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'fields'         => 'ids',
			'posts_per_page' => -1,
		);

		if ( str_starts_with( $scope, 'category:' ) ) {
			$args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy' => 'product_cat',
					'field'    => 'term_id',
					'terms'    => (int) substr( $scope, strlen( 'category:' ) ),
				),
			);
		} elseif ( str_starts_with( $scope, 'tag:' ) ) {
			$args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy' => 'product_tag',
					'field'    => 'term_id',
					'terms'    => (int) substr( $scope, strlen( 'tag:' ) ),
				),
			);
		}

		$ids = function_exists( 'get_posts' ) ? (array) get_posts( $args ) : array();
		$ids = array_map( 'intval', $ids );

		if ( 'empty' === $scope ) {
			$ids = array_values( array_filter( $ids, fn( int $pid ): bool => '' === trim( (string) get_post_field( 'post_content', $pid ) ) ) );
		} elseif ( str_starts_with( $scope, 'shorter_than:' ) ) {
			$threshold = (int) substr( $scope, strlen( 'shorter_than:' ) );
			$ids       = array_values( array_filter(
				$ids,
				static function ( int $pid ) use ( $threshold ): bool {
					return strlen( (string) get_post_field( 'post_content', $pid ) ) < $threshold;
				}
			) );
		}

		return $ids;
	}

	/**
	 * Enqueue a bulk job. Creates one Action Scheduler action per product
	 * in the `shopwalk-bulk-generation` group. Returns the synthetic job_id
	 * that the merchant uses to query progress.
	 *
	 * @param array<int>          $product_ids Products to enqueue.
	 * @param array<string,mixed> $options     Per-job generation options (forwarded to the generator).
	 * @return string Job id.
	 */
	public function enqueue_products( array $product_ids, array $options = array() ): string {
		$product_ids = array_values( array_unique( array_map( 'intval', $product_ids ) ) );
		$job_id      = 'pdbg_' . substr( md5( uniqid( 'sw', true ) ), 0, 12 );

		// Persist a job descriptor so the panel can render progress without
		// scanning Action Scheduler tables (those scans are slow on large
		// stores). The AS rows remain the source of truth for retry state.
		$this->update_job(
			$job_id,
			array(
				'job_id'    => $job_id,
				'total'     => count( $product_ids ),
				'completed' => 0,
				'failed'    => 0,
				'options'   => $options,
				'started_at'=> time(),
				'mode'      => (string) ( $options['mode'] ?? 'review_queue' ),
				'status'    => 'running',
			)
		);

		$base_ts = time() + 2;
		foreach ( $product_ids as $i => $pid ) {
			if ( function_exists( 'as_schedule_single_action' ) ) {
				as_schedule_single_action(
					$base_ts + $i, // stagger to ride concurrency throttle
					Shopwalk_Product_Descriptions_Feature::AS_HOOK_BULK,
					array( $pid, $options, $job_id ),
					Shopwalk_Product_Descriptions_Feature::AS_GROUP
				);
			}
		}

		return $job_id;
	}

	/**
	 * Run a single per-product generation. Called by the AS handler.
	 *
	 * @param int                  $product_id Product to generate for.
	 * @param array<string,mixed>  $options    Job options.
	 * @param string               $job_id     Parent job ID.
	 * @return void
	 */
	public function run_one( int $product_id, array $options, string $job_id ): void {
		$mode   = (string) ( $options['mode'] ?? 'review_queue' );
		$result = $this->generator->generate( $product_id, $options );

		if ( ! ( $result['ok'] ?? false ) ) {
			$this->increment_job( $job_id, 'failed' );
			return;
		}

		if ( 'auto_save' === $mode ) {
			$this->generator->apply( $product_id, (array) ( $result['body'] ?? array() ) );
		} else {
			// Review-queue mode: stash result under postmeta for the
			// merchant to inspect from the panel before applying.
			update_post_meta(
				$product_id,
				'_shopwalk_description_pending_review',
				array(
					'job_id'      => $job_id,
					'generated_at'=> time(),
					'result'      => (array) ( $result['body'] ?? array() ),
				)
			);
		}

		$this->increment_job( $job_id, 'completed' );
	}

	/**
	 * Persist a job descriptor.
	 *
	 * @param string              $job_id Job ID.
	 * @param array<string,mixed> $patch  Fields to merge into the descriptor.
	 * @return void
	 */
	private function update_job( string $job_id, array $patch ): void {
		$jobs = (array) get_option( self::JOBS_OPTION, array() );
		if ( ! isset( $jobs[ $job_id ] ) || ! is_array( $jobs[ $job_id ] ) ) {
			$jobs[ $job_id ] = array();
		}
		$jobs[ $job_id ] = array_merge( $jobs[ $job_id ], $patch );

		// Prune jobs older than JOB_TTL_DAYS — keeps the option from
		// growing unbounded on high-volume stores.
		$cutoff = time() - ( self::JOB_TTL_DAYS * DAY_IN_SECONDS );
		foreach ( $jobs as $jid => $job ) {
			if ( (int) ( $job['started_at'] ?? 0 ) < $cutoff ) {
				unset( $jobs[ $jid ] );
			}
		}
		update_option( self::JOBS_OPTION, $jobs, false );
	}

	/**
	 * Increment a counter (`completed` or `failed`) on a job descriptor.
	 *
	 * @param string $job_id Job ID.
	 * @param string $field  Counter field name.
	 * @return void
	 */
	private function increment_job( string $job_id, string $field ): void {
		$jobs = (array) get_option( self::JOBS_OPTION, array() );
		if ( ! isset( $jobs[ $job_id ] ) ) {
			return;
		}
		$jobs[ $job_id ][ $field ] = (int) ( $jobs[ $job_id ][ $field ] ?? 0 ) + 1;

		$total       = (int) ( $jobs[ $job_id ]['total'] ?? 0 );
		$completed   = (int) ( $jobs[ $job_id ]['completed'] ?? 0 );
		$failed      = (int) ( $jobs[ $job_id ]['failed'] ?? 0 );
		if ( ( $completed + $failed ) >= $total ) {
			$jobs[ $job_id ]['status']      = 'complete';
			$jobs[ $job_id ]['finished_at'] = time();
		}

		update_option( self::JOBS_OPTION, $jobs, false );
	}

	/**
	 * Fetch a job descriptor for the panel's progress poll.
	 *
	 * @param string $job_id Job ID.
	 * @return array<string,mixed>|null
	 */
	public function get_job( string $job_id ): ?array {
		$jobs = (array) get_option( self::JOBS_OPTION, array() );
		return isset( $jobs[ $job_id ] ) && is_array( $jobs[ $job_id ] ) ? $jobs[ $job_id ] : null;
	}

	/**
	 * Cancel a running job. Unschedules pending AS actions tagged with the
	 * job_id and marks the descriptor `cancelled`.
	 *
	 * @param string $job_id Job ID.
	 * @return void
	 */
	public function cancel( string $job_id ): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			// AS doesn't index by arbitrary args natively; cancel-all on
			// the hook is the simplest robust path. Bulk jobs are run in
			// distinct windows so collateral cancellation is acceptable.
			as_unschedule_all_actions( Shopwalk_Product_Descriptions_Feature::AS_HOOK_BULK );
		}
		$this->update_job( $job_id, array( 'status' => 'cancelled', 'finished_at' => time() ) );
	}
}

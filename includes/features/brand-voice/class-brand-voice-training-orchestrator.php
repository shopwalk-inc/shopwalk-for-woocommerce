<?php
/**
 * Shopwalk_Brand_Voice_Training_Orchestrator — Action Scheduler job runner
 * for the brand-voice training pipeline.
 *
 * Why Action Scheduler and not the synchronous AJAX path:
 *
 *   - The corpus can run to dozens of batches; uploading inline from an
 *     admin AJAX request would block the merchant's browser for minutes
 *     and time out under any non-trivial corpus.
 *   - AS is already a hard dependency of WooCommerce (8.0+), so we don't
 *     add a new dependency to ship this.
 *   - AS retries failed actions automatically with exponential backoff,
 *     which is what we want for transient shopwalk-api hiccups.
 *
 * Two AS hook names used by this feature (all under group `shopwalk-brand-voice`):
 *
 *   - `shopwalk_brand_voice_upload_batch`   single-shot per batch; args:
 *                                            { batch_index, total_batches,
 *                                              chunks_serialized, job_id }
 *   - `shopwalk_brand_voice_poll_status`    recurring every 30s after the
 *                                            last upload; args: { job_id }.
 *                                            Self-cancels when terminal.
 *
 * The orchestrator is stateless across AS invocations — every job carries
 * everything it needs in its args. The only WP-state we persist between
 * jobs is the active `job_id` (option `shopwalk_brand_voice_active_job_id`)
 * so the admin UI can show "training in progress" across page loads.
 *
 * @package ShopwalkWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shopwalk_Brand_Voice_Training_Orchestrator — AS job runner.
 */
final class Shopwalk_Brand_Voice_Training_Orchestrator {

	/** WP option storing the current job_id during an active training run. */
	public const OPTION_ACTIVE_JOB = 'shopwalk_brand_voice_active_job_id';

	/** AS hook name — uploads one batch of corpus chunks. */
	public const HOOK_UPLOAD = 'shopwalk_brand_voice_upload_batch';

	/** AS hook name — polls the training status endpoint every 30s. */
	public const HOOK_POLL = 'shopwalk_brand_voice_poll_status';

	/** AS group name — used to scope hooks for ops "cancel all" / queryability. */
	public const AS_GROUP = 'shopwalk-brand-voice';

	/** Poll interval in seconds. Matches the spec ("plugin polls every 30s"). */
	public const POLL_INTERVAL_SECONDS = 30;

	/** Hard cap on training-job duration; orchestrator gives up after this. */
	public const MAX_TRAINING_SECONDS = 30 * MINUTE_IN_SECONDS;

	/**
	 * Kick off a training run. Splits the corpus into batches, enqueues a
	 * one-shot upload job per batch, and schedules the recurring status poll.
	 *
	 * Returns an array suitable for an AJAX response.
	 *
	 * @return array{ok:bool, error?:string, total_batches?:int, word_count?:int}
	 */
	public static function start(): array {
		// Defense in depth — refuse to start if the corpus is too small. The
		// admin UI also gates the "Train" button on this; this is the back-up.
		$word_count = Shopwalk_Brand_Voice_Corpus_Manager::total_word_count();
		if ( $word_count < Shopwalk_Brand_Voice_Corpus_Manager::MIN_WORD_COUNT ) {
			return array(
				'ok'    => false,
				'error' => sprintf(
					/* translators: 1: current word count, 2: minimum required */
					__( 'Corpus too small: %1$d words (need %2$d).', 'shopwalk-for-woocommerce' ),
					$word_count,
					Shopwalk_Brand_Voice_Corpus_Manager::MIN_WORD_COUNT
				),
			);
		}

		if ( ! function_exists( 'as_enqueue_async_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return array(
				'ok'    => false,
				'error' => __( 'Action Scheduler unavailable. Ensure WooCommerce 8.0+ is active.', 'shopwalk-for-woocommerce' ),
			);
		}

		// Cancel any previously-stuck run before starting a new one. The
		// merchant explicitly clicked Train — we honor that over a zombie
		// half-state from a prior aborted training run.
		self::cancel_pending();

		$chunks = Shopwalk_Brand_Voice_Corpus_Manager::chunk();
		if ( count( $chunks ) === 0 ) {
			return array(
				'ok'    => false,
				'error' => __( 'No approved corpus to train on.', 'shopwalk-for-woocommerce' ),
			);
		}

		$batch_size    = Shopwalk_Brand_Voice_Corpus_Manager::CHUNKS_PER_BATCH;
		$batches       = array_chunk( $chunks, $batch_size );
		$total_batches = count( $batches );

		// Snapshot the corpus hash NOW so the cross-feature `_set_ready`
		// records what this training run was actually trained against.
		$corpus_hash = Shopwalk_Brand_Voice_Corpus_Manager::corpus_hash();

		// Clear any prior active-job marker; the first upload batch (which
		// has no job_id) will populate it once the server returns one.
		delete_option( self::OPTION_ACTIVE_JOB );
		update_option( 'shopwalk_brand_voice_training_corpus_hash', $corpus_hash );
		update_option( 'shopwalk_brand_voice_training_started_at', time() );

		Shopwalk_Brand_Voice::_set_training();

		// Enqueue one async action per batch. We stagger by 2 seconds to
		// avoid swamping shopwalk-api on multi-MB corpora; AS will fire them
		// in order regardless, but spacing protects shared workers.
		$delay = 0;
		foreach ( $batches as $i => $batch_chunks ) {
			as_schedule_single_action(
				time() + $delay,
				self::HOOK_UPLOAD,
				array(
					array(
						'batch_index'       => (int) $i,
						'total_batches'     => $total_batches,
						// Serialize so AS's args column (jsontext) doesn't
						// fight us on nested utf-8 in the chunks.
						'chunks_serialized' => wp_json_encode( $batch_chunks ),
					),
				),
				self::AS_GROUP
			);
			$delay += 2;
		}

		// Schedule the status poll starting once the last batch should be in.
		// First fire 30s after the last batch's submission window.
		as_schedule_recurring_action(
			time() + max( 30, $delay + 30 ),
			self::POLL_INTERVAL_SECONDS,
			self::HOOK_POLL,
			array(),
			self::AS_GROUP
		);

		return array(
			'ok'            => true,
			'total_batches' => $total_batches,
			'word_count'    => $word_count,
		);
	}

	/**
	 * AS callback — uploads one batch.
	 *
	 * @param array $payload See start() for shape.
	 */
	public static function handle_upload_batch( array $payload ): void {
		$batch_index   = isset( $payload['batch_index'] ) ? (int) $payload['batch_index'] : 0;
		$total_batches = isset( $payload['total_batches'] ) ? (int) $payload['total_batches'] : 1;
		$chunks_raw    = isset( $payload['chunks_serialized'] ) ? (string) $payload['chunks_serialized'] : '[]';
		$chunks        = json_decode( $chunks_raw, true );
		if ( ! is_array( $chunks ) ) {
			$chunks = array();
		}

		// Batch 0 has no job_id (server creates it); subsequent batches
		// inherit the job_id the server returned from batch 0.
		$job_id = (string) get_option( self::OPTION_ACTIVE_JOB, '' );
		if ( 0 === $batch_index ) {
			$job_id = '';
		}

		$result = Shopwalk_Brand_Voice_API_Client::upload_batch(
			array(
				'batch_index'   => $batch_index,
				'total_batches' => $total_batches,
				'chunks'        => $chunks,
				'job_id'        => '' === $job_id ? null : $job_id,
			)
		);

		if ( ! $result['ok'] ) {
			// AS will retry this action on the next pass. If we're past the
			// retry budget, fail the run gracefully.
			$started = (int) get_option( 'shopwalk_brand_voice_training_started_at', 0 );
			if ( $started > 0 && time() - $started > self::MAX_TRAINING_SECONDS ) {
				Shopwalk_Brand_Voice::_set_failed(
					isset( $result['error'] ) ? (string) $result['error'] : 'upload failed'
				);
				self::cancel_pending();
			}
			// Re-throw so AS records the failure and retries.
			throw new RuntimeException( esc_html( 'brand-voice upload failed: ' . ( $result['error'] ?? 'unknown' ) ) );
		}

		// Capture server-assigned job_id on batch 0.
		if ( 0 === $batch_index && isset( $result['body']['job_id'] ) ) {
			update_option( self::OPTION_ACTIVE_JOB, (string) $result['body']['job_id'] );
		}
	}

	/**
	 * AS callback — polls the status endpoint and transitions cross-feature
	 * state when training reaches a terminal state.
	 */
	public static function handle_poll_status(): void {
		$job_id = (string) get_option( self::OPTION_ACTIVE_JOB, '' );
		if ( '' === $job_id ) {
			// No active job — orphaned recurring poll. Cancel ourselves.
			self::cancel_poll();
			return;
		}

		// Bail out if we've exceeded the max training duration.
		$started = (int) get_option( 'shopwalk_brand_voice_training_started_at', 0 );
		if ( $started > 0 && time() - $started > self::MAX_TRAINING_SECONDS ) {
			Shopwalk_Brand_Voice::_set_failed( 'Training timed out.' );
			self::cancel_pending();
			return;
		}

		$result = Shopwalk_Brand_Voice_API_Client::fetch_status( $job_id );
		if ( ! $result['ok'] ) {
			// Transient — let AS poll again in 30s. Only flip to failed if
			// the server explicitly said failed.
			return;
		}

		$status = isset( $result['body']['status'] ) ? (string) $result['body']['status'] : 'training';

		if ( 'failed' === $status ) {
			$error = isset( $result['body']['error'] ) ? (string) $result['body']['error'] : 'unknown error';
			Shopwalk_Brand_Voice::_set_failed( $error );
			self::cancel_pending();
			return;
		}

		if ( 'ready' === $status ) {
			$voice_id = isset( $result['body']['voice_id'] ) ? (string) $result['body']['voice_id'] : '';
			if ( '' === $voice_id ) {
				// Server said ready but no voice_id — treat as transient and re-poll.
				return;
			}
			$profile_resp = Shopwalk_Brand_Voice_API_Client::fetch_profile();
			$profile      = $profile_resp['ok'] && is_array( $profile_resp['body'] )
				? $profile_resp['body']
				: array( 'voice_id' => $voice_id, 'trained_at' => gmdate( 'c' ) );

			$corpus_hash = (string) get_option( 'shopwalk_brand_voice_training_corpus_hash', '' );
			Shopwalk_Brand_Voice::_set_ready( $voice_id, $profile, $corpus_hash );
			self::cancel_pending();
			return;
		}

		// status is "training" — leave the recurring poll alone, AS will
		// fire us again in 30s.
	}

	/**
	 * Cancel any pending uploads + the recurring poll. Idempotent.
	 */
	public static function cancel_pending(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK_UPLOAD, array(), self::AS_GROUP );
			as_unschedule_all_actions( self::HOOK_POLL, array(), self::AS_GROUP );
		}
		delete_option( self::OPTION_ACTIVE_JOB );
		delete_option( 'shopwalk_brand_voice_training_corpus_hash' );
		delete_option( 'shopwalk_brand_voice_training_started_at' );
	}

	/**
	 * Cancel only the recurring poll (used when we discover an orphaned poll
	 * with no active job_id).
	 */
	public static function cancel_poll(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK_POLL, array(), self::AS_GROUP );
		}
	}
}

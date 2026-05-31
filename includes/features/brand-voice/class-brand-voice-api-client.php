<?php
/**
 * Shopwalk_Brand_Voice_API_Client — talks to shopwalk-api's brand-voice
 * training endpoints.
 *
 * Endpoints (canonical spec, mirrored in PR body for the api-side agent):
 *
 *   POST /api/v1/plugin/ai/brand-voice/train
 *     Request body (JSON):
 *       {
 *         "partner_id": "uuid",
 *         "job_id": "uuid|null",          // omit on batch 0; required from batch 1
 *         "batch_index": 0,
 *         "total_batches": 12,
 *         "corpus_chunks": [
 *           {"source":"post_id:123","text":"..."},
 *           ...
 *         ]
 *       }
 *     Response (200):
 *       { "job_id": "uuid", "accepted_chunks": N }
 *
 *   GET /api/v1/plugin/ai/brand-voice/status?job_id=...
 *     Response:
 *       {
 *         "status": "training"|"ready"|"failed",
 *         "voice_id": "uuid"|null,
 *         "progress_pct": 0-100,
 *         "error": "..."   // present only when status=failed
 *       }
 *
 *   GET /api/v1/plugin/ai/brand-voice/profile
 *     Response:
 *       {
 *         "voice_id": "uuid",
 *         "trained_at": "iso8601",
 *         "sample_output": "...",
 *         "corpus_summary": { "word_count": N, "doc_count": N }
 *       }
 *
 *   DELETE /api/v1/plugin/ai/brand-voice/profile
 *     Server-side delete of the merchant's trained voice + stored samples.
 *     Called by the "Reset brand voice" admin action.
 *
 * Auth on every call:
 *   Authorization: Bearer <license_key>
 *   X-Shopwalk-HMAC: <hex-encoded sha256( request_body, license_key )>
 *
 * The HMAC defends against on-path body tampering even when TLS termination
 * sits in front of shopwalk-api (load balancer, CDN). For GET / DELETE
 * requests with no body we sign the canonical "METHOD\nPATH\nQUERY" string.
 *
 * Class is intentionally a private implementation detail of the brand-voice
 * feature — downstream features must NOT call this directly. Use the public
 * cross-feature interface in `class-brand-voice-cross-feature.php`.
 *
 * @package ShopwalkWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shopwalk_Brand_Voice_API_Client — thin wrapper around wp_remote_*.
 */
final class Shopwalk_Brand_Voice_API_Client {

	/** HTTP timeout for training-side calls. Backend is async; we never block on training. */
	private const TIMEOUT_SECONDS = 30;

	/**
	 * Upload one batch of corpus chunks to the training endpoint.
	 *
	 * @param array{
	 *   batch_index:int,
	 *   total_batches:int,
	 *   chunks: list<array{source:string,text:string}>,
	 *   job_id: ?string,
	 * } $batch
	 * @return array{ok:bool, status:int, body: array<string,mixed>, error?:string}
	 */
	public static function upload_batch( array $batch ): array {
		$body = array(
			'partner_id'    => self::partner_id(),
			'batch_index'   => (int) $batch['batch_index'],
			'total_batches' => (int) $batch['total_batches'],
			'corpus_chunks' => array_values( $batch['chunks'] ),
		);
		// `job_id` is omitted on batch 0 (server creates it) and required on
		// every subsequent batch so the server can append to the right job.
		if ( ! empty( $batch['job_id'] ) ) {
			$body['job_id'] = (string) $batch['job_id'];
		}

		return self::request( 'POST', '/plugin/ai/brand-voice/train', $body );
	}

	/**
	 * Poll training status by job_id.
	 *
	 * @return array{ok:bool, status:int, body: array<string,mixed>, error?:string}
	 */
	public static function fetch_status( string $job_id ): array {
		return self::request( 'GET', '/plugin/ai/brand-voice/status', null, array( 'job_id' => $job_id ) );
	}

	/**
	 * Fetch the trained voice profile (with sample_output preview).
	 *
	 * @return array{ok:bool, status:int, body: array<string,mixed>, error?:string}
	 */
	public static function fetch_profile(): array {
		return self::request( 'GET', '/plugin/ai/brand-voice/profile', null );
	}

	/**
	 * Delete the merchant's trained voice + stored source samples server-side.
	 *
	 * @return array{ok:bool, status:int, body: array<string,mixed>, error?:string}
	 */
	public static function delete_profile(): array {
		return self::request( 'DELETE', '/plugin/ai/brand-voice/profile', null );
	}

	// ── Internals ─────────────────────────────────────────────────────────

	/**
	 * Send a signed HTTP request to shopwalk-api.
	 *
	 * @param string                     $method  HTTP method.
	 * @param string                     $path    API path (relative to /api/v1, leading slash required).
	 * @param array<string,mixed>|null   $body    JSON body (null for no body).
	 * @param array<string,string>       $query   Query-string parameters.
	 * @return array{ok:bool, status:int, body: array<string,mixed>, error?:string}
	 */
	private static function request( string $method, string $path, ?array $body = null, array $query = array() ): array {
		if ( ! class_exists( 'Shopwalk_License' ) ) {
			return array( 'ok' => false, 'status' => 0, 'body' => array(), 'error' => 'tier-2 not loaded' );
		}
		$license = Shopwalk_License::key();
		if ( '' === $license ) {
			return array( 'ok' => false, 'status' => 0, 'body' => array(), 'error' => 'no license key' );
		}

		$url = self::api_base() . $path;
		if ( ! empty( $query ) ) {
			$url .= '?' . http_build_query( $query );
		}

		$json_body = null === $body ? '' : (string) wp_json_encode( $body );

		// Canonical signing string:
		//   POST + body          → sign the JSON body
		//   GET / DELETE no body → sign "METHOD\nPATH\nQUERY" so URL params
		//                          are also covered by the HMAC.
		$signing_payload = '' !== $json_body
			? $json_body
			: $method . "\n" . $path . "\n" . ( empty( $query ) ? '' : http_build_query( $query ) );
		$hmac            = hash_hmac( 'sha256', $signing_payload, $license );

		$args = array(
			'method'  => $method,
			'timeout' => self::TIMEOUT_SECONDS,
			'headers' => array(
				'Authorization'    => 'Bearer ' . $license,
				'X-Shopwalk-HMAC'  => $hmac,
				'Content-Type'     => 'application/json',
				'Accept'           => 'application/json',
				'User-Agent'       => 'shopwalk-for-woocommerce-plugin/' . WOOCOMMERCE_SHOPWALK_VERSION,
				'X-Shopwalk-Site'  => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
			),
		);
		if ( '' !== $json_body ) {
			$args['body'] = $json_body;
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'     => false,
				'status' => 0,
				'body'   => array(),
				'error'  => $response->get_error_message(),
			);
		}

		$status     = (int) wp_remote_retrieve_response_code( $response );
		$body_raw   = (string) wp_remote_retrieve_body( $response );
		$body_array = array();
		if ( '' !== $body_raw ) {
			$decoded = json_decode( $body_raw, true );
			if ( is_array( $decoded ) ) {
				$body_array = $decoded;
			}
		}

		$ok  = $status >= 200 && $status < 300;
		$out = array(
			'ok'     => $ok,
			'status' => $status,
			'body'   => $body_array,
		);
		if ( ! $ok ) {
			$out['error'] = isset( $body_array['error'] )
				? (string) $body_array['error']
				: ( 'HTTP ' . $status );
		}
		return $out;
	}

	/**
	 * Returns the partner UUID this license is bound to. Defensive against
	 * the license class returning empty strings (e.g. during early bootstrap).
	 */
	private static function partner_id(): string {
		if ( ! class_exists( 'Shopwalk_License' ) ) {
			return '';
		}
		$id = Shopwalk_License::partner_id();
		return is_string( $id ) ? $id : '';
	}

	/**
	 * Base URL — uses the same constant the rest of the plugin uses so the
	 * dev-loop override (`SHOPWALK_API_BASE=http://localhost:8080/api/v1`)
	 * applies here too.
	 */
	private static function api_base(): string {
		return defined( 'SHOPWALK_API_BASE' ) ? (string) SHOPWALK_API_BASE : 'https://api.shopwalk.com/api/v1';
	}
}

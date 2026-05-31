<?php
/**
 * Shopwalk_Catalog_Sync_API_Client — outbound HTTP to shopwalk-api.
 *
 * Calls:
 *   - POST {SHOPWALK_API_BASE}/plugin/sync/batch   (products)
 *   - POST {SHOPWALK_API_BASE}/plugin/orders/batch (orders)
 *
 * Auth (per `partner/api/README.md` Layer 1):
 *   - `Authorization: Bearer <license_key>`
 *   - `X-Shopwalk-HMAC: <hex sha256-hmac of request body keyed by the license key>`
 *
 * Returns a normalized envelope so callers don't have to differentiate
 * between WP_Error (transport) and HTTP error (status >= 400).
 *
 * @package ShopwalkWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shopwalk_Catalog_Sync_API_Client — thin HTTP wrapper.
 */
final class Shopwalk_Catalog_Sync_API_Client {

	/**
	 * HTTP timeout for sync requests. Bigger than interactive calls because
	 * batches can be wide, smaller than infinity so a hung backend doesn't
	 * wedge the AS runner.
	 */
	private const HTTP_TIMEOUT_SECONDS = 30;

	/**
	 * Send a product batch.
	 *
	 * @param array $batch Batch envelope from the batcher.
	 * @return array{ok:bool,status?:int,error?:string,accepted?:int,rejected?:int,errors?:array}
	 */
	public function send_products_batch( array $batch ): array {
		return $this->post( $this->endpoint( '/plugin/sync/batch' ), $batch );
	}

	/**
	 * Send an order batch.
	 *
	 * @param array $batch Batch envelope from the batcher.
	 * @return array{ok:bool,status?:int,error?:string,accepted?:int,rejected?:int,errors?:array}
	 */
	public function send_orders_batch( array $batch ): array {
		return $this->post( $this->endpoint( '/plugin/orders/batch' ), $batch );
	}

	/**
	 * Compute the HMAC for a request body.
	 *
	 * Algorithm: SHA-256 HMAC, hex-encoded, keyed by the license key,
	 * over the exact bytes of the JSON-encoded body sent on the wire.
	 *
	 * Public + static so the unit tests can pin the contract without
	 * having to instantiate the client + mock HTTP.
	 *
	 * @param string $body        Raw JSON body.
	 * @param string $license_key License key (the shared secret).
	 * @return string Hex-encoded HMAC.
	 */
	public static function compute_hmac( string $body, string $license_key ): string {
		return hash_hmac( 'sha256', $body, $license_key );
	}

	// ─── Internals ──────────────────────────────────────────────────────

	/**
	 * Build the full URL for an API path. SHOPWALK_API_BASE already
	 * carries `/api/v1` so we just append the path.
	 *
	 * @param string $path Path segment (must start with `/`).
	 * @return string
	 */
	private function endpoint( string $path ): string {
		$base = defined( 'SHOPWALK_API_BASE' )
			? (string) constant( 'SHOPWALK_API_BASE' )
			: 'https://api.shopwalk.com/api/v1';
		return rtrim( $base, '/' ) . '/' . ltrim( $path, '/' );
	}

	/**
	 * POST a JSON body with license + HMAC auth, normalize the result.
	 *
	 * @param string $url  Full URL.
	 * @param array  $body Payload to JSON-encode.
	 * @return array{ok:bool,status?:int,error?:string,accepted?:int,rejected?:int,errors?:array}
	 */
	private function post( string $url, array $body ): array {
		$license_key = $this->license_key();
		if ( '' === $license_key ) {
			return array(
				'ok'    => false,
				'error' => 'no_license_key',
			);
		}

		$json = function_exists( 'wp_json_encode' )
			? wp_json_encode( $body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
			: json_encode( $body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $json ) {
			return array(
				'ok'    => false,
				'error' => 'json_encode_failed',
			);
		}

		$hmac = self::compute_hmac( (string) $json, $license_key );
		$ua   = 'shopwalk-for-woocommerce-plugin/' . ( defined( 'WOOCOMMERCE_SHOPWALK_VERSION' ) ? WOOCOMMERCE_SHOPWALK_VERSION : '0.0.0' );

		$args = array(
			'method'  => 'POST',
			'timeout' => self::HTTP_TIMEOUT_SECONDS,
			'headers' => array(
				'Content-Type'    => 'application/json',
				'Accept'          => 'application/json',
				'Authorization'   => 'Bearer ' . $license_key,
				'X-Shopwalk-HMAC' => $hmac,
				'User-Agent'      => $ua,
			),
			'body'    => $json,
		);

		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Allow tests / integrations to short-circuit the HTTP call by
			 * returning a pre-baked response array. Mirrors WP core's
			 * `pre_http_request` pattern but scoped to this feature so we
			 * don't accidentally intercept unrelated traffic.
			 */
			$pre = apply_filters( 'shopwalk_catalog_sync_pre_http', null, $url, $args );
			if ( null !== $pre ) {
				return $this->normalize_response( $pre );
			}
			$args = (array) apply_filters( 'shopwalk_catalog_sync_http_args', $args, $url );
		}

		$response = function_exists( 'wp_remote_request' )
			? wp_remote_request( $url, $args )
			: new WP_Error( 'no_http', 'wp_remote_request unavailable' );

		return $this->normalize_response( $response );
	}

	/**
	 * Normalize a WP HTTP response (or WP_Error) into the result envelope.
	 *
	 * @param mixed $response WP_Error|array.
	 * @return array{ok:bool,status?:int,error?:string,accepted?:int,rejected?:int,errors?:array}
	 */
	private function normalize_response( $response ): array {
		if ( function_exists( 'is_wp_error' ) && is_wp_error( $response ) ) {
			return array(
				'ok'    => false,
				'error' => 'transport: ' . (string) $response->get_error_message(),
			);
		}
		if ( ! is_array( $response ) ) {
			return array(
				'ok'    => false,
				'error' => 'unexpected_response_shape',
			);
		}

		$status = function_exists( 'wp_remote_retrieve_response_code' )
			? (int) wp_remote_retrieve_response_code( $response )
			: (int) ( $response['response']['code'] ?? 0 );
		$body   = function_exists( 'wp_remote_retrieve_body' )
			? (string) wp_remote_retrieve_body( $response )
			: (string) ( $response['body'] ?? '' );

		$decoded = '' === $body ? array() : json_decode( $body, true );
		if ( ! is_array( $decoded ) ) {
			$decoded = array();
		}

		if ( $status >= 200 && $status < 300 ) {
			return array(
				'ok'       => true,
				'status'   => $status,
				'accepted' => (int) ( $decoded['accepted'] ?? 0 ),
				'rejected' => (int) ( $decoded['rejected'] ?? 0 ),
				'errors'   => (array) ( $decoded['errors'] ?? array() ),
			);
		}

		$msg = isset( $decoded['error'] ) ? (string) $decoded['error']
			: ( isset( $decoded['message'] ) ? (string) $decoded['message'] : 'http_error' );

		return array(
			'ok'     => false,
			'status' => $status,
			'error'  => $msg,
		);
	}

	/**
	 * Resolve the license key. Prefers the foundation's helper.
	 *
	 * @return string
	 */
	private function license_key(): string {
		if ( class_exists( 'Shopwalk_License' ) && method_exists( 'Shopwalk_License', 'key' ) ) {
			$key = (string) Shopwalk_License::key();
			if ( '' !== $key ) {
				return $key;
			}
		}
		return (string) get_option( 'shopwalk_license_key', '' );
	}
}

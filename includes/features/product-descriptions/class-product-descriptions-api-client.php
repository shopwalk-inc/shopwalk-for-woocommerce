<?php
/**
 * Shopwalk_Product_Descriptions_Api_Client — outbound call to shopwalk-api.
 *
 * Wraps `POST /api/v1/plugin/ai/descriptions/generate`. Authenticated with
 * the merchant's license key (Bearer) and an HMAC-SHA256 signature of the
 * request body so the backend can verify integrity + replay-protect the
 * call.
 *
 * @package ShopwalkWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shopwalk_Product_Descriptions_Api_Client — wire-level client.
 */
final class Shopwalk_Product_Descriptions_Api_Client {

	public const ENDPOINT_PATH = '/plugin/ai/descriptions/generate';

	/**
	 * Build the signed headers + body for a generation request.
	 *
	 * Exposed (vs private) so the unit tests can exercise HMAC signing
	 * without a full HTTP round-trip.
	 *
	 * @param array<string,mixed> $payload The request payload.
	 * @param string              $license_key The merchant's license key.
	 * @param string              $signing_secret The HMAC signing secret.
	 * @param int                 $timestamp Unix ts (defaults to now). Exposed for test determinism.
	 * @return array{headers:array<string,string>,body:string}
	 */
	public static function build_signed_request( array $payload, string $license_key, string $signing_secret, int $timestamp = 0 ): array {
		if ( $timestamp <= 0 ) {
			$timestamp = time();
		}
		$body = wp_json_encode( $payload );
		if ( ! is_string( $body ) ) {
			$body = '{}';
		}

		// Sign `<timestamp>.<body>` so replay windows can be enforced
		// without ambiguity. shopwalk-api validates a 5-minute window.
		$signing_input = $timestamp . '.' . $body;
		$hmac          = hash_hmac( 'sha256', $signing_input, $signing_secret );

		return array(
			'headers' => array(
				'Content-Type'      => 'application/json',
				'Authorization'     => 'Bearer ' . $license_key,
				'X-Shopwalk-HMAC'   => $hmac,
				'X-Shopwalk-Ts'     => (string) $timestamp,
				'User-Agent'        => 'shopwalk-for-woocommerce-plugin/' . ( defined( 'WOOCOMMERCE_SHOPWALK_VERSION' ) ? WOOCOMMERCE_SHOPWALK_VERSION : '0.0.0' ),
			),
			'body'    => $body,
		);
	}

	/**
	 * Execute a generation request synchronously. Suitable for the
	 * per-product UI (typical latency 4-8s). Bulk callers should go
	 * through Shopwalk_Product_Descriptions_Bulk so each call runs in
	 * its own Action Scheduler task.
	 *
	 * @param array<string,mixed> $payload Generation request payload.
	 * @return array{ok:bool, status:int, body:array<string,mixed>, error?:string}
	 */
	public function generate( array $payload ): array {
		$license_key = $this->license_key();
		if ( '' === $license_key ) {
			return array(
				'ok'     => false,
				'status' => 0,
				'body'   => array(),
				'error'  => 'no_license',
			);
		}

		$signed = self::build_signed_request( $payload, $license_key, $this->signing_secret() );

		$url      = ( defined( 'SHOPWALK_API_BASE' ) ? SHOPWALK_API_BASE : 'https://api.shopwalk.com/api/v1' ) . self::ENDPOINT_PATH;
		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 30,
				'headers' => $signed['headers'],
				'body'    => $signed['body'],
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'     => false,
				'status' => 0,
				'body'   => array(),
				'error'  => $response->get_error_message(),
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = (string) wp_remote_retrieve_body( $response );
		$body   = json_decode( $raw, true );
		if ( ! is_array( $body ) ) {
			$body = array();
		}

		if ( $status >= 300 ) {
			return array(
				'ok'     => false,
				'status' => $status,
				'body'   => $body,
				'error'  => (string) ( $body['message'] ?? ( 'HTTP ' . $status ) ),
			);
		}

		return array(
			'ok'     => true,
			'status' => $status,
			'body'   => $body,
		);
	}

	/**
	 * Resolve the merchant's license key. Prefers Shopwalk_License::key()
	 * when the foundation class is loaded, falls back to the WP option.
	 *
	 * @return string
	 */
	private function license_key(): string {
		if ( class_exists( 'Shopwalk_License' ) && method_exists( 'Shopwalk_License', 'key' ) ) {
			return (string) call_user_func( array( 'Shopwalk_License', 'key' ) );
		}
		return (string) get_option( 'shopwalk_license_key', '' );
	}

	/**
	 * Resolve the HMAC signing secret. Prefers UCP_Signing::store_secret()
	 * (already used for outbound webhook signing), falls back to a
	 * derived secret based on the license key so unit tests can exercise
	 * the signing path without the full UCP bootstrap.
	 *
	 * @return string
	 */
	private function signing_secret(): string {
		if ( class_exists( 'UCP_Signing' ) && method_exists( 'UCP_Signing', 'store_secret' ) ) {
			$secret = (string) call_user_func( array( 'UCP_Signing', 'store_secret' ) );
			if ( '' !== $secret ) {
				return $secret;
			}
		}
		return 'sw-signing:' . $this->license_key();
	}
}

<?php
/**
 * Shopwalk_ACP_Client — thin wrapper around the shopwalk-api ACP partner
 * endpoints.
 *
 * Per `platform/SHOPWALK_ACP_INTEGRATION.md` §5 (post-spec-revision), there is
 * no separate ACP opt-in dance — Free/Pro merchants are opted in by virtue of
 * connecting to Shopwalk. The plugin only needs `status` and `pause` calls.
 *
 * The shopwalk-api ACP routes live under `/partners/v1/acp/*` rather than the
 * versioned `/api/v1/` prefix used by license endpoints — `SHOPWALK_API_BASE`
 * carries `/api/v1` baked in, so this client derives the host root from it and
 * rebuilds the URL.
 *
 * Authentication uses the same `X-API-Key: <license_key>` header that
 * Shopwalk_License uses.
 *
 * @package ShopwalkWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shopwalk_ACP_Client — pause / status calls into shopwalk-api.
 */
final class Shopwalk_ACP_Client {

	/**
	 * Reasonable HTTP timeout for interactive pause / status calls.
	 */
	private const HTTP_TIMEOUT_SECONDS = 15;

	/**
	 * Derive the shopwalk-api host root from SHOPWALK_API_BASE
	 * (which carries `/api/v1`). Returns e.g. `https://api.shopwalk.com`.
	 */
	private static function host_root(): string {
		$base = (string) SHOPWALK_API_BASE;
		$pos  = strpos( $base, '/api/' );
		if ( false === $pos ) {
			return rtrim( $base, '/' );
		}
		return rtrim( substr( $base, 0, $pos ), '/' );
	}

	/**
	 * Build the full URL for an ACP partner route, e.g. `/partners/v1/acp/pause`.
	 */
	private static function url( string $path ): string {
		$path = '/' . ltrim( $path, '/' );
		return self::host_root() . $path;
	}

	/**
	 * POST /partners/v1/acp/pause — toggle the merchant's ACP pause state.
	 *
	 * @param bool $paused True to pause, false to resume.
	 * @return array{ok:bool,message:string,status?:string}
	 */
	public static function set_paused( bool $paused ): array {
		$key = self::license_key();
		if ( '' === $key ) {
			return array(
				'ok'      => false,
				'message' => __( 'No Shopwalk license configured.', 'shopwalk-for-woocommerce' ),
			);
		}

		$response = wp_remote_post(
			self::url( '/partners/v1/acp/pause' ),
			array(
				'timeout' => self::HTTP_TIMEOUT_SECONDS,
				'headers' => array(
					'Content-Type' => 'application/json',
					'X-API-Key'    => $key,
					'User-Agent'   => 'shopwalk-for-woocommerce-plugin/' . WOOCOMMERCE_SHOPWALK_VERSION,
				),
				'body'    => wp_json_encode(
					array(
						'paused'   => $paused,
						'site_url' => home_url(),
					)
				),
			)
		);

		return self::interpret( $response, __( 'ACP pause toggle failed.', 'shopwalk-for-woocommerce' ) );
	}

	/**
	 * GET /partners/v1/acp/status — current ACP opt-in state, feed item count,
	 * and any active moderation flags for this merchant. Returns
	 * `ok=false, status_code=404` for partners that aren't ACP-eligible
	 * (typically unlicensed / disconnected stores).
	 *
	 * @return array{
	 *   ok:bool,
	 *   message:string,
	 *   status?:string,
	 *   status_code?:int,
	 *   payment_compat?:string,
	 *   feed_item_count?:int,
	 *   moderation_flags?:array<int,array<string,mixed>>
	 * }
	 */
	public static function status(): array {
		$key = self::license_key();
		if ( '' === $key ) {
			return array(
				'ok'          => false,
				'message'     => __( 'No Shopwalk license configured.', 'shopwalk-for-woocommerce' ),
				'status_code' => 0,
			);
		}

		$response = wp_remote_get(
			self::url( '/partners/v1/acp/status' ),
			array(
				'timeout' => self::HTTP_TIMEOUT_SECONDS,
				'headers' => array(
					'X-API-Key'  => $key,
					'User-Agent' => 'shopwalk-for-woocommerce-plugin/' . WOOCOMMERCE_SHOPWALK_VERSION,
				),
			)
		);

		return self::interpret( $response, __( 'ACP status fetch failed.', 'shopwalk-for-woocommerce' ) );
	}

	/**
	 * Common response interpretation — turns the wp_remote_* result into a
	 * `{ok, message, status_code, ...body}` array.
	 *
	 * @param mixed  $response       Result of wp_remote_get/post.
	 * @param string $generic_error  Fallback message when no API error is present.
	 * @return array<string,mixed>
	 */
	private static function interpret( $response, string $generic_error ): array {
		if ( is_wp_error( $response ) ) {
			return array(
				'ok'          => false,
				'message'     => $response->get_error_message(),
				'status_code' => 0,
			);
		}
		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$body        = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			$body = array();
		}

		if ( $status_code >= 300 ) {
			$message = isset( $body['message'] ) && is_string( $body['message'] )
				? $body['message']
				: $generic_error . ' (HTTP ' . $status_code . ')';
			return array(
				'ok'          => false,
				'message'     => $message,
				'status_code' => $status_code,
			);
		}

		return array_merge(
			array(
				'ok'          => true,
				'message'     => '',
				'status_code' => $status_code,
			),
			$body
		);
	}

	/**
	 * Read the configured license key, or '' if missing. Loaded indirectly so
	 * the file doesn't hard-fail in tests that haven't loaded Shopwalk_License.
	 */
	private static function license_key(): string {
		if ( class_exists( 'Shopwalk_License' ) ) {
			return Shopwalk_License::key();
		}
		return (string) get_option( 'shopwalk_license_key', '' );
	}
}

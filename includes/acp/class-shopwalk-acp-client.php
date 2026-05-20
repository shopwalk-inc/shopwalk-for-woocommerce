<?php
/**
 * Shopwalk_ACP_Client — thin wrapper around the shopwalk-api ACP partner
 * endpoints (Agent A in the ACP build plan).
 *
 * The shopwalk-api ACP routes live under `/partners/v1/acp/*` rather than the
 * versioned `/api/v1/` prefix used by license endpoints — `SHOPWALK_API_BASE`
 * carries `/api/v1` baked in, so this client derives the host root from it
 * and rebuilds the URL.
 *
 * Authentication uses the same `X-API-Key: <license_key>` header that
 * Shopwalk_License uses; the shopwalk-api validates the key against the
 * partners table and resolves partner_id server-side.
 *
 * Per `feedback_keep_ai_wiring_for_revival` we use the existing wp_remote_*
 * HTTP path; no new parallel HTTP client.
 *
 * @package ShopwalkWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shopwalk_ACP_Client — opt-in / pause / status calls into shopwalk-api.
 */
final class Shopwalk_ACP_Client {

	/**
	 * Current ToS addendum version. Bumped when `tos-vN.html` is replaced
	 * with a substantively-different version; opt-in submissions carry this
	 * value so shopwalk-api can require re-acknowledgement on a future bump.
	 */
	public const TOS_VERSION = 'v1';

	/**
	 * Reasonable HTTP timeout for interactive opt-in / pause / status calls.
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
	 * Build the full URL for an ACP partner route, e.g. `/partners/v1/acp/opt-in`.
	 */
	private static function url( string $path ): string {
		$path = '/' . ltrim( $path, '/' );
		return self::host_root() . $path;
	}

	/**
	 * POST /partners/v1/acp/opt-in — merchant accepts the ACP ToS addendum.
	 *
	 * @param string $payment_compat One of "full" (Stripe/WooPayments configured)
	 *                                or "deep_link" (no compatible gateway).
	 * @return array{ok:bool,message:string,status?:string}
	 */
	public static function opt_in( string $payment_compat ): array {
		$key = self::license_key();
		if ( '' === $key ) {
			return array(
				'ok'      => false,
				'message' => __( 'No Shopwalk license configured. Connect to Shopwalk before enabling ACP.', 'shopwalk-for-woocommerce' ),
			);
		}

		$response = wp_remote_post(
			self::url( '/partners/v1/acp/opt-in' ),
			array(
				'timeout' => self::HTTP_TIMEOUT_SECONDS,
				'headers' => array(
					'Content-Type' => 'application/json',
					'X-API-Key'    => $key,
					'User-Agent'   => 'shopwalk-for-woocommerce-plugin/' . WOOCOMMERCE_SHOPWALK_VERSION,
				),
				'body'    => wp_json_encode(
					array(
						'tos_version'    => self::TOS_VERSION,
						'payment_compat' => $payment_compat,
						'site_url'       => home_url(),
					)
				),
			)
		);

		return self::interpret( $response, __( 'ACP opt-in failed.', 'shopwalk-for-woocommerce' ) );
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
	 * GET /partners/v1/acp/status — current ACP opt-in state, feed item
	 * count, and any active moderation flags for this merchant.
	 *
	 * @return array{
	 *   ok:bool,
	 *   message:string,
	 *   status?:string,
	 *   tos_version?:string,
	 *   payment_compat?:string,
	 *   feed_item_count?:int,
	 *   moderation_flags?:array<int,array<string,mixed>>
	 * }
	 */
	public static function status(): array {
		$key = self::license_key();
		if ( '' === $key ) {
			return array(
				'ok'      => false,
				'message' => __( 'No Shopwalk license configured.', 'shopwalk-for-woocommerce' ),
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
	 * `{ok, message, ...body}` array. Keeps the per-endpoint handlers terse.
	 *
	 * @param mixed  $response       Result of wp_remote_get/post.
	 * @param string $generic_error  Fallback message when no API error is present.
	 * @return array<string,mixed>
	 */
	private static function interpret( $response, string $generic_error ): array {
		if ( is_wp_error( $response ) ) {
			return array(
				'ok'      => false,
				'message' => $response->get_error_message(),
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
				'ok'      => false,
				'message' => $message,
			);
		}

		return array_merge(
			array(
				'ok'      => true,
				'message' => '',
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

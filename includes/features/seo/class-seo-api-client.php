<?php
/**
 * Shopwalk_Seo_Api_Client — wraps POST /api/v1/plugin/ai/seo/generate.
 *
 * Auth: license key as Bearer + HMAC-SHA256 of the request body as
 * `X-Shopwalk-HMAC` (signed with the store's signing secret). Mirrors the
 * pattern already used by sync + descriptions calls — see
 * `class-shopwalk-license.php` and `class-ucp-signing.php` for the
 * underlying primitives.
 *
 * Wire shape (per the v1.0 SEO spec — fields inferred where the partner
 * spec was silent are marked with @inferred):
 *
 *   Request: {
 *     "partner_id":      "<uuid>",                         // from Shopwalk_License
 *     "product_id":      <int>,                            // WC product id
 *     "fields":          ["meta_title","meta_description", // selected fields
 *                         "image_alt","seo_checklist"],
 *     "image_urls":      ["https://..."],                  // attached images
 *     "focus_keyphrase": "<string|null>"                   // optional hint
 *
 *     // @inferred — context the backend needs to produce product-aware text
 *     "product_title":       "<string>",
 *     "product_description": "<string>",
 *     "product_short_desc":  "<string>",
 *     "product_attributes":  {<slug>: <value|values[]>},
 *     "product_categories":  ["<slug>", ...],
 *     "product_sku":         "<string>",
 *     "site_locale":         "en_US",
 *     "overwrite_alt":       <bool>                        // bulk option
 *   }
 *
 *   Response: {
 *     "meta_title":       "<string>",                       // ≤60 char target
 *     "meta_description": "<string>",                       // ≤155 char target
 *     "image_alts":       {"<image_id_or_url>": "<alt>"},   // per-image
 *     "seo_checklist":    [{"item":"...", "status":"ok|warn|fail", "fix":"..."}],
 *     "tokens_used":      <int>,
 *
 *     // @inferred — partner-side recommended additions
 *     "focus_keyphrase":  "<string|null>",
 *     "og_title":         "<string|null>",
 *     "og_description":   "<string|null>"
 *   }
 *
 * Network errors and non-2xx responses are normalized to an `ok=false`
 * result with a `message` — the meta box JS shows that message verbatim.
 *
 * @package ShopwalkWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shopwalk_Seo_Api_Client — outbound calls to Shopwalk for SEO generation.
 */
final class Shopwalk_Seo_Api_Client {

	/**
	 * Endpoint path appended to SHOPWALK_API_BASE.
	 */
	private const ENDPOINT = '/plugin/ai/seo/generate';

	/**
	 * HTTP timeout in seconds. Spec calls out 3–5s typical latency; we leave
	 * generous headroom for vision-model calls on alt-text-heavy requests.
	 */
	private const TIMEOUT = 30;

	/**
	 * Run the generate call.
	 *
	 * @param array $payload Pre-built request payload (see class header).
	 * @return array {ok:bool, message:string, data?:array, http?:int}
	 */
	public static function generate( array $payload ): array {
		if ( ! class_exists( 'Shopwalk_License' ) || ! Shopwalk_License::is_valid() ) {
			return array(
				'ok'      => false,
				'message' => __( 'A valid Shopwalk license is required.', 'shopwalk-for-woocommerce' ),
			);
		}

		$license_key = Shopwalk_License::key();
		$partner_id  = Shopwalk_License::partner_id();
		if ( '' === $partner_id ) {
			return array(
				'ok'      => false,
				'message' => __( 'partner_id missing — reconnect the plugin.', 'shopwalk-for-woocommerce' ),
			);
		}

		// Ensure partner_id is always present on the wire even if a caller
		// forgot to set it.
		$payload['partner_id'] = $partner_id;

		$body = wp_json_encode( $payload );
		if ( false === $body ) {
			return array(
				'ok'      => false,
				'message' => __( 'Failed to encode SEO request payload.', 'shopwalk-for-woocommerce' ),
			);
		}

		$signing_secret = class_exists( 'UCP_Signing' )
			? UCP_Signing::store_secret()
			: '';
		$hmac           = '' !== $signing_secret
			? hash_hmac( 'sha256', $body, $signing_secret )
			: '';

		$response = wp_remote_post(
			SHOPWALK_API_BASE . self::ENDPOINT,
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array(
					'Content-Type'    => 'application/json',
					'Authorization'   => 'Bearer ' . $license_key,
					'X-Shopwalk-HMAC' => $hmac,
					'User-Agent'      => 'shopwalk-for-woocommerce-plugin/' . WOOCOMMERCE_SHOPWALK_VERSION,
				),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'      => false,
				'message' => $response->get_error_message(),
			);
		}

		$http = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			$data = array();
		}

		if ( $http >= 300 ) {
			$msg = isset( $data['message'] ) && is_string( $data['message'] )
				? $data['message']
				: sprintf( /* translators: %d: HTTP status code */ __( 'Shopwalk API returned HTTP %d', 'shopwalk-for-woocommerce' ), $http );
			return array(
				'ok'      => false,
				'http'    => $http,
				'message' => $msg,
				'data'    => $data,
			);
		}

		return array(
			'ok'   => true,
			'http' => $http,
			'data' => $data,
		);
	}
}

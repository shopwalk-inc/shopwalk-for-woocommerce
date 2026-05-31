<?php
/**
 * API client for the Shopwalk recommendations endpoint.
 *
 * Wraps the single outbound call to
 *   POST https://api.shopwalk.com/api/v1/plugin/ai/recommendations
 *
 * Wire shape (matches partner/api/README.md):
 *   Authorization: Bearer <license_key>
 *   X-Shopwalk-HMAC: <sha256 hmac over body using license key>
 *   X-Shopwalk-Timestamp: <unix seconds, included in HMAC input>
 *   X-Shopwalk-Partner-ID: <partner uuid>
 *
 * Request body:
 *   {
 *     "partner_id":         "uuid",
 *     "type":               "also_viewed|related|fbt|personalized",
 *     "context_product_id": 123,
 *     "user_id":            456,        // optional
 *     "count":              6
 *   }
 *
 * Response body:
 *   { "product_ids": [int,...], "fallback": bool, "tokens_used": int }
 *
 * Caching: results are wrapped in a 5-minute transient keyed by
 * (partner_id, type, context_product_id, user_id, count). The cache is
 * intentionally short — recommendations are stale-tolerant per the spec
 * (5-minute TTL) and we re-fetch eagerly when stock changes invalidate
 * the slot (out-of-stock demotion is handled server-side, but the cache
 * key includes count so a different count gets a different cache).
 *
 * @package ShopwalkWooCommerce\Features\Recommendations
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stateless HTTP client. All public methods are static so callers can
 * invoke without singleton plumbing.
 */
final class Shopwalk_Recommendations_API_Client {

	/**
	 * Transient TTL (seconds). 5 minutes per spec.
	 */
	private const CACHE_TTL = 300;

	/**
	 * Outbound HTTP timeout (seconds). We aim for ~80ms p95 server-side
	 * but allow a generous ceiling so transient network blips don't fail
	 * the slot.
	 */
	private const HTTP_TIMEOUT = 5;

	/**
	 * Endpoint path appended to SHOPWALK_API_BASE.
	 */
	private const ENDPOINT_PATH = '/plugin/ai/recommendations';

	/**
	 * Fetch a recommendations payload, hitting cache first.
	 *
	 * @param string   $type       One of also_viewed|related|fbt|personalized.
	 * @param int      $product_id Context product id. 0 allowed for
	 *                             "personalized" when a user_id is set.
	 * @param int      $count      Number of items requested. Server may
	 *                             return fewer if it can't satisfy.
	 * @param int|null $user_id    Optional WC customer id for
	 *                             personalization.
	 * @return array{
	 *   ok:bool,
	 *   product_ids:array<int,int>,
	 *   fallback:bool,
	 *   tokens_used:int,
	 *   from_cache:bool,
	 *   message?:string
	 * }
	 */
	public static function fetch( string $type, int $product_id, int $count, ?int $user_id = null ): array {
		$type  = self::normalize_type( $type );
		$count = max( 1, min( 24, $count ) );

		$partner_id = (string) get_option( 'shopwalk_partner_id', '' );
		if ( '' === $partner_id ) {
			return self::error( 'no_partner_id', 'No partner_id is configured. Connect to Shopwalk first.' );
		}
		$license_key = (string) get_option( 'shopwalk_license_key', '' );
		if ( '' === $license_key ) {
			return self::error( 'no_license', 'No Shopwalk license key is configured.' );
		}

		$cache_key = self::cache_key( $partner_id, $type, $product_id, $user_id, $count );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			$cached['from_cache'] = true;
			return $cached;
		}

		$payload = array(
			'partner_id'         => $partner_id,
			'type'               => $type,
			'context_product_id' => $product_id,
			'count'              => $count,
		);
		if ( null !== $user_id && $user_id > 0 ) {
			$payload['user_id'] = $user_id;
		}

		// Allow themes / integrations to mutate the request before send.
		$payload = apply_filters( 'shopwalk_recommendations_query_payload', $payload );

		$body      = wp_json_encode( $payload );
		$timestamp = (string) time();
		$signature = hash_hmac( 'sha256', $timestamp . '.' . $body, $license_key );

		$base = defined( 'SHOPWALK_API_BASE' ) ? SHOPWALK_API_BASE : 'https://api.shopwalk.com/api/v1';
		$url  = $base . self::ENDPOINT_PATH;

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => self::HTTP_TIMEOUT,
				'headers' => array(
					'Content-Type'          => 'application/json',
					'Accept'                => 'application/json',
					'Authorization'         => 'Bearer ' . $license_key,
					'X-Shopwalk-HMAC'       => $signature,
					'X-Shopwalk-Timestamp'  => $timestamp,
					'X-Shopwalk-Partner-ID' => $partner_id,
					'User-Agent'            => 'shopwalk-for-woocommerce-plugin/' . ( defined( 'WOOCOMMERCE_SHOPWALK_VERSION' ) ? WOOCOMMERCE_SHOPWALK_VERSION : '0.0.0' ),
				),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return self::error( 'http_error', $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		if ( $code >= 300 ) {
			$decoded = json_decode( $raw, true );
			$msg     = is_array( $decoded ) && isset( $decoded['message'] ) ? (string) $decoded['message'] : 'Shopwalk API returned HTTP ' . $code;
			return self::error( 'http_' . $code, $msg, $code );
		}

		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return self::error( 'bad_response', 'Recommendations API returned a non-JSON body.' );
		}

		$ids = array();
		if ( isset( $decoded['product_ids'] ) && is_array( $decoded['product_ids'] ) ) {
			foreach ( $decoded['product_ids'] as $maybe_id ) {
				$as_int = (int) $maybe_id;
				if ( $as_int > 0 ) {
					$ids[] = $as_int;
				}
			}
		}

		$result = array(
			'ok'          => true,
			'product_ids' => $ids,
			'fallback'    => (bool) ( $decoded['fallback'] ?? false ),
			'tokens_used' => (int) ( $decoded['tokens_used'] ?? 0 ),
			'from_cache'  => false,
		);

		// Allow integrations to post-process the result before cache.
		$result = apply_filters( 'shopwalk_recommendations_results', $result, $payload );

		set_transient( $cache_key, $result, self::CACHE_TTL );

		return $result;
	}

	/**
	 * Bust the cached payload for a (type, product_id) tuple across all
	 * count + user variants. Called by the order / stock change handlers
	 * when they want fresh recommendations next request.
	 *
	 * Because transients don't support wildcard delete portably, this
	 * helper bumps a per-product version counter that's appended to the
	 * cache key — old keys naturally expire after 5 minutes.
	 *
	 * @param int $product_id Context product id.
	 * @return void
	 */
	public static function invalidate( int $product_id ): void {
		if ( $product_id <= 0 ) {
			return;
		}
		$current = (int) get_option( 'shopwalk_recs_cache_version_' . $product_id, 0 );
		update_option( 'shopwalk_recs_cache_version_' . $product_id, $current + 1, false );
	}

	/**
	 * Normalize the requested type to one the server recognises. Unknown
	 * types fall through to "related" — the safest default.
	 *
	 * @param string $type Incoming type.
	 * @return string
	 */
	private static function normalize_type( string $type ): string {
		$type = strtolower( trim( $type ) );
		$ok   = array( 'also_viewed', 'related', 'fbt', 'personalized' );
		if ( in_array( $type, $ok, true ) ) {
			return $type;
		}
		// Accept a couple of common synonyms from shortcode authors.
		$aliases = array(
			'cart_upsell'  => 'fbt',
			'related_products' => 'related',
			'co_viewed'    => 'also_viewed',
		);
		return $aliases[ $type ] ?? 'related';
	}

	/**
	 * Build the cache key. Includes the per-product version counter so
	 * invalidate() is effective without scanning all transients.
	 *
	 * @param string   $partner_id Partner uuid.
	 * @param string   $type       Recommendation type.
	 * @param int      $product_id Context product id.
	 * @param int|null $user_id    Optional user id.
	 * @param int      $count      Requested count.
	 * @return string
	 */
	private static function cache_key( string $partner_id, string $type, int $product_id, ?int $user_id, int $count ): string {
		$version = (int) get_option( 'shopwalk_recs_cache_version_' . $product_id, 0 );
		$user    = null === $user_id ? '0' : (string) $user_id;
		// Transient names are capped at 172 chars by WP — hash to stay safe.
		return 'sw_recs_' . substr( md5( $partner_id . '|' . $type . '|' . $product_id . '|' . $user . '|' . $count . '|' . $version ), 0, 32 );
	}

	/**
	 * Build a structured error result.
	 *
	 * @param string $code        Error code.
	 * @param string $message     Human readable message.
	 * @param int    $status_code Optional HTTP status code.
	 * @return array<string,mixed>
	 */
	private static function error( string $code, string $message, int $status_code = 0 ): array {
		return array(
			'ok'          => false,
			'code'        => $code,
			'message'     => $message,
			'status_code' => $status_code,
			'product_ids' => array(),
			'fallback'    => false,
			'tokens_used' => 0,
			'from_cache'  => false,
		);
	}
}

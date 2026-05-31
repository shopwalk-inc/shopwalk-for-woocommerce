<?php
/**
 * Semantic_Search_API_Client — thin wrapper around the shopwalk-api semantic
 * search endpoint (`POST /api/v1/plugin/ai/search`).
 *
 * Auth follows the v1.0 AI-feature wire contract:
 *   Authorization: Bearer <license_key>
 *   X-Shopwalk-HMAC: <sha256(body, signing_secret)>
 *
 * (Sister AI endpoints can migrate to OAuth Bearer later — they share the same
 * signing secret + license-key bootstrap, so flipping auth modes is a header
 * change inside this client, not a caller change.)
 *
 * Hard-timeout at 800ms per request: if the backend doesn't return a ranked
 * set in that window the storefront falls back to native WP search and the
 * shopper sees zero added latency. Slow upstream != broken storefront.
 *
 * @package ShopwalkWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Semantic_Search_API_Client — POST /api/v1/plugin/ai/search wrapper.
 */
final class Semantic_Search_API_Client {

	/**
	 * Outer wp_remote_post timeout (seconds). The spec budget is 800ms; we
	 * pick 1s as a hard cap so transient blips don't error before the
	 * fallback path can engage. The "is the upstream too slow" check is
	 * applied separately on the elapsed wall-clock — see ::search().
	 */
	private const HTTP_TIMEOUT_SECONDS = 1;

	/**
	 * Soft latency budget. When a search call exceeds this many milliseconds
	 * the result is dropped and the storefront falls back to native WP search.
	 * 800ms = the upper bound declared in the task spec.
	 */
	private const SOFT_LATENCY_BUDGET_MS = 800;

	/**
	 * Per-(query, partner_id, scope) transient TTL in seconds.
	 */
	public const CACHE_TTL_SECONDS = 60;

	/**
	 * Run a semantic search. Returns a normalized result envelope or null on
	 * failure (signaling the caller to fall back to native WP search).
	 *
	 * @param string        $query     The shopper's raw query string.
	 * @param array<int,string> $scope Subset of {product,post,page}.
	 * @param int           $limit     Max post IDs to return.
	 *
	 * @return array{product_ids:int[],post_ids:int[],page_ids:int[],tokens_used:int}|null
	 */
	public static function search( string $query, array $scope, int $limit ): ?array {
		$query = trim( $query );
		if ( '' === $query ) {
			return null;
		}

		$partner_id = self::partner_id();
		if ( '' === $partner_id ) {
			return null;
		}

		$license_key = self::license_key();
		if ( '' === $license_key ) {
			return null;
		}

		$cache_key = self::cache_key( $query, $partner_id, $scope, $limit );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$body = wp_json_encode(
			array(
				'partner_id'        => $partner_id,
				'query'             => $query,
				'scope'             => array_values( $scope ),
				'limit'             => $limit,
				'synonym_dict_hash' => self::synonym_dict_hash(),
			)
		);
		if ( ! is_string( $body ) ) {
			return null;
		}

		$signature = self::sign( $body );
		$started   = microtime( true );

		$response = wp_remote_post(
			self::endpoint_url(),
			array(
				'timeout' => self::HTTP_TIMEOUT_SECONDS,
				'headers' => array(
					'Content-Type'    => 'application/json',
					'Authorization'   => 'Bearer ' . $license_key,
					'X-Shopwalk-HMAC' => $signature,
					'User-Agent'      => 'shopwalk-for-woocommerce-plugin/' . WOOCOMMERCE_SHOPWALK_VERSION,
				),
				'body'    => $body,
			)
		);

		$elapsed_ms = (int) ( ( microtime( true ) - $started ) * 1000 );

		if ( is_wp_error( $response ) ) {
			self::debug_log( 'semantic search http error: ' . $response->get_error_message() );
			return null;
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			self::debug_log( 'semantic search non-200: ' . $status_code );
			return null;
		}

		if ( $elapsed_ms > self::SOFT_LATENCY_BUDGET_MS ) {
			self::debug_log( 'semantic search exceeded latency budget (' . $elapsed_ms . 'ms) — falling back to native WP search' );
			return null;
		}

		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $decoded ) ) {
			return null;
		}

		$result = array(
			'product_ids' => array_map( 'intval', (array) ( $decoded['product_ids'] ?? array() ) ),
			'post_ids'    => array_map( 'intval', (array) ( $decoded['post_ids'] ?? array() ) ),
			'page_ids'    => array_map( 'intval', (array) ( $decoded['page_ids'] ?? array() ) ),
			'tokens_used' => (int) ( $decoded['tokens_used'] ?? 0 ),
		);

		set_transient( $cache_key, $result, self::CACHE_TTL_SECONDS );
		return $result;
	}

	/**
	 * Cache key for the per-(query, partner_id, scope, limit) transient.
	 *
	 * @internal exposed for tests.
	 *
	 * @param string            $query
	 * @param string            $partner_id
	 * @param array<int,string> $scope
	 * @param int               $limit
	 */
	public static function cache_key( string $query, string $partner_id, array $scope, int $limit ): string {
		$normalized_scope = $scope;
		sort( $normalized_scope );
		$digest = md5( $query . '|' . $partner_id . '|' . implode( ',', $normalized_scope ) . '|' . $limit );
		return 'shopwalk_semsearch_' . $digest;
	}

	/**
	 * Compute the synonym dictionary hash so the backend can detect changes
	 * and rebuild its per-partner synonym index without the plugin having to
	 * call an explicit "re-index" endpoint.
	 *
	 * @internal exposed for tests.
	 */
	public static function synonym_dict_hash(): string {
		$dict = (array) get_option( 'shopwalk_semsearch_synonyms', array() );
		return self::hash_synonym_dict( $dict );
	}

	/**
	 * Pure helper — hash a synonym dictionary. Order- and whitespace-stable so
	 * the hash only changes when the merchant's effective synonym set
	 * changes.
	 *
	 * @param array<int,array<int,string>> $dict Row-of-synonyms list.
	 */
	public static function hash_synonym_dict( array $dict ): string {
		$canonical = array();
		foreach ( $dict as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$cleaned = array();
			foreach ( $row as $term ) {
				$term = strtolower( trim( (string) $term ) );
				if ( '' !== $term ) {
					$cleaned[] = $term;
				}
			}
			if ( count( $cleaned ) < 2 ) {
				continue;
			}
			sort( $cleaned );
			$canonical[] = implode( ',', array_unique( $cleaned ) );
		}
		sort( $canonical );
		return hash( 'sha256', implode( "\n", $canonical ) );
	}

	/**
	 * HMAC-SHA256 of the request body using the plugin's Shopwalk signing
	 * secret (the same secret used by the sync push path).
	 */
	private static function sign( string $body ): string {
		$secret = (string) get_option( 'shopwalk_signing_secret', '' );
		if ( '' === $secret ) {
			// No signing secret yet — return a deterministic placeholder so the
			// backend can reject the call clearly. Real production calls only
			// happen after Shopwalk_License has run the connect flow which
			// provisions the signing secret.
			$secret = 'unprovisioned';
		}
		return hash_hmac( 'sha256', $body, $secret );
	}

	/**
	 * Fully-qualified endpoint URL on shopwalk-api.
	 */
	private static function endpoint_url(): string {
		return rtrim( SHOPWALK_API_BASE, '/' ) . '/plugin/ai/search';
	}

	/**
	 * Read the configured license key. Indirect so tests don't need to load
	 * Shopwalk_License.
	 */
	private static function license_key(): string {
		if ( class_exists( 'Shopwalk_License' ) ) {
			return Shopwalk_License::key();
		}
		return (string) get_option( 'shopwalk_license_key', '' );
	}

	/**
	 * Read the configured partner_id. Indirect for the same reason as
	 * ::license_key().
	 */
	private static function partner_id(): string {
		if ( class_exists( 'Shopwalk_License' ) ) {
			return Shopwalk_License::partner_id();
		}
		return (string) get_option( 'shopwalk_partner_id', '' );
	}

	/**
	 * Debug-only log line. Goes through WP's debug.log; never the storefront.
	 */
	private static function debug_log( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- debug-only diagnostic
			error_log( '[shopwalk-semantic-search] ' . $message );
		}
	}
}

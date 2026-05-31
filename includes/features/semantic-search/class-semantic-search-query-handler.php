<?php
/**
 * Semantic_Search_Query_Handler — intercepts WP search queries for
 * product (and optionally post/page) results and substitutes the
 * Shopwalk-ranked set in their place.
 *
 * Theme transparency: we deliberately do NOT render a Shopwalk results UI.
 * Hooks set `post__in` + `orderby=post__in` (or short-circuit via
 * `posts_pre_query`) so the merchant's theme renders the result set exactly
 * the way it already renders WC product loops.
 *
 * Fallback behavior: any error in the API client (network failure, non-200,
 * timeout) returns null, which is treated as "fall through to native WP
 * search" — the shopper sees zero added latency and never an error.
 *
 * @package ShopwalkWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Semantic_Search_Query_Handler — pre_get_posts / posts_pre_query hook.
 */
final class Semantic_Search_Query_Handler {

	/**
	 * Search mode constants — match the values written by the admin panel.
	 */
	public const MODE_OFF     = 'off';
	public const MODE_AUGMENT = 'augment';
	public const MODE_REPLACE = 'replace';

	/**
	 * Default scope when the merchant hasn't configured anything yet.
	 *
	 * @var string[]
	 */
	private const DEFAULT_SCOPE = array( 'product' );

	/**
	 * Default result count when the merchant hasn't configured anything.
	 */
	private const DEFAULT_LIMIT = 24;

	/**
	 * Memoized per-(query, scope) result so pre_get_posts and posts_pre_query
	 * don't issue duplicate HTTP calls when both fire on the same WP_Query.
	 *
	 * @var array<string,array|null>
	 */
	private array $resolved = array();

	/**
	 * Hook into WP search.
	 */
	public function register(): void {
		add_action( 'pre_get_posts', array( $this, 'on_pre_get_posts' ), 20, 1 );
	}

	/**
	 * Decide whether this WP_Query is in scope and, if so, rewrite it to
	 * carry the Shopwalk-ranked post IDs.
	 *
	 * @param WP_Query $query The WP_Query about to run.
	 */
	public function on_pre_get_posts( $query ): void {
		if ( is_admin() ) {
			return;
		}
		if ( ! $this->is_eligible_query( $query ) ) {
			return;
		}

		$mode = $this->mode();
		if ( self::MODE_OFF === $mode ) {
			return;
		}

		$search_term = (string) $query->get( 's' );
		if ( '' === trim( $search_term ) ) {
			return;
		}

		$scope = $this->scope();
		$limit = $this->limit();

		$result = $this->resolve( $search_term, $scope, $limit );
		if ( null === $result ) {
			// API unreachable / timed out — leave the query untouched so WP's
			// native search runs and the shopper sees results, not an error.
			return;
		}

		$ids = $this->ids_for_query( $query, $result, $scope );
		if ( empty( $ids ) ) {
			if ( self::MODE_REPLACE === $mode ) {
				// Force the empty set so theme renders "no results" cleanly
				// without falling back to MySQL LIKE matching.
				$query->set( 'post__in', array( 0 ) );
			}
			return;
		}

		if ( self::MODE_REPLACE === $mode ) {
			$query->set( 'post__in', $ids );
			$query->set( 'orderby', 'post__in' );
			// Drop the search term so WP doesn't AND a `LIKE %term%` against
			// our explicit ID set — the ranker already decided relevance.
			$query->set( 's', '' );
			return;
		}

		// Augment mode: keep WP's native LIKE results, but bias the top of
		// the page to the Shopwalk-ranked IDs. We do this by injecting
		// post__in OR-ed via a posts_clauses filter; simplest implementation
		// here is to widen post__in to include both native + Shopwalk hits
		// and use post__in ordering for the top slice.
		$native_ids = $this->native_ids_for_query( $query );
		$merged     = $this->merge_for_augment( $ids, $native_ids );
		if ( ! empty( $merged ) ) {
			$query->set( 'post__in', $merged );
			$query->set( 'orderby', 'post__in' );
			$query->set( 's', '' );
		}
	}

	/**
	 * Public for tests — pure decision: is this WP_Query a product (or
	 * in-scope post/page) search that we should rewrite?
	 *
	 * @param WP_Query $query
	 */
	public function is_eligible_query( $query ): bool {
		if ( ! is_object( $query ) || empty( $query->is_search ) ) {
			return false;
		}
		// Only the main query — secondary loops (related products, widgets)
		// don't represent the shopper's search intent.
		if ( method_exists( $query, 'is_main_query' ) && ! $query->is_main_query() ) {
			return false;
		}

		$requested_type = $query->get( 'post_type' );
		$scope          = $this->scope();

		// Search results page may be empty post_type (WP's default), or set
		// to 'product' by themes that hook search to the shop page. We
		// consider the query eligible if any in-scope post type matches.
		if ( empty( $requested_type ) ) {
			return in_array( 'product', $scope, true ) || in_array( 'post', $scope, true );
		}
		$types = (array) $requested_type;
		foreach ( $types as $type ) {
			if ( in_array( $type, $scope, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Pull the configured search mode.
	 */
	public function mode(): string {
		$raw = (string) get_option( 'shopwalk_semsearch_mode', self::MODE_REPLACE );
		return in_array( $raw, array( self::MODE_OFF, self::MODE_AUGMENT, self::MODE_REPLACE ), true )
			? $raw
			: self::MODE_REPLACE;
	}

	/**
	 * Pull the configured scope as an array of post-type slugs.
	 *
	 * @return string[]
	 */
	public function scope(): array {
		$raw = get_option( 'shopwalk_semsearch_scope', self::DEFAULT_SCOPE );
		if ( ! is_array( $raw ) || empty( $raw ) ) {
			return self::DEFAULT_SCOPE;
		}
		$allowed = array( 'product', 'post', 'page' );
		$out     = array();
		foreach ( $raw as $type ) {
			if ( in_array( $type, $allowed, true ) ) {
				$out[] = $type;
			}
		}
		return empty( $out ) ? self::DEFAULT_SCOPE : $out;
	}

	/**
	 * Pull the configured result count.
	 */
	public function limit(): int {
		$raw = (int) get_option( 'shopwalk_semsearch_limit', self::DEFAULT_LIMIT );
		if ( $raw < 1 ) {
			return self::DEFAULT_LIMIT;
		}
		// Hard cap — there's no UI use case for >100 results in a single
		// search response, and an unbounded value would let a misconfigured
		// option run the backend hot.
		return min( $raw, 100 );
	}

	/**
	 * Resolve a query through the API client with per-query memoization so
	 * pre_get_posts and any downstream filter (e.g. posts_pre_query) share
	 * the same network call.
	 *
	 * @param string   $query
	 * @param string[] $scope
	 * @param int      $limit
	 *
	 * @return array|null
	 */
	private function resolve( string $query, array $scope, int $limit ): ?array {
		$key = $query . '|' . implode( ',', $scope ) . '|' . $limit;
		if ( array_key_exists( $key, $this->resolved ) ) {
			return $this->resolved[ $key ];
		}
		$result                 = Semantic_Search_API_Client::search( $query, $scope, $limit );
		$this->resolved[ $key ] = $result;
		return $result;
	}

	/**
	 * Choose the ID slice that matches this WP_Query's requested post type.
	 *
	 * @param WP_Query                                                         $query
	 * @param array{product_ids:int[],post_ids:int[],page_ids:int[]}           $result
	 * @param string[]                                                         $scope
	 *
	 * @return int[]
	 */
	private function ids_for_query( $query, array $result, array $scope ): array {
		$types = $query->get( 'post_type' );
		if ( empty( $types ) ) {
			// Default search page — pull whatever scope says is in play,
			// products first because that's almost always what WC merchants
			// care about.
			$ids = array();
			if ( in_array( 'product', $scope, true ) ) {
				$ids = array_merge( $ids, $result['product_ids'] );
			}
			if ( in_array( 'post', $scope, true ) ) {
				$ids = array_merge( $ids, $result['post_ids'] );
			}
			if ( in_array( 'page', $scope, true ) ) {
				$ids = array_merge( $ids, $result['page_ids'] );
			}
			return array_values( array_unique( array_filter( $ids ) ) );
		}

		$ids = array();
		foreach ( (array) $types as $type ) {
			if ( 'product' === $type ) {
				$ids = array_merge( $ids, $result['product_ids'] );
			} elseif ( 'post' === $type ) {
				$ids = array_merge( $ids, $result['post_ids'] );
			} elseif ( 'page' === $type ) {
				$ids = array_merge( $ids, $result['page_ids'] );
			}
		}
		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * Merge Shopwalk-ranked IDs with WP's native result set for augment
	 * mode. Shopwalk-ranked IDs always come first; native IDs the ranker
	 * didn't return are appended after, preserving WP's native order.
	 *
	 * Pure helper, exposed for tests.
	 *
	 * @param int[] $shopwalk_ids
	 * @param int[] $native_ids
	 *
	 * @return int[]
	 */
	public function merge_for_augment( array $shopwalk_ids, array $native_ids ): array {
		$seen   = array();
		$merged = array();
		foreach ( $shopwalk_ids as $id ) {
			$id = (int) $id;
			if ( $id > 0 && ! isset( $seen[ $id ] ) ) {
				$seen[ $id ] = true;
				$merged[]    = $id;
			}
		}
		foreach ( $native_ids as $id ) {
			$id = (int) $id;
			if ( $id > 0 && ! isset( $seen[ $id ] ) ) {
				$seen[ $id ] = true;
				$merged[]    = $id;
			}
		}
		return $merged;
	}

	/**
	 * Native WP search result IDs for the augment-mode merge. Runs a
	 * one-shot WP_Query with the same search term but stripped of our
	 * post__in injection, to discover what WP would have returned natively.
	 *
	 * Best-effort — if WP_Query isn't available (unit tests), returns [].
	 *
	 * @param WP_Query $query
	 *
	 * @return int[]
	 */
	private function native_ids_for_query( $query ): array {
		if ( ! class_exists( 'WP_Query' ) ) {
			return array();
		}
		$args   = array(
			's'              => (string) $query->get( 's' ),
			'post_type'      => $query->get( 'post_type' ) ?: 'product',
			'posts_per_page' => $this->limit(),
			'fields'         => 'ids',
			'no_found_rows'  => true,
		);
		$native = new WP_Query( $args );
		$ids    = isset( $native->posts ) ? array_map( 'intval', (array) $native->posts ) : array();
		return $ids;
	}
}

<?php
/**
 * Tests for Semantic_Search_API_Client::hash_synonym_dict.
 *
 * The hash is sent on every search request so the backend can detect when
 * the merchant's synonym dictionary has changed and rebuild its per-partner
 * synonym index. It must therefore be:
 *
 * - Stable under row reordering (semantics, not order, define equivalence)
 * - Stable under within-row term reordering (same reason)
 * - Stable under whitespace and case differences (synonyms are tokens, not strings)
 * - Sensitive to actual term changes (any real edit must change the hash)
 *
 * @package ShopwalkWooCommerce
 */

use PHPUnit\Framework\TestCase;

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/../../../' );
defined( 'SHOPWALK_API_BASE' ) || define( 'SHOPWALK_API_BASE', 'https://api.shopwalk.test/api/v1' );
defined( 'WOOCOMMERCE_SHOPWALK_VERSION' ) || define( 'WOOCOMMERCE_SHOPWALK_VERSION', '4.3.0-test' );

require_once __DIR__ . '/../../../includes/features/semantic-search/class-semantic-search-api-client.php';

final class SynonymDictHashTest extends TestCase {

	public function test_empty_dict_produces_stable_hash(): void {
		$this->assertSame(
			Semantic_Search_API_Client::hash_synonym_dict( array() ),
			Semantic_Search_API_Client::hash_synonym_dict( array() )
		);
	}

	public function test_row_order_does_not_change_hash(): void {
		$a = array(
			array( 'running shoes', 'sneakers' ),
			array( 'sofa', 'couch' ),
		);
		$b = array(
			array( 'sofa', 'couch' ),
			array( 'running shoes', 'sneakers' ),
		);
		$this->assertSame(
			Semantic_Search_API_Client::hash_synonym_dict( $a ),
			Semantic_Search_API_Client::hash_synonym_dict( $b )
		);
	}

	public function test_within_row_order_does_not_change_hash(): void {
		$a = array( array( 'running shoes', 'sneakers', 'trainers' ) );
		$b = array( array( 'trainers', 'running shoes', 'sneakers' ) );
		$this->assertSame(
			Semantic_Search_API_Client::hash_synonym_dict( $a ),
			Semantic_Search_API_Client::hash_synonym_dict( $b )
		);
	}

	public function test_whitespace_and_case_normalization(): void {
		$a = array( array( 'Running Shoes', 'SNEAKERS' ) );
		$b = array( array( '  running shoes  ', 'sneakers' ) );
		$this->assertSame(
			Semantic_Search_API_Client::hash_synonym_dict( $a ),
			Semantic_Search_API_Client::hash_synonym_dict( $b )
		);
	}

	public function test_adding_a_new_term_changes_the_hash(): void {
		$before = array( array( 'running shoes', 'sneakers' ) );
		$after  = array( array( 'running shoes', 'sneakers', 'trainers' ) );
		$this->assertNotSame(
			Semantic_Search_API_Client::hash_synonym_dict( $before ),
			Semantic_Search_API_Client::hash_synonym_dict( $after )
		);
	}

	public function test_adding_a_new_row_changes_the_hash(): void {
		$before = array( array( 'running shoes', 'sneakers' ) );
		$after  = array(
			array( 'running shoes', 'sneakers' ),
			array( 'sofa', 'couch' ),
		);
		$this->assertNotSame(
			Semantic_Search_API_Client::hash_synonym_dict( $before ),
			Semantic_Search_API_Client::hash_synonym_dict( $after )
		);
	}

	public function test_rows_with_fewer_than_two_terms_are_ignored(): void {
		// A single-term row isn't a synonym pair; including or excluding it
		// from the dict must produce the same hash so the backend doesn't
		// see spurious changes when the merchant uploads a malformed row.
		$with_singleton = array(
			array( 'running shoes', 'sneakers' ),
			array( 'singleton' ),
		);
		$without        = array(
			array( 'running shoes', 'sneakers' ),
		);
		$this->assertSame(
			Semantic_Search_API_Client::hash_synonym_dict( $with_singleton ),
			Semantic_Search_API_Client::hash_synonym_dict( $without )
		);
	}

	public function test_duplicate_terms_within_a_row_are_collapsed(): void {
		$dupe   = array( array( 'sneakers', 'sneakers', 'sneakers', 'running shoes' ) );
		$single = array( array( 'sneakers', 'running shoes' ) );
		$this->assertSame(
			Semantic_Search_API_Client::hash_synonym_dict( $dupe ),
			Semantic_Search_API_Client::hash_synonym_dict( $single )
		);
	}

	public function test_hash_is_sha256_hex(): void {
		$hash = Semantic_Search_API_Client::hash_synonym_dict( array( array( 'a', 'b' ) ) );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $hash );
	}

	public function test_cache_key_changes_when_query_changes(): void {
		$a = Semantic_Search_API_Client::cache_key( 'shoes', 'p1', array( 'product' ), 24 );
		$b = Semantic_Search_API_Client::cache_key( 'jacket', 'p1', array( 'product' ), 24 );
		$this->assertNotSame( $a, $b );
	}

	public function test_cache_key_is_scope_order_independent(): void {
		$a = Semantic_Search_API_Client::cache_key( 'shoes', 'p1', array( 'product', 'post' ), 24 );
		$b = Semantic_Search_API_Client::cache_key( 'shoes', 'p1', array( 'post', 'product' ), 24 );
		$this->assertSame( $a, $b );
	}

	public function test_cache_key_partitions_by_partner_id(): void {
		$a = Semantic_Search_API_Client::cache_key( 'shoes', 'partner-a', array( 'product' ), 24 );
		$b = Semantic_Search_API_Client::cache_key( 'shoes', 'partner-b', array( 'product' ), 24 );
		$this->assertNotSame( $a, $b );
	}
}

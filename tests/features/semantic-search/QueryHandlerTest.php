<?php
/**
 * Tests for Semantic_Search_Query_Handler.
 *
 * Covers:
 * - mode / scope / limit option reads with safe defaults
 * - eligibility decisions for product / non-product / non-main queries
 * - augment-mode merge ordering (Shopwalk-ranked first, native after)
 *
 * @package ShopwalkWooCommerce
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/../../../' );
defined( 'SHOPWALK_API_BASE' ) || define( 'SHOPWALK_API_BASE', 'https://api.shopwalk.test/api/v1' );
defined( 'WOOCOMMERCE_SHOPWALK_VERSION' ) || define( 'WOOCOMMERCE_SHOPWALK_VERSION', '4.3.0-test' );

require_once __DIR__ . '/../../../includes/features/semantic-search/class-semantic-search-api-client.php';
require_once __DIR__ . '/../../../includes/features/semantic-search/class-semantic-search-query-handler.php';

/**
 * Tiny WP_Query stand-in for unit tests — covers the slice we hook on.
 */
class FakeWP_Query {
	public bool $is_search           = true;
	private array $vars              = array();
	private bool $is_main            = true;
	public function set( string $k, $v ): void {
		$this->vars[ $k ] = $v;
	}
	public function get( string $k ) {
		return $this->vars[ $k ] ?? '';
	}
	public function is_main_query(): bool {
		return $this->is_main;
	}
	public function set_main( bool $m ): void {
		$this->is_main = $m;
	}
}

final class QueryHandlerTest extends TestCase {

	private array $option_store = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$store = &$this->option_store;
		Functions\when( 'get_option' )->alias(
			function ( $key, $default = false ) use ( &$store ) {
				return array_key_exists( $key, $store ) ? $store[ $key ] : $default;
			}
		);
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_mode_defaults_to_replace_when_option_missing(): void {
		$h = new Semantic_Search_Query_Handler();
		$this->assertSame( Semantic_Search_Query_Handler::MODE_REPLACE, $h->mode() );
	}

	public function test_mode_rejects_unknown_value_and_falls_back_to_replace(): void {
		$this->option_store['shopwalk_semsearch_mode'] = 'banana';
		$h = new Semantic_Search_Query_Handler();
		$this->assertSame( Semantic_Search_Query_Handler::MODE_REPLACE, $h->mode() );
	}

	public function test_mode_returns_off_when_set(): void {
		$this->option_store['shopwalk_semsearch_mode'] = Semantic_Search_Query_Handler::MODE_OFF;
		$h = new Semantic_Search_Query_Handler();
		$this->assertSame( Semantic_Search_Query_Handler::MODE_OFF, $h->mode() );
	}

	public function test_scope_defaults_to_product_only(): void {
		$h = new Semantic_Search_Query_Handler();
		$this->assertSame( array( 'product' ), $h->scope() );
	}

	public function test_scope_filters_unknown_post_types_out(): void {
		$this->option_store['shopwalk_semsearch_scope'] = array( 'product', 'evil', 'post' );
		$h = new Semantic_Search_Query_Handler();
		$this->assertSame( array( 'product', 'post' ), $h->scope() );
	}

	public function test_scope_falls_back_to_default_when_only_unknown_types(): void {
		$this->option_store['shopwalk_semsearch_scope'] = array( 'evil' );
		$h = new Semantic_Search_Query_Handler();
		$this->assertSame( array( 'product' ), $h->scope() );
	}

	public function test_limit_clamps_to_default_when_invalid(): void {
		$this->option_store['shopwalk_semsearch_limit'] = -5;
		$h = new Semantic_Search_Query_Handler();
		$this->assertSame( 24, $h->limit() );
	}

	public function test_limit_caps_at_100(): void {
		$this->option_store['shopwalk_semsearch_limit'] = 9001;
		$h = new Semantic_Search_Query_Handler();
		$this->assertSame( 100, $h->limit() );
	}

	public function test_eligibility_rejects_non_search_query(): void {
		$q              = new FakeWP_Query();
		$q->is_search   = false;
		$h              = new Semantic_Search_Query_Handler();
		$this->assertFalse( $h->is_eligible_query( $q ) );
	}

	public function test_eligibility_rejects_non_main_query(): void {
		$q = new FakeWP_Query();
		$q->set_main( false );
		$h = new Semantic_Search_Query_Handler();
		$this->assertFalse( $h->is_eligible_query( $q ) );
	}

	public function test_eligibility_accepts_product_search(): void {
		$q = new FakeWP_Query();
		$q->set( 'post_type', 'product' );
		$h = new Semantic_Search_Query_Handler();
		$this->assertTrue( $h->is_eligible_query( $q ) );
	}

	public function test_eligibility_rejects_out_of_scope_post_type(): void {
		// Default scope is product-only; attachment is never in scope.
		$q = new FakeWP_Query();
		$q->set( 'post_type', 'attachment' );
		$h = new Semantic_Search_Query_Handler();
		$this->assertFalse( $h->is_eligible_query( $q ) );
	}

	public function test_eligibility_default_search_page_with_product_scope(): void {
		// Empty post_type is the canonical "default search results page" —
		// must be eligible because product is in scope.
		$q = new FakeWP_Query();
		$h = new Semantic_Search_Query_Handler();
		$this->assertTrue( $h->is_eligible_query( $q ) );
	}

	public function test_eligibility_default_search_page_when_scope_excludes_default_types(): void {
		// Scope is page-only — the default search page (empty post_type)
		// should not be eligible because neither product nor post is in scope.
		$this->option_store['shopwalk_semsearch_scope'] = array( 'page' );
		$q                                              = new FakeWP_Query();
		$h                                              = new Semantic_Search_Query_Handler();
		$this->assertFalse( $h->is_eligible_query( $q ) );
	}

	public function test_merge_for_augment_puts_shopwalk_ids_first(): void {
		$h      = new Semantic_Search_Query_Handler();
		$merged = $h->merge_for_augment( array( 10, 20 ), array( 30, 10, 40 ) );
		$this->assertSame( array( 10, 20, 30, 40 ), $merged );
	}

	public function test_merge_for_augment_deduplicates(): void {
		$h      = new Semantic_Search_Query_Handler();
		$merged = $h->merge_for_augment( array( 1, 1, 2 ), array( 2, 3, 3 ) );
		$this->assertSame( array( 1, 2, 3 ), $merged );
	}

	public function test_merge_for_augment_skips_zero_and_negative_ids(): void {
		$h      = new Semantic_Search_Query_Handler();
		$merged = $h->merge_for_augment( array( 0, 5, -1 ), array( 6, 0 ) );
		$this->assertSame( array( 5, 6 ), $merged );
	}
}

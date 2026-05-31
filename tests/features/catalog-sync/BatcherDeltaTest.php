<?php
/**
 * Tests for Shopwalk_Catalog_Sync_Batcher delta detection + batch wrapping.
 *
 * The batcher reads via $wpdb / wc_get_orders / wc_get_product. We stub
 * those globals with Brain\Monkey + a hand-rolled $wpdb double so the
 * tests don't need a WP runtime.
 *
 * @package ShopwalkWooCommerce
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

defined( 'SHOPWALK_API_BASE' ) || define( 'SHOPWALK_API_BASE', 'https://api.shopwalk.test/api/v1' );
defined( 'WOOCOMMERCE_SHOPWALK_VERSION' ) || define( 'WOOCOMMERCE_SHOPWALK_VERSION', '4.1.0-test' );
defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 3600 );

require_once __DIR__ . '/../../../includes/features/catalog-sync/class-catalog-sync-batcher.php';

/**
 * Minimal $wpdb double that captures the last prepared SQL and returns a
 * canned column result. The batcher's delta query is structured (we don't
 * need a real DB) — we only need to verify the SQL shape and observe the
 * post-processing that turns variation IDs into parent IDs.
 */
final class CatalogSyncFakeWpdb { // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
	public string $posts        = 'wp_posts';
	public array $last_prepared = array();
	/** @var int[] */
	public array $next_col_result = array();

	public function prepare( string $query, ...$args ): string {
		// Naive %s / %d substitution good enough for SQL-shape assertions.
		$idx = 0;
		return preg_replace_callback(
			'/%[sd]/',
			function ( $m ) use ( &$idx, $args ) {
				$v = $args[ $idx++ ] ?? '';
				return is_int( $v ) ? (string) $v : "'" . $v . "'";
			},
			$query
		);
	}

	/**
	 * @return int[]
	 */
	public function get_col( string $query ): array {
		$this->last_prepared[] = $query;
		return $this->next_col_result;
	}
}

final class BatcherDeltaTest extends TestCase {

	private CatalogSyncFakeWpdb $wpdb;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		global $wpdb;
		$this->wpdb = new CatalogSyncFakeWpdb();
		$wpdb       = $this->wpdb;

		// Default parent resolution: nothing is a variation. Per-test
		// overrides re-bind this with Functions\when().
		Functions\when( 'wp_get_post_parent_id' )->justReturn( 0 );
		// returnArg() is 1-indexed in Brain\Monkey; apply_filters($name, $value)
		// → return the value (arg #2).
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_option' )->justReturn( '' );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'wp_generate_password' )->justReturn( 'test-salt' );
		Functions\when( 'wp_json_encode' )->alias(
			static function ( $v, $flags = 0 ) {
				return json_encode( $v, (int) $flags );
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_find_products_scopes_to_product_post_types_and_modified_window(): void {
		$this->wpdb->next_col_result = array( '101', '102', '103' );

		$batcher = new Shopwalk_Catalog_Sync_Batcher();
		$ids     = $batcher->find_products_modified_since( 1_700_000_000 );

		$this->assertSame( array( 101, 102, 103 ), $ids );
		$this->assertCount( 1, $this->wpdb->last_prepared );

		$sql = $this->wpdb->last_prepared[0];
		// Asserts the SQL shape: post_type filter + modified_gmt filter.
		$this->assertStringContainsString( "post_type IN ('product','product_variation')", $sql );
		$this->assertStringContainsString( 'post_modified_gmt >', $sql );
		// Modified-since timestamp must be the GMT string for 1_700_000_000.
		$this->assertStringContainsString( gmdate( 'Y-m-d H:i:s', 1_700_000_000 ), $sql );
		// Bounded — must include a LIMIT to cap per-tick work.
		$this->assertStringContainsString( 'LIMIT', $sql );
	}

	public function test_find_products_rolls_variations_up_to_parents(): void {
		$this->wpdb->next_col_result = array( '500', '501', '502' );

		// 500 + 501 are variations whose parent is 200; 502 is a top-level product.
		Functions\when( 'wp_get_post_parent_id' )->alias(
			static function ( $id ) {
				return in_array( (int) $id, array( 500, 501 ), true ) ? 200 : 0;
			}
		);

		$batcher = new Shopwalk_Catalog_Sync_Batcher();
		$ids     = $batcher->find_products_modified_since( 0 );

		// 500 + 501 collapse to parent 200; 502 stays. Order = parents in
		// insertion order, then standalones.
		$this->assertSame( array( 200, 502 ), $ids );
	}

	public function test_find_products_returns_empty_when_wpdb_silent(): void {
		$this->wpdb->next_col_result = array();

		$batcher = new Shopwalk_Catalog_Sync_Batcher();
		$ids     = $batcher->find_products_modified_since( time() );

		$this->assertSame( array(), $ids );
	}

	public function test_find_products_passes_zero_through_safely(): void {
		// Negative or zero timestamps used to bypass the index — verify we
		// clamp to 0 and still build a valid query.
		$this->wpdb->next_col_result = array();

		$batcher = new Shopwalk_Catalog_Sync_Batcher();
		$batcher->find_products_modified_since( -42 );

		$sql = $this->wpdb->last_prepared[0];
		$this->assertStringContainsString( gmdate( 'Y-m-d H:i:s', 0 ), $sql );
	}

	public function test_find_all_product_ids_paginates(): void {
		$this->wpdb->next_col_result = array( '1', '2', '3' );

		$batcher = new Shopwalk_Catalog_Sync_Batcher();
		$ids     = $batcher->find_all_product_ids( 2, 100 );

		$this->assertSame( array( 1, 2, 3 ), $ids );

		$sql = $this->wpdb->last_prepared[0];
		// Page 2 with size 100 → OFFSET 100, LIMIT 100.
		$this->assertStringContainsString( 'LIMIT 100', $sql );
		$this->assertStringContainsString( 'OFFSET 100', $sql );
		$this->assertStringContainsString( "post_type = 'product'", $sql );
		$this->assertStringContainsString( "post_status = 'publish'", $sql );
	}

	public function test_collect_products_with_no_items_produces_empty_batch_envelope(): void {
		// wc_get_product undefined → batcher's serialize_product returns null,
		// so the items array stays empty but the envelope shape must hold.
		$batcher = new Shopwalk_Catalog_Sync_Batcher();
		$batch   = $batcher->collect_products( array() );

		$this->assertArrayHasKey( 'partner_id', $batch );
		$this->assertArrayHasKey( 'checksum', $batch );
		$this->assertArrayHasKey( 'items', $batch );
		$this->assertSame( array(), $batch['items'] );
		// Empty-items checksum is the sha256 of the JSON literal `[]`.
		$this->assertSame( hash( 'sha256', '[]' ), $batch['checksum'] );
	}

	public function test_collect_products_checksum_is_stable_for_same_inputs(): void {
		$this->stub_wc_get_product_null();

		$batcher = new Shopwalk_Catalog_Sync_Batcher();
		$a       = $batcher->collect_products( array( 7, 8 ) );
		$b       = $batcher->collect_products( array( 7, 8 ) );

		$this->assertSame( $a['checksum'], $b['checksum'] );
		// Sanity: deletion records made it into the batch.
		$this->assertCount( 2, $a['items'] );
		$this->assertTrue( (bool) ( $a['items'][0]['deleted'] ?? false ) );
	}

	public function test_collect_products_checksum_differs_when_items_differ(): void {
		$this->stub_wc_get_product_null();

		$batcher = new Shopwalk_Catalog_Sync_Batcher();
		$a       = $batcher->collect_products( array( 7, 8 ) );
		$b       = $batcher->collect_products( array( 7, 9 ) );

		$this->assertNotSame( $a['checksum'], $b['checksum'] );
	}

	/**
	 * Stub wc_get_product to return null so the batcher emits the deletion
	 * record (`{external_id, deleted:true}`) rather than skipping the item.
	 *
	 * Brain\Monkey only defines a function the first time across the test
	 * suite — once another test has registered `wc_get_product`, re-stubbing
	 * is a no-op. To stay robust across run ordering we declare the function
	 * via Brain\Monkey if needed and then bind the per-test behaviour with
	 * the same call.
	 */
	private function stub_wc_get_product_null(): void {
		if ( ! function_exists( 'wc_get_product' ) ) {
			Functions\when( 'wc_get_product' )->justReturn( null );
			return;
		}
		Functions\when( 'wc_get_product' )->justReturn( null );
	}
}

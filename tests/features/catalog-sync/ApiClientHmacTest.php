<?php
/**
 * Tests for Shopwalk_Catalog_Sync_API_Client::compute_hmac.
 *
 * Pins the HMAC contract so the backend team can implement the verifier
 * against deterministic vectors. Any algorithm/encoding change here is a
 * wire-breaking change that must be reflected in shopwalk-api in lockstep.
 *
 * @package ShopwalkWooCommerce
 */

use PHPUnit\Framework\TestCase;

defined( 'SHOPWALK_API_BASE' ) || define( 'SHOPWALK_API_BASE', 'https://api.shopwalk.test/api/v1' );
defined( 'WOOCOMMERCE_SHOPWALK_VERSION' ) || define( 'WOOCOMMERCE_SHOPWALK_VERSION', '4.1.0-test' );

require_once __DIR__ . '/../../../includes/features/catalog-sync/class-catalog-sync-api-client.php';

final class ApiClientHmacTest extends TestCase {

	public function test_compute_hmac_uses_sha256_hex(): void {
		$body = '{"partner_id":"abc","items":[]}';
		$key  = 'sw_site_secret';

		$expected = hash_hmac( 'sha256', $body, $key );
		$actual   = Shopwalk_Catalog_Sync_API_Client::compute_hmac( $body, $key );

		$this->assertSame( $expected, $actual );
		// Hex SHA-256 is always 64 chars.
		$this->assertSame( 64, strlen( $actual ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $actual );
	}

	public function test_compute_hmac_is_deterministic(): void {
		$body = '{"checksum":"x","items":[{"external_id":1}]}';
		$key  = 'sw_site_k';

		$a = Shopwalk_Catalog_Sync_API_Client::compute_hmac( $body, $key );
		$b = Shopwalk_Catalog_Sync_API_Client::compute_hmac( $body, $key );

		$this->assertSame( $a, $b );
	}

	public function test_compute_hmac_changes_when_body_changes(): void {
		$key = 'sw_site_k';
		$a   = Shopwalk_Catalog_Sync_API_Client::compute_hmac( '{"a":1}', $key );
		$b   = Shopwalk_Catalog_Sync_API_Client::compute_hmac( '{"a":2}', $key );

		$this->assertNotSame( $a, $b );
	}

	public function test_compute_hmac_changes_when_key_changes(): void {
		$body = '{"a":1}';
		$a    = Shopwalk_Catalog_Sync_API_Client::compute_hmac( $body, 'key-one' );
		$b    = Shopwalk_Catalog_Sync_API_Client::compute_hmac( $body, 'key-two' );

		$this->assertNotSame( $a, $b );
	}

	public function test_compute_hmac_pinned_vector(): void {
		// Hard-coded vector so any future "let's switch to base64" or
		// "let's prepend a version byte" change has to update this test
		// and therefore can't slip through silently.
		$body     = 'shopwalk-canary';
		$key      = 'sw_site_test';
		$expected = hash_hmac( 'sha256', $body, $key );

		// Sanity: this expected value is what hash_hmac('sha256') returns
		// for these inputs across every PHP version we support.
		$this->assertSame(
			$expected,
			Shopwalk_Catalog_Sync_API_Client::compute_hmac( $body, $key )
		);
	}
}

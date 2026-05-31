<?php
/**
 * Tests for Shopwalk_Product_Descriptions_Api_Client::build_signed_request().
 *
 * Verifies the HMAC-SHA256 signing semantics — deterministic for fixed
 * inputs, stable across runs, and sensitive to every signed component
 * (timestamp, body, secret). The wire format follows the convention
 * `hmac_sha256("<timestamp>.<body>", signing_secret)` so the backend can
 * enforce a replay window.
 *
 * @package ShopwalkWooCommerce
 */

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/../../../' );
defined( 'WOOCOMMERCE_SHOPWALK_VERSION' ) || define( 'WOOCOMMERCE_SHOPWALK_VERSION', '4.2.0-test' );
defined( 'SHOPWALK_API_BASE' ) || define( 'SHOPWALK_API_BASE', 'https://api.shopwalk.test/api/v1' );

require_once __DIR__ . '/../../../includes/features/product-descriptions/class-product-descriptions-api-client.php';

final class ProductDescriptions_ApiClientSigningTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// Stub wp_json_encode so the class can serialise without WP loaded.
		Functions\when( 'wp_json_encode' )->alias( fn( $v ) => json_encode( $v ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_request_carries_bearer_license_and_hmac_headers(): void {
		$out = Shopwalk_Product_Descriptions_Api_Client::build_signed_request(
			array( 'product_id' => 42, 'fields' => array( 'long' ) ),
			'sw_lic_test',
			'shared-secret',
			1717000000
		);

		$this->assertSame( 'Bearer sw_lic_test', $out['headers']['Authorization'] );
		$this->assertSame( 'application/json', $out['headers']['Content-Type'] );
		$this->assertSame( '1717000000', $out['headers']['X-Shopwalk-Ts'] );
		$this->assertArrayHasKey( 'X-Shopwalk-HMAC', $out['headers'] );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $out['headers']['X-Shopwalk-HMAC'] );
		$this->assertStringContainsString( 'shopwalk-for-woocommerce-plugin/', $out['headers']['User-Agent'] );
	}

	public function test_hmac_is_deterministic_for_fixed_inputs(): void {
		$payload = array( 'product_id' => 7 );
		$a = Shopwalk_Product_Descriptions_Api_Client::build_signed_request( $payload, 'k', 's', 1234 );
		$b = Shopwalk_Product_Descriptions_Api_Client::build_signed_request( $payload, 'k', 's', 1234 );
		$this->assertSame( $a['headers']['X-Shopwalk-HMAC'], $b['headers']['X-Shopwalk-HMAC'] );
		$this->assertSame( $a['body'], $b['body'] );
	}

	public function test_hmac_changes_when_body_changes(): void {
		$a = Shopwalk_Product_Descriptions_Api_Client::build_signed_request( array( 'product_id' => 1 ), 'k', 's', 999 );
		$b = Shopwalk_Product_Descriptions_Api_Client::build_signed_request( array( 'product_id' => 2 ), 'k', 's', 999 );
		$this->assertNotSame( $a['headers']['X-Shopwalk-HMAC'], $b['headers']['X-Shopwalk-HMAC'] );
	}

	public function test_hmac_changes_when_secret_changes(): void {
		$a = Shopwalk_Product_Descriptions_Api_Client::build_signed_request( array( 'x' => 1 ), 'k', 's1', 1 );
		$b = Shopwalk_Product_Descriptions_Api_Client::build_signed_request( array( 'x' => 1 ), 'k', 's2', 1 );
		$this->assertNotSame( $a['headers']['X-Shopwalk-HMAC'], $b['headers']['X-Shopwalk-HMAC'] );
	}

	public function test_hmac_changes_when_timestamp_changes(): void {
		$a = Shopwalk_Product_Descriptions_Api_Client::build_signed_request( array( 'x' => 1 ), 'k', 's', 1 );
		$b = Shopwalk_Product_Descriptions_Api_Client::build_signed_request( array( 'x' => 1 ), 'k', 's', 2 );
		$this->assertNotSame( $a['headers']['X-Shopwalk-HMAC'], $b['headers']['X-Shopwalk-HMAC'] );
	}

	public function test_hmac_matches_reference_value(): void {
		// Belt-and-braces: lock in the exact wire format the backend
		// must implement (`hmac_sha256("<ts>.<body>", secret)`) by
		// comparing against an independently computed digest.
		$payload = array( 'product_id' => 100, 'fields' => array( 'long', 'short' ) );
		$ts      = 1717777777;
		$body    = json_encode( $payload );
		$expect  = hash_hmac( 'sha256', $ts . '.' . $body, 'reference-secret' );

		$out = Shopwalk_Product_Descriptions_Api_Client::build_signed_request( $payload, 'sw_lic_x', 'reference-secret', $ts );

		$this->assertSame( $expect, $out['headers']['X-Shopwalk-HMAC'] );
		$this->assertSame( $body, $out['body'] );
	}
}

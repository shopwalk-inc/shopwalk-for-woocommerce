<?php
/**
 * Tests for Shopwalk_ACP_Client — host_root derivation, request shape, and
 * response interpretation. These pin the contract against the shopwalk-api
 * partner endpoints (pause + status).
 *
 * @package ShopwalkWooCommerce
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

defined( 'SHOPWALK_API_BASE' ) || define( 'SHOPWALK_API_BASE', 'https://api.shopwalk.test/api/v1' );
defined( 'WOOCOMMERCE_SHOPWALK_VERSION' ) || define( 'WOOCOMMERCE_SHOPWALK_VERSION', '3.1.16-test' );

require_once __DIR__ . '/../includes/acp/class-shopwalk-acp-client.php';

final class AcpClientTest extends TestCase {

	/** @var array<int,array{url:string,args:array}> */
	private array $requests = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->requests = array();

		Functions\when( 'get_option' )->alias(
			function ( $key, $default = false ) {
				if ( 'shopwalk_license_key' === $key ) {
					return 'sw_site_test';
				}
				return $default;
			}
		);
		Functions\when( 'home_url' )->justReturn( 'https://merchant.example' );
		Functions\when( 'wp_json_encode' )->alias(
			function ( $data ) {
				return json_encode( $data );
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			function ( $r ) {
				return isset( $r['response']['code'] ) ? (int) $r['response']['code'] : 0;
			}
		);
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			function ( $r ) {
				return isset( $r['body'] ) ? (string) $r['body'] : '';
			}
		);
		Functions\when( 'is_wp_error' )->alias(
			function ( $thing ) {
				return $thing instanceof WP_Error;
			}
		);
		Functions\when( '__' )->alias(
			function ( $s ) {
				return $s;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Helper: mock wp_remote_post / wp_remote_get to record the call and
	 * return a fake response payload.
	 *
	 * @param array $fake_response Shape: ['response' => ['code' => 200], 'body' => '...']
	 */
	private function fake_http( array $fake_response ): void {
		$captures = &$this->requests;
		Functions\when( 'wp_remote_post' )->alias(
			function ( $url, $args = array() ) use ( $fake_response, &$captures ) {
				$captures[] = array(
					'method' => 'POST',
					'url'    => $url,
					'args'   => $args,
				);
				return $fake_response;
			}
		);
		Functions\when( 'wp_remote_get' )->alias(
			function ( $url, $args = array() ) use ( $fake_response, &$captures ) {
				$captures[] = array(
					'method' => 'GET',
					'url'    => $url,
					'args'   => $args,
				);
				return $fake_response;
			}
		);
	}

	public function test_pause_request_carries_paused_flag(): void {
		$this->fake_http(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => json_encode( array( 'status' => 'paused' ) ),
			)
		);

		$result = Shopwalk_ACP_Client::set_paused( true );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'paused', $result['status'] );
		$this->assertCount( 1, $this->requests );

		// The URL must NOT carry /api/v1 in front of /partners/... — ACP
		// partner routes live at the host root, alongside /api/v1, not
		// underneath it.
		$this->assertSame( 'https://api.shopwalk.test/partners/v1/acp/pause', $this->requests[0]['url'] );

		$headers = $this->requests[0]['args']['headers'];
		$this->assertSame( 'sw_site_test', $headers['X-API-Key'] );
		$this->assertSame( 'application/json', $headers['Content-Type'] );

		$body = json_decode( $this->requests[0]['args']['body'], true );
		$this->assertTrue( $body['paused'] );
		$this->assertSame( 'https://merchant.example', $body['site_url'] );
	}

	public function test_resume_request_passes_paused_false(): void {
		$this->fake_http(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => json_encode( array( 'status' => 'opted_in' ) ),
			)
		);

		$result = Shopwalk_ACP_Client::set_paused( false );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'opted_in', $result['status'] );
		$body = json_decode( $this->requests[0]['args']['body'], true );
		$this->assertFalse( $body['paused'] );
	}

	public function test_status_uses_get_and_returns_payload(): void {
		$payload = array(
			'status'           => 'opted_in',
			'payment_compat'   => 'full',
			'feed_item_count'  => 42,
			'moderation_flags' => array(
				array(
					'product_id' => '123',
					'reason'     => 'price_zero',
				),
			),
		);
		$this->fake_http(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => json_encode( $payload ),
			)
		);

		$result = Shopwalk_ACP_Client::status();

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'GET', $this->requests[0]['method'] );
		$this->assertSame( 'https://api.shopwalk.test/partners/v1/acp/status', $this->requests[0]['url'] );
		$this->assertSame( 42, $result['feed_item_count'] );
		$this->assertCount( 1, $result['moderation_flags'] );
	}

	public function test_status_404_surfaces_status_code(): void {
		// 404 = partner not ACP-eligible (typically unlicensed/disconnected).
		// The admin page uses status_code to decide whether to show the
		// "Connect to Shopwalk first" view vs a transient error banner.
		$this->fake_http(
			array(
				'response' => array( 'code' => 404 ),
				'body'     => json_encode( array( 'message' => 'acp_not_eligible' ) ),
			)
		);

		$result = Shopwalk_ACP_Client::status();

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 404, $result['status_code'] );
		$this->assertSame( 'acp_not_eligible', $result['message'] );
	}

	public function test_non_2xx_response_returns_error_with_api_message(): void {
		$this->fake_http(
			array(
				'response' => array( 'code' => 422 ),
				'body'     => json_encode( array( 'message' => 'invalid_request' ) ),
			)
		);

		$result = Shopwalk_ACP_Client::set_paused( true );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'invalid_request', $result['message'] );
		$this->assertSame( 422, $result['status_code'] );
	}

	public function test_wp_error_response_returns_error_with_error_message(): void {
		$err = new WP_Error( 'http_request_failed', 'connection refused', array() );
		Functions\when( 'wp_remote_post' )->justReturn( $err );

		$result = Shopwalk_ACP_Client::set_paused( true );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'connection refused', $result['message'] );
		$this->assertSame( 0, $result['status_code'] );
	}

	public function test_missing_license_short_circuits_with_clear_error(): void {
		// Re-stub get_option to return empty for the license key.
		Functions\when( 'get_option' )->alias(
			function ( $key, $default = false ) {
				return $default;
			}
		);

		$result = Shopwalk_ACP_Client::status();

		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'No Shopwalk license', $result['message'] );
	}
}

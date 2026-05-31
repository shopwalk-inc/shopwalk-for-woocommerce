<?php
/**
 * Tests for Shopwalk_Recommendations_API_Client.
 *
 * Pins the wire contract: endpoint URL, auth headers, HMAC signature,
 * payload shape, response interpretation, and the 5-minute transient
 * cache behaviour.
 *
 * @package ShopwalkWooCommerce
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

defined( 'SHOPWALK_API_BASE' ) || define( 'SHOPWALK_API_BASE', 'https://api.shopwalk.test/api/v1' );
defined( 'WOOCOMMERCE_SHOPWALK_VERSION' ) || define( 'WOOCOMMERCE_SHOPWALK_VERSION', '4.4.0-test' );
defined( 'WOOCOMMERCE_SHOPWALK_PLUGIN_DIR' ) || define( 'WOOCOMMERCE_SHOPWALK_PLUGIN_DIR', dirname( __DIR__, 2 ) . '/' );
defined( 'WOOCOMMERCE_SHOPWALK_PLUGIN_URL' ) || define( 'WOOCOMMERCE_SHOPWALK_PLUGIN_URL', 'https://example.test/wp-content/plugins/shopwalk-for-woocommerce/' );

require_once __DIR__ . '/../../../includes/features/recommendations/class-recommendations-api-client.php';

final class RecommendationsApiClientTest extends TestCase {

	/** @var array<int,array{url:string,args:array}> */
	private array $requests = array();

	/** @var array<string,mixed> */
	private array $transients = array();

	/** @var ArrayObject<string,mixed> */
	private ArrayObject $options;

	/** @var ArrayObject<string,mixed> */
	private ArrayObject $transient_store;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->requests        = array();
		$this->transient_store = new ArrayObject();
		$this->transients      = array();
		$this->options         = new ArrayObject(
			array(
				'shopwalk_license_key' => 'sw_site_test',
				'shopwalk_partner_id'  => 'partner-uuid-1',
			)
		);

		$options    = $this->options;
		$transients = $this->transient_store;

		Functions\when( 'get_option' )->alias(
			fn ( $key, $default = false ) => $options->offsetExists( $key ) ? $options->offsetGet( $key ) : $default
		);
		Functions\when( 'update_option' )->alias(
			function ( $key, $value ) use ( $options ) {
				$options->offsetSet( $key, $value );
				return true;
			}
		);
		Functions\when( 'get_transient' )->alias(
			fn ( $key ) => $transients->offsetExists( $key ) ? $transients->offsetGet( $key ) : false
		);
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value, $ttl ) use ( $transients ) {
				$transients->offsetSet( $key, $value );
				return true;
			}
		);
		Functions\when( 'wp_json_encode' )->alias( fn ( $data ) => json_encode( $data ) );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			fn ( $r ) => isset( $r['response']['code'] ) ? (int) $r['response']['code'] : 0
		);
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			fn ( $r ) => isset( $r['body'] ) ? (string) $r['body'] : ''
		);
		Functions\when( 'is_wp_error' )->alias( fn ( $thing ) => $thing instanceof WP_Error );
		Functions\when( 'apply_filters' )->alias(
			function ( $hook, $value ) { return $value; }
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Capture every wp_remote_post() and return a canned fake response.
	 *
	 * @param array $fake_response Fake response payload.
	 */
	private function fake_http( array $fake_response ): void {
		$captures = &$this->requests;
		Functions\when( 'wp_remote_post' )->alias(
			function ( $url, $args = array() ) use ( $fake_response, &$captures ) {
				$captures[] = array( 'url' => $url, 'args' => $args );
				return $fake_response;
			}
		);
	}

	public function test_fetch_posts_to_recommendations_endpoint_with_bearer_and_hmac(): void {
		$this->fake_http(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => json_encode(
					array(
						'product_ids' => array( 11, 22, 33 ),
						'fallback'    => false,
						'tokens_used' => 42,
					)
				),
			)
		);

		$result = Shopwalk_Recommendations_API_Client::fetch( 'related', 999, 3, null );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( array( 11, 22, 33 ), $result['product_ids'] );
		$this->assertFalse( $result['fallback'] );
		$this->assertSame( 42, $result['tokens_used'] );
		$this->assertFalse( $result['from_cache'] );
		$this->assertCount( 1, $this->requests );

		$this->assertSame(
			'https://api.shopwalk.test/api/v1/plugin/ai/recommendations',
			$this->requests[0]['url']
		);

		$headers = $this->requests[0]['args']['headers'];
		$this->assertSame( 'Bearer sw_site_test', $headers['Authorization'] );
		$this->assertSame( 'partner-uuid-1', $headers['X-Shopwalk-Partner-ID'] );
		$this->assertSame( 'application/json', $headers['Content-Type'] );
		$this->assertNotEmpty( $headers['X-Shopwalk-HMAC'] );
		$this->assertNotEmpty( $headers['X-Shopwalk-Timestamp'] );

		// Verify the HMAC matches what the server will reproduce.
		$body     = (string) $this->requests[0]['args']['body'];
		$expected = hash_hmac( 'sha256', $headers['X-Shopwalk-Timestamp'] . '.' . $body, 'sw_site_test' );
		$this->assertSame( $expected, $headers['X-Shopwalk-HMAC'] );

		$decoded = json_decode( $body, true );
		$this->assertSame( 'partner-uuid-1', $decoded['partner_id'] );
		$this->assertSame( 'related', $decoded['type'] );
		$this->assertSame( 999, $decoded['context_product_id'] );
		$this->assertSame( 3, $decoded['count'] );
		$this->assertArrayNotHasKey( 'user_id', $decoded );
	}

	public function test_fetch_includes_user_id_when_provided(): void {
		$this->fake_http(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => json_encode(
					array(
						'product_ids' => array( 5 ),
						'fallback'    => true,
					)
				),
			)
		);

		$result = Shopwalk_Recommendations_API_Client::fetch( 'personalized', 0, 4, 777 );

		$this->assertTrue( $result['ok'] );
		$this->assertTrue( $result['fallback'] );
		$body = json_decode( (string) $this->requests[0]['args']['body'], true );
		$this->assertSame( 777, $body['user_id'] );
	}

	public function test_fetch_normalizes_unknown_type_to_related(): void {
		$this->fake_http(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => json_encode( array( 'product_ids' => array() ) ),
			)
		);

		Shopwalk_Recommendations_API_Client::fetch( 'made_up_type', 1, 2, null );

		$body = json_decode( (string) $this->requests[0]['args']['body'], true );
		$this->assertSame( 'related', $body['type'] );
	}

	public function test_fetch_caches_response_for_5_minutes(): void {
		$this->fake_http(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => json_encode( array( 'product_ids' => array( 7, 8, 9 ) ) ),
			)
		);

		$first  = Shopwalk_Recommendations_API_Client::fetch( 'fbt', 111, 3, null );
		$second = Shopwalk_Recommendations_API_Client::fetch( 'fbt', 111, 3, null );

		$this->assertCount( 1, $this->requests, 'Second fetch must come from the transient cache.' );
		$this->assertSame( array( 7, 8, 9 ), $second['product_ids'] );
		$this->assertTrue( $second['from_cache'] );
		$this->assertFalse( $first['from_cache'] );
	}

	public function test_invalidate_busts_cache_for_a_product(): void {
		$this->fake_http(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => json_encode( array( 'product_ids' => array( 1 ) ) ),
			)
		);

		Shopwalk_Recommendations_API_Client::fetch( 'related', 42, 6, null );
		Shopwalk_Recommendations_API_Client::invalidate( 42 );
		Shopwalk_Recommendations_API_Client::fetch( 'related', 42, 6, null );

		$this->assertCount( 2, $this->requests, 'Invalidate must force a re-fetch.' );
	}

	public function test_missing_partner_id_short_circuits(): void {
		$this->options->offsetSet( 'shopwalk_partner_id', '' );
		Functions\when( 'wp_remote_post' )->justReturn(
			array( 'response' => array( 'code' => 200 ), 'body' => '{}' )
		);

		$result = Shopwalk_Recommendations_API_Client::fetch( 'related', 1, 6, null );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'no_partner_id', $result['code'] );
	}

	public function test_non_2xx_returns_error_with_api_message(): void {
		$this->fake_http(
			array(
				'response' => array( 'code' => 422 ),
				'body'     => json_encode( array( 'message' => 'invalid_request' ) ),
			)
		);

		$result = Shopwalk_Recommendations_API_Client::fetch( 'related', 1, 6, null );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'invalid_request', $result['message'] );
		$this->assertSame( 422, $result['status_code'] );
	}

	public function test_wp_error_response_returns_error(): void {
		Functions\when( 'wp_remote_post' )->justReturn(
			new WP_Error( 'http_failed', 'connection refused', array() )
		);

		$result = Shopwalk_Recommendations_API_Client::fetch( 'related', 1, 6, null );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'connection refused', $result['message'] );
	}
}

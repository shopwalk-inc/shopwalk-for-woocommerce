<?php
/**
 * Tests for Shopwalk_Product_Descriptions_Generator's context-gathering.
 *
 * Covers:
 *  - build_request_payload() shape (fields default, options pass-through, plugin_version stamping)
 *  - brand_voice_id() resolves the filter, then the option, then '' when nothing is set
 *  - gather_product_context() returns the documented array shape even when WC is unavailable
 *
 * Brain\Monkey stubs WP functions so the tests can run without a WP runtime.
 *
 * @package ShopwalkWooCommerce
 */

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Brain\Monkey\Filters;

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/../../../' );
defined( 'MINUTE_IN_SECONDS' ) || define( 'MINUTE_IN_SECONDS', 60 );
defined( 'WOOCOMMERCE_SHOPWALK_VERSION' ) || define( 'WOOCOMMERCE_SHOPWALK_VERSION', '4.2.0-test' );

require_once __DIR__ . '/../../../includes/features/product-descriptions/class-product-descriptions-api-client.php';
require_once __DIR__ . '/../../../includes/features/product-descriptions/class-product-descriptions-generator.php';

final class ProductDescriptions_GeneratorContextTest extends TestCase {

	/** @var array<string,mixed> */
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->options = array();
		$opts          = &$this->options;
		// Note: traditional anonymous functions only. PHP arrow `fn()`
		// captures by value, which silently breaks the `use ( &$opts )`
		// reference Brain\Monkey relies on for the per-test option store.
		Functions\when( 'get_option' )->alias(
			function ( $k, $d = false ) use ( &$opts ) {
				return array_key_exists( $k, $opts ) ? $opts[ $k ] : $d;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $k, $v ) use ( &$opts ) {
				$opts[ $k ] = $v;
				return true;
			}
		);
		Functions\when( 'home_url' )->justReturn( 'https://example.test' );
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );
		// Other test files in the suite define wc_get_product globally;
		// give it a deterministic null so gather_product_context() returns
		// the empty-shape branch and doesn't try to call WC product methods.
		Functions\when( 'wc_get_product' )->justReturn( null );
		Functions\when( 'get_the_terms' )->justReturn( array() );
		// Brain\Monkey ships its own apply_filters/do_action shims that
		// return null when no expectation is registered. The generator
		// is defensive against that (treats null as "no listeners") so
		// no global stub is needed here.
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_payload_carries_product_id_and_default_fields(): void {
		$gen     = new Shopwalk_Product_Descriptions_Generator();
		$payload = $gen->build_request_payload( 42, array() );

		$this->assertSame( 42, $payload['product_id'] );
		$this->assertSame( array( 'long', 'short' ), $payload['fields'] );
		$this->assertSame( 'brand_voice', $payload['tone'] );
		$this->assertSame( 'medium', $payload['length'] );
		$this->assertSame( '', $payload['focus_keyphrase'] );
		$this->assertArrayHasKey( 'context', $payload );
		// Other test files in the suite may have defined the constant
		// first; we only require that the payload carries whatever
		// version constant is in scope as a non-empty string.
		$this->assertIsString( $payload['plugin_version'] );
		$this->assertNotEmpty( $payload['plugin_version'] );
		$this->assertSame( 'https://example.test', $payload['site_url'] );
	}

	public function test_payload_respects_caller_options(): void {
		$gen     = new Shopwalk_Product_Descriptions_Generator();
		$payload = $gen->build_request_payload(
			7,
			array(
				'fields'          => array( 'short' ),
				'tone'            => 'playful',
				'length'          => 'long',
				'focus_keyphrase' => 'organic cotton',
				'include_images'  => false,
			)
		);

		$this->assertSame( array( 'short' ), $payload['fields'] );
		$this->assertSame( 'playful', $payload['tone'] );
		$this->assertSame( 'long', $payload['length'] );
		$this->assertSame( 'organic cotton', $payload['focus_keyphrase'] );
	}

	public function test_invalid_fields_fall_back_to_default(): void {
		$gen     = new Shopwalk_Product_Descriptions_Generator();
		$payload = $gen->build_request_payload( 1, array( 'fields' => array( 'bogus', 'also-bogus' ) ) );
		$this->assertSame( array( 'long', 'short' ), $payload['fields'] );
	}

	public function test_locale_is_carried_when_provided(): void {
		$gen     = new Shopwalk_Product_Descriptions_Generator();
		$payload = $gen->build_request_payload( 1, array( 'locale' => 'es_ES' ) );
		$this->assertSame( 'es_ES', $payload['locale'] );
	}

	public function test_brand_voice_id_from_option_when_status_trained(): void {
		$this->options['shopwalk_brand_voice_status'] = 'trained';
		$this->options['shopwalk_brand_voice_id']     = 'bv_abc';

		$gen = new Shopwalk_Product_Descriptions_Generator();
		$this->assertSame( 'bv_abc', $gen->brand_voice_id() );
	}

	public function test_brand_voice_id_skipped_when_status_in_progress(): void {
		$this->options['shopwalk_brand_voice_status'] = 'training';
		$this->options['shopwalk_brand_voice_id']     = 'bv_abc';

		$gen = new Shopwalk_Product_Descriptions_Generator();
		$this->assertSame( '', $gen->brand_voice_id() );
	}

	public function test_brand_voice_id_filter_wins_over_option(): void {
		$this->options['shopwalk_brand_voice_id'] = 'opt_value';
		Filters\expectApplied( 'shopwalk_brand_voice_id' )
			->once()
			->andReturn( 'filter_value' );

		$gen = new Shopwalk_Product_Descriptions_Generator();
		$this->assertSame( 'filter_value', $gen->brand_voice_id() );
	}

	public function test_payload_includes_brand_voice_when_resolved(): void {
		$this->options['shopwalk_brand_voice_status'] = 'trained';
		$this->options['shopwalk_brand_voice_id']     = 'bv_xyz';

		$gen     = new Shopwalk_Product_Descriptions_Generator();
		$payload = $gen->build_request_payload( 1, array() );
		$this->assertSame( 'bv_xyz', $payload['brand_voice_id'] );
	}

	public function test_payload_omits_brand_voice_when_missing(): void {
		$gen     = new Shopwalk_Product_Descriptions_Generator();
		$payload = $gen->build_request_payload( 1, array() );
		$this->assertArrayNotHasKey( 'brand_voice_id', $payload );
	}

	public function test_partner_id_from_option_when_license_class_absent(): void {
		$this->options['shopwalk_partner_id'] = 'pid_test';
		$gen     = new Shopwalk_Product_Descriptions_Generator();
		$payload = $gen->build_request_payload( 1, array() );
		$this->assertSame( 'pid_test', $payload['partner_id'] );
	}

	public function test_gather_context_returns_documented_shape_without_wc(): void {
		$gen     = new Shopwalk_Product_Descriptions_Generator();
		$context = $gen->gather_product_context( 1 );

		$this->assertSame(
			array(
				'title',
				'sku',
				'price',
				'currency',
				'existing_long',
				'existing_short',
				'attributes',
				'categories',
				'tags',
				'images',
			),
			array_keys( $context )
		);
		$this->assertSame( array(), $context['attributes'] );
		$this->assertSame( array(), $context['images'] );
	}
}

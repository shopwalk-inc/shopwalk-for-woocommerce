<?php
/**
 * Tests for Shopwalk_Recommendations_Block_Handler::render_container().
 *
 * The container is the only output the storefront sees from server-side
 * render — the carousel itself is filled in by lazy-load JS via the
 * /wp-json/shopwalk/v1/recommendations endpoint. These tests pin:
 *
 *   - the container carries the data attributes the JS depends on
 *   - the Pro/Free tier gate suppresses output for Free installs
 *   - the layout class + skeleton count reflect the request
 *   - the shortcode entry point produces the same shape
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

require_once __DIR__ . '/../../../includes/features/recommendations/class-recommendations-feature.php';

final class RecommendationsBlockRenderTest extends TestCase {

	/**
	 * Stable storage for the get_option() alias. We use a static-ish
	 * holder (object property holding an ArrayObject) so the closure
	 * registered in setUp() keeps seeing the same backing store as the
	 * test method mutates it — a plain array property doesn't survive
	 * the reference dance.
	 *
	 * @var ArrayObject<string,mixed>
	 */
	private ArrayObject $options;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Default: Pro license active so render proceeds.
		$this->options = new ArrayObject(
			array(
				'shopwalk_license_key' => 'sw_site_test',
				'shopwalk_partner_id'  => 'partner-uuid-1',
				'shopwalk_plan'        => 'pro',
			)
		);

		$options = $this->options;
		Functions\when( 'get_option' )->alias(
			fn ( $k, $d = false ) => $options->offsetExists( $k ) ? $options->offsetGet( $k ) : $d
		);
		Functions\when( 'wp_unique_id' )->justReturn( 'test1' );
		Functions\when( 'esc_attr' )->alias( fn ( $s ) => htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ) );
		Functions\when( 'esc_html' )->alias( fn ( $s ) => htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ) );
		Functions\when( 'esc_url_raw' )->alias( fn ( $s ) => (string) $s );
		Functions\when( 'rest_url' )->alias( fn ( $p ) => 'https://example.test/wp-json/' . ltrim( (string) $p, '/' ) );
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce123' );
		Functions\when( 'wp_enqueue_style' )->justReturn( true );
		Functions\when( 'wp_enqueue_script' )->justReturn( true );
		Functions\when( 'wp_localize_script' )->justReturn( true );
		Functions\when( 'plugin_dir_url' )->justReturn( WOOCOMMERCE_SHOPWALK_PLUGIN_URL );
		Functions\when( 'shortcode_atts' )->alias(
			function ( $defaults, $atts, $tag = '' ) {
				$atts = is_array( $atts ) ? $atts : array();
				return array_merge( $defaults, array_intersect_key( $atts, $defaults ) );
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_render_container_emits_section_with_data_attributes(): void {
		$html = Shopwalk_Recommendations_Block_Handler::render_container(
			array(
				'type'       => 'related',
				'product_id' => 123,
				'count'      => 4,
				'layout'     => 'grid',
				'title'      => 'You might also like',
			)
		);

		$this->assertStringContainsString( '<section', $html );
		$this->assertStringContainsString( 'data-shopwalk-recs', $html );
		$this->assertStringContainsString( 'data-type="related"', $html );
		$this->assertStringContainsString( 'data-product-id="123"', $html );
		$this->assertStringContainsString( 'data-count="4"', $html );
		$this->assertStringContainsString( 'data-layout="grid"', $html );
		$this->assertStringContainsString( 'shopwalk-recommendations--grid', $html );
		$this->assertStringContainsString( 'You might also like', $html );
		// One skeleton per requested item.
		$this->assertSame( 4, substr_count( $html, 'shopwalk-recommendations__skeleton-image' ) );
	}

	public function test_render_container_returns_empty_for_free_tier(): void {
		// Force Free tier by clearing license + plan in-place.
		foreach ( array_keys( (array) $this->options ) as $k ) {
			$this->options->offsetUnset( $k );
		}

		$html = Shopwalk_Recommendations_Block_Handler::render_container(
			array(
				'type'       => 'related',
				'product_id' => 123,
				'count'      => 4,
				'layout'     => 'grid',
				'title'      => '',
			)
		);

		$this->assertSame( '', $html );
	}

	public function test_render_container_clamps_count_to_24(): void {
		$html = Shopwalk_Recommendations_Block_Handler::render_container(
			array(
				'type'       => 'also_viewed',
				'product_id' => 1,
				'count'      => 1000,
				'layout'     => 'carousel',
				'title'      => '',
			)
		);

		$this->assertStringContainsString( 'data-count="24"', $html );
		$this->assertSame( 24, substr_count( $html, 'shopwalk-recommendations__skeleton-image' ) );
	}

	public function test_render_container_falls_back_to_related_for_bad_type(): void {
		$html = Shopwalk_Recommendations_Block_Handler::render_container(
			array(
				'type'       => 'totally_bogus',
				'product_id' => 7,
				'count'      => 3,
				'layout'     => 'carousel',
				'title'      => '',
			)
		);

		$this->assertStringContainsString( 'data-type="related"', $html );
	}

	public function test_shortcode_renders_container_with_provided_attrs(): void {
		$html = Shopwalk_Recommendations_Block_Handler::render_shortcode(
			array(
				'type'       => 'fbt',
				'product_id' => '42',
				'count'      => '5',
				'layout'     => 'list',
				'title'      => 'Buy together',
			)
		);

		$this->assertStringContainsString( 'data-type="fbt"', $html );
		$this->assertStringContainsString( 'data-product-id="42"', $html );
		$this->assertStringContainsString( 'data-count="5"', $html );
		$this->assertStringContainsString( 'shopwalk-recommendations--list', $html );
		$this->assertStringContainsString( 'Buy together', $html );
	}
}

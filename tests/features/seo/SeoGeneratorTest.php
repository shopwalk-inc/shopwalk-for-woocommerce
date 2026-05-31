<?php
/**
 * Tests for Shopwalk_Seo_Generator.
 *
 * Focus areas:
 *   - build_payload() pulls product context off a WC product
 *   - apply() routes meta to the active SEO target's postmeta keys
 *   - apply_image_alts() respects the "don't overwrite non-empty alt"
 *     rule unless force_overwrite is true
 *
 * @package ShopwalkWooCommerce
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

defined( 'SHOPWALK_API_BASE' ) || define( 'SHOPWALK_API_BASE', 'https://api.shopwalk.test/api/v1' );
defined( 'WOOCOMMERCE_SHOPWALK_VERSION' ) || define( 'WOOCOMMERCE_SHOPWALK_VERSION', '4.5.0-test' );

require_once __DIR__ . '/../../../includes/features/seo/class-seo-conflict-detector.php';
require_once __DIR__ . '/../../../includes/features/seo/class-seo-api-client.php';
require_once __DIR__ . '/../../../includes/features/seo/class-seo-generator.php';

// ─── Test doubles ───────────────────────────────────────────────────────────

if ( ! class_exists( 'SeoTestProduct' ) ) {
	/**
	 * Minimal WC_Product test double — exposes only the surface
	 * Shopwalk_Seo_Generator reads from.
	 */
	class SeoTestProduct {
		public int $id;
		public string $name;
		public string $desc;
		public string $short;
		public string $sku;
		public int $image_id;
		public array $gallery_ids;
		public array $cat_ids;
		public array $attrs;

		public function __construct( array $args ) {
			$this->id          = (int) ( $args['id'] ?? 1 );
			$this->name        = (string) ( $args['name'] ?? '' );
			$this->desc        = (string) ( $args['desc'] ?? '' );
			$this->short       = (string) ( $args['short'] ?? '' );
			$this->sku         = (string) ( $args['sku'] ?? '' );
			$this->image_id    = (int) ( $args['image_id'] ?? 0 );
			$this->gallery_ids = (array) ( $args['gallery_ids'] ?? array() );
			$this->cat_ids     = (array) ( $args['cat_ids'] ?? array() );
			$this->attrs       = (array) ( $args['attrs'] ?? array() );
		}

		public function get_name(): string {
			return $this->name; }
		public function get_description(): string {
			return $this->desc; }
		public function get_short_description(): string {
			return $this->short; }
		public function get_sku(): string {
			return $this->sku; }
		public function get_image_id(): int {
			return $this->image_id; }
		public function get_gallery_image_ids(): array {
			return $this->gallery_ids; }
		public function get_category_ids(): array {
			return $this->cat_ids; }
		public function get_attributes(): array {
			return $this->attrs; }
	}
}

final class SeoGeneratorTest extends TestCase {

	/** @var array<string,array<string,string>> postmeta[post_id][meta_key] = value */
	private array $postmeta = array();

	/** @var array<int,string> attachment_id => url */
	private array $attachment_urls = array();

	/** @var SeoTestProduct|null */
	private ?SeoTestProduct $product = null;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->postmeta        = array();
		$this->attachment_urls = array(
			11 => 'https://merchant.example/wp-content/uploads/featured.jpg',
			12 => 'https://merchant.example/wp-content/uploads/gallery-a.jpg',
			13 => 'https://merchant.example/wp-content/uploads/gallery-b.jpg',
		);
		$this->product         = new SeoTestProduct(
			array(
				'id'          => 42,
				'name'        => 'Blue Cotton T-shirt',
				'desc'        => 'A soft blue cotton t-shirt.',
				'short'       => 'Soft blue tee.',
				'sku'         => 'TEE-BLUE',
				'image_id'    => 11,
				'gallery_ids' => array( 12, 13 ),
				'cat_ids'     => array( 7 ),
				'attrs'       => array(
					'color' => 'blue',
					'size'  => array( 'S', 'M', 'L' ),
				),
			)
		);

		$urls     = &$this->attachment_urls;
		$postmeta = &$this->postmeta;
		$product  = $this->product;

		Functions\when( 'wc_get_product' )->alias(
			function ( $id ) use ( $product ) {
				return ( (int) $id === $product->id ) ? $product : null;
			}
		);

		Functions\when( 'wp_get_attachment_url' )->alias(
			function ( $id ) use ( &$urls ) {
				return $urls[ (int) $id ] ?? '';
			}
		);

		Functions\when( 'get_term' )->alias(
			function ( $id, $taxonomy ) {
				$known = array( 7 => (object) array( 'slug' => 'shirts' ) );
				return $known[ (int) $id ] ?? null;
			}
		);
		Functions\when( 'is_wp_error' )->alias(
			function ( $thing ) {
				return $thing instanceof WP_Error;
			}
		);
		Functions\when( 'get_locale' )->justReturn( 'en_US' );

		Functions\when( 'get_post_meta' )->alias(
			function ( $post_id, $key, $single = false ) use ( &$postmeta ) {
				$v = $postmeta[ (int) $post_id ][ $key ] ?? '';
				return $v;
			}
		);
		Functions\when( 'update_post_meta' )->alias(
			function ( $post_id, $key, $value ) use ( &$postmeta ) {
				$postmeta[ (int) $post_id ][ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'apply_filters' )->alias(
			function ( $hook, $value ) {
				return $value;
			}
		);
		Functions\when( '__' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_build_payload_collects_product_context(): void {
		$built = Shopwalk_Seo_Generator::build_payload(
			42,
			array( 'meta_title', 'meta_description', 'image_alt', 'seo_checklist' ),
			'cotton tee'
		);

		$this->assertTrue( $built['ok'] );
		$p = $built['payload'];
		$this->assertSame( 42, $p['product_id'] );
		$this->assertSame( 'Blue Cotton T-shirt', $p['product_title'] );
		$this->assertSame( 'TEE-BLUE', $p['product_sku'] );
		$this->assertSame( 'cotton tee', $p['focus_keyphrase'] );
		$this->assertSame( array( 'shirts' ), $p['product_categories'] );

		// Image urls follow attachment order: featured then gallery.
		$this->assertSame(
			array(
				'https://merchant.example/wp-content/uploads/featured.jpg',
				'https://merchant.example/wp-content/uploads/gallery-a.jpg',
				'https://merchant.example/wp-content/uploads/gallery-b.jpg',
			),
			$p['image_urls']
		);

		// Attributes flow through as-is.
		$this->assertSame( 'blue', $p['product_attributes']['color'] );
		$this->assertSame( array( 'S', 'M', 'L' ), $p['product_attributes']['size'] );
	}

	public function test_build_payload_missing_product(): void {
		$built = Shopwalk_Seo_Generator::build_payload( 9999, array( 'meta_title' ) );
		$this->assertFalse( $built['ok'] );
	}

	public function test_apply_writes_to_fallback_fields_by_default(): void {
		$result = Shopwalk_Seo_Generator::apply(
			42,
			array(
				'meta_title'       => 'Blue Cotton T-shirt — Soft & Breathable | Acme',
				'meta_description' => 'Our blue cotton tee is soft, breathable, and machine washable.',
			),
			array(
				'meta_title'       => true,
				'meta_description' => true,
			)
		);

		$this->assertTrue( $result['ok'] );
		$this->assertSame( Shopwalk_Seo_Conflict_Detector::TARGET_FALLBACK, $result['target'] );
		$this->assertSame( 'Blue Cotton T-shirt — Soft & Breathable | Acme', $this->postmeta[42]['_shopwalk_seo_title'] );
		$this->assertSame( 'Our blue cotton tee is soft, breathable, and machine washable.', $this->postmeta[42]['_shopwalk_seo_metadesc'] );
	}

	public function test_apply_routes_to_yoast_when_active(): void {
		Functions\when( 'apply_filters' )->alias(
			function ( $hook, $value ) {
				return 'shopwalk_seo_active_target' === $hook ? Shopwalk_Seo_Conflict_Detector::TARGET_YOAST : $value;
			}
		);

		Shopwalk_Seo_Generator::apply(
			42,
			array(
				'meta_title'       => 'T',
				'meta_description' => 'D',
				'focus_keyphrase'  => 'k',
			),
			array(
				'meta_title'       => true,
				'meta_description' => true,
				'focus_keyphrase'  => true,
			)
		);

		$this->assertSame( 'T', $this->postmeta[42]['_yoast_wpseo_title'] );
		$this->assertSame( 'D', $this->postmeta[42]['_yoast_wpseo_metadesc'] );
		$this->assertSame( 'k', $this->postmeta[42]['_yoast_wpseo_focuskw'] );
	}

	public function test_apply_image_alts_skips_non_empty_without_force(): void {
		$this->postmeta[12] = array( '_wp_attachment_image_alt' => 'existing alt' );

		$updated = Shopwalk_Seo_Generator::apply_image_alts(
			$this->product,
			array(
				11 => 'new featured alt',
				12 => 'should NOT overwrite',
				13 => 'new gallery-b alt',
			),
			false
		);

		$this->assertSame( 2, $updated );
		$this->assertSame( 'new featured alt', $this->postmeta[11]['_wp_attachment_image_alt'] );
		$this->assertSame( 'existing alt', $this->postmeta[12]['_wp_attachment_image_alt'] );
		$this->assertSame( 'new gallery-b alt', $this->postmeta[13]['_wp_attachment_image_alt'] );
	}

	public function test_apply_image_alts_overwrites_when_forced(): void {
		$this->postmeta[12] = array( '_wp_attachment_image_alt' => 'existing alt' );

		$updated = Shopwalk_Seo_Generator::apply_image_alts(
			$this->product,
			array( 12 => 'forced new alt' ),
			true
		);

		$this->assertSame( 1, $updated );
		$this->assertSame( 'forced new alt', $this->postmeta[12]['_wp_attachment_image_alt'] );
	}

	public function test_apply_image_alts_accepts_url_keys(): void {
		$updated = Shopwalk_Seo_Generator::apply_image_alts(
			$this->product,
			array(
				'https://merchant.example/wp-content/uploads/featured.jpg' => 'url-keyed alt',
			),
			false
		);
		$this->assertSame( 1, $updated );
		$this->assertSame( 'url-keyed alt', $this->postmeta[11]['_wp_attachment_image_alt'] );
	}

	public function test_apply_image_alts_rejects_foreign_attachments(): void {
		// Attachment 9999 is not part of this product — backend can't stomp it.
		$updated = Shopwalk_Seo_Generator::apply_image_alts(
			$this->product,
			array( 9999 => 'malicious alt' ),
			true
		);
		$this->assertSame( 0, $updated );
		$this->assertArrayNotHasKey( 9999, $this->postmeta );
	}
}

<?php
/**
 * Tests for Shopwalk_Seo_Conflict_Detector.
 *
 * Asserts the SEO-plugin detection matrix and the postmeta field-key
 * routing. No WP runtime required — Brain\Monkey stubs `apply_filters`.
 *
 * @package ShopwalkWooCommerce
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../includes/features/seo/class-seo-conflict-detector.php';

final class SeoConflictDetectorTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// apply_filters: identity by default so detect() flows through.
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

	public function test_fallback_when_no_seo_plugin_active(): void {
		$this->assertSame(
			Shopwalk_Seo_Conflict_Detector::TARGET_FALLBACK,
			Shopwalk_Seo_Conflict_Detector::detect()
		);
		$this->assertSame( '_shopwalk_seo_title', Shopwalk_Seo_Conflict_Detector::field_key( 'title' ) );
		$this->assertSame( '_shopwalk_seo_metadesc', Shopwalk_Seo_Conflict_Detector::field_key( 'description' ) );
		$this->assertNull( Shopwalk_Seo_Conflict_Detector::field_key( 'focus' ) );
		$this->assertTrue( Shopwalk_Seo_Conflict_Detector::is_fallback() );
	}

	public function test_yoast_target_via_filter(): void {
		Functions\when( 'apply_filters' )->alias(
			function ( $hook, $value ) {
				return 'shopwalk_seo_active_target' === $hook ? Shopwalk_Seo_Conflict_Detector::TARGET_YOAST : $value;
			}
		);
		$this->assertSame( Shopwalk_Seo_Conflict_Detector::TARGET_YOAST, Shopwalk_Seo_Conflict_Detector::active_target() );
		$this->assertSame( '_yoast_wpseo_title', Shopwalk_Seo_Conflict_Detector::field_key( 'title' ) );
		$this->assertSame( '_yoast_wpseo_metadesc', Shopwalk_Seo_Conflict_Detector::field_key( 'description' ) );
		$this->assertSame( '_yoast_wpseo_focuskw', Shopwalk_Seo_Conflict_Detector::field_key( 'focus' ) );
		$this->assertFalse( Shopwalk_Seo_Conflict_Detector::is_fallback() );
	}

	public function test_rankmath_target_via_filter(): void {
		Functions\when( 'apply_filters' )->alias(
			function ( $hook, $value ) {
				return 'shopwalk_seo_active_target' === $hook ? Shopwalk_Seo_Conflict_Detector::TARGET_RANKMATH : $value;
			}
		);
		$this->assertSame( 'rank_math_title', Shopwalk_Seo_Conflict_Detector::field_key( 'title' ) );
		$this->assertSame( 'rank_math_description', Shopwalk_Seo_Conflict_Detector::field_key( 'description' ) );
		$this->assertSame( 'rank_math_focus_keyword', Shopwalk_Seo_Conflict_Detector::field_key( 'focus' ) );
	}

	public function test_aioseo_target_via_filter(): void {
		Functions\when( 'apply_filters' )->alias(
			function ( $hook, $value ) {
				return 'shopwalk_seo_active_target' === $hook ? Shopwalk_Seo_Conflict_Detector::TARGET_AIOSEO : $value;
			}
		);
		$this->assertSame( '_aioseo_title', Shopwalk_Seo_Conflict_Detector::field_key( 'title' ) );
		$this->assertSame( '_aioseo_description', Shopwalk_Seo_Conflict_Detector::field_key( 'description' ) );
		$this->assertSame( '_aioseo_keyphrases', Shopwalk_Seo_Conflict_Detector::field_key( 'focus' ) );
	}

	public function test_filter_ignored_for_unknown_target(): void {
		Functions\when( 'apply_filters' )->alias(
			function ( $hook, $value ) {
				return 'shopwalk_seo_active_target' === $hook ? 'unknown_plugin' : $value;
			}
		);
		// Unknown filter value must not poison routing — falls back to detect().
		$this->assertSame(
			Shopwalk_Seo_Conflict_Detector::TARGET_FALLBACK,
			Shopwalk_Seo_Conflict_Detector::active_target()
		);
	}

	public function test_targets_matrix_is_complete(): void {
		foreach ( Shopwalk_Seo_Conflict_Detector::TARGETS as $slug => $row ) {
			$this->assertArrayHasKey( 'title', $row, "missing title for $slug" );
			$this->assertArrayHasKey( 'description', $row, "missing description for $slug" );
			$this->assertArrayHasKey( 'focus', $row, "missing focus key for $slug" );
			$this->assertArrayHasKey( 'label', $row, "missing label for $slug" );
		}
	}
}

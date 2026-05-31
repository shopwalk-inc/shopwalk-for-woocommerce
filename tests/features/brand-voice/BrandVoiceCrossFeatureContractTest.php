<?php
/**
 * Unit tests — `Shopwalk_Brand_Voice` cross-feature accessor contract.
 *
 * This test file is the canary on the cross-feature contract — any change
 * to the signatures or behaviors of `is_trained()` / `get_active_voice_id()`
 * / `get_status()` / `get_profile_summary()` will break downstream features
 * (product descriptions, SEO meta, authoring) shipped from parallel sessions.
 *
 * If you find yourself editing this test to fit a new return shape, STOP —
 * coordinate a deprecation cycle first. See the contract docblock at the
 * top of `includes/features/brand-voice/class-brand-voice-cross-feature.php`.
 *
 * @package ShopwalkWooCommerce
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/../../../' );
defined( 'WOOCOMMERCE_SHOPWALK_VERSION' ) || define( 'WOOCOMMERCE_SHOPWALK_VERSION', '4.6.0-test' );

require_once __DIR__ . '/../../../includes/features/brand-voice/class-brand-voice-cross-feature.php';

final class BrandVoiceCrossFeatureContractTest extends TestCase {

	/** @var array<string,mixed> */
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->options = array();
		$opts          = &$this->options;

		Functions\when( 'get_option' )->alias(
			function ( $key, $default = false ) use ( &$opts ) {
				return array_key_exists( $key, $opts ) ? $opts[ $key ] : $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $key, $value ) use ( &$opts ) {
				$opts[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( $key ) use ( &$opts ) {
				unset( $opts[ $key ] );
				return true;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_is_trained_false_by_default(): void {
		$this->assertFalse( Shopwalk_Brand_Voice::is_trained() );
		$this->assertSame( 'untrained', Shopwalk_Brand_Voice::get_status() );
		$this->assertNull( Shopwalk_Brand_Voice::get_active_voice_id() );
	}

	public function test_is_trained_false_while_training(): void {
		Shopwalk_Brand_Voice::_set_training();
		$this->assertSame( 'training', Shopwalk_Brand_Voice::get_status() );
		$this->assertFalse( Shopwalk_Brand_Voice::is_trained() );
		$this->assertNull( Shopwalk_Brand_Voice::get_active_voice_id() );
	}

	public function test_is_trained_true_after_set_ready(): void {
		Shopwalk_Brand_Voice::_set_ready(
			'voice-uuid-123',
			array(
				'voice_id'       => 'voice-uuid-123',
				'trained_at'     => '2026-05-30T12:00:00Z',
				'sample_output'  => 'A friendly sample paragraph.',
				'corpus_summary' => array( 'word_count' => 6000, 'doc_count' => 12 ),
			),
			'hash-abc'
		);
		$this->assertTrue( Shopwalk_Brand_Voice::is_trained() );
		$this->assertSame( 'ready', Shopwalk_Brand_Voice::get_status() );
		$this->assertSame( 'voice-uuid-123', Shopwalk_Brand_Voice::get_active_voice_id() );
	}

	public function test_is_trained_remains_true_when_stale(): void {
		// "stale" means the prior voice_id is still usable — the contract
		// promises generation calls don't break mid-edit.
		Shopwalk_Brand_Voice::_set_ready(
			'voice-uuid-123',
			array( 'voice_id' => 'voice-uuid-123' ),
			'hash-abc'
		);
		Shopwalk_Brand_Voice::_mark_stale();

		$this->assertSame( 'stale', Shopwalk_Brand_Voice::get_status() );
		$this->assertTrue( Shopwalk_Brand_Voice::is_trained() );
		$this->assertSame( 'voice-uuid-123', Shopwalk_Brand_Voice::get_active_voice_id() );
	}

	public function test_failed_status_makes_voice_unavailable(): void {
		Shopwalk_Brand_Voice::_set_ready(
			'voice-uuid-123',
			array( 'voice_id' => 'voice-uuid-123' ),
			'hash-abc'
		);
		Shopwalk_Brand_Voice::_set_failed( 'backend error' );

		$this->assertSame( 'failed', Shopwalk_Brand_Voice::get_status() );
		$this->assertFalse( Shopwalk_Brand_Voice::is_trained() );
		$this->assertNull( Shopwalk_Brand_Voice::get_active_voice_id() );
	}

	public function test_reset_wipes_everything(): void {
		Shopwalk_Brand_Voice::_set_ready(
			'voice-uuid-xyz',
			array( 'voice_id' => 'voice-uuid-xyz', 'sample_output' => 'hello' ),
			'hash-xyz'
		);
		Shopwalk_Brand_Voice::_reset();

		$this->assertSame( 'untrained', Shopwalk_Brand_Voice::get_status() );
		$this->assertFalse( Shopwalk_Brand_Voice::is_trained() );
		$this->assertNull( Shopwalk_Brand_Voice::get_active_voice_id() );
	}

	public function test_profile_summary_has_documented_shape_when_empty(): void {
		$profile = Shopwalk_Brand_Voice::get_profile_summary();
		$this->assertArrayHasKey( 'voice_id', $profile );
		$this->assertArrayHasKey( 'trained_at', $profile );
		$this->assertArrayHasKey( 'sample_output', $profile );
		$this->assertArrayHasKey( 'corpus_summary', $profile );
		$this->assertArrayHasKey( 'word_count', $profile['corpus_summary'] );
		$this->assertArrayHasKey( 'doc_count', $profile['corpus_summary'] );

		$this->assertNull( $profile['voice_id'] );
		$this->assertNull( $profile['trained_at'] );
		$this->assertNull( $profile['sample_output'] );
		$this->assertSame( 0, $profile['corpus_summary']['word_count'] );
		$this->assertSame( 0, $profile['corpus_summary']['doc_count'] );
	}

	public function test_profile_summary_reflects_stored_data(): void {
		Shopwalk_Brand_Voice::_set_ready(
			'voice-uuid-42',
			array(
				'voice_id'       => 'voice-uuid-42',
				'trained_at'     => '2026-05-30T18:00:00Z',
				'sample_output'  => 'Sample preview.',
				'corpus_summary' => array( 'word_count' => 7200, 'doc_count' => 14 ),
			),
			'hash'
		);
		$profile = Shopwalk_Brand_Voice::get_profile_summary();
		$this->assertSame( 'voice-uuid-42', $profile['voice_id'] );
		$this->assertSame( '2026-05-30T18:00:00Z', $profile['trained_at'] );
		$this->assertSame( 'Sample preview.', $profile['sample_output'] );
		$this->assertSame( 7200, $profile['corpus_summary']['word_count'] );
		$this->assertSame( 14, $profile['corpus_summary']['doc_count'] );
	}

	public function test_profile_summary_tolerates_missing_corpus_subkeys_from_server(): void {
		Shopwalk_Brand_Voice::_set_ready( 'v', array( 'voice_id' => 'v' ), 'h' );
		$p = Shopwalk_Brand_Voice::get_profile_summary();
		$this->assertSame( 0, $p['corpus_summary']['word_count'] );
		$this->assertSame( 0, $p['corpus_summary']['doc_count'] );
	}

	public function test_unknown_status_value_is_normalized_to_untrained(): void {
		// Defensive — if a future server starts writing a status value we
		// don't know about, the contract pins us to "untrained" rather than
		// surfacing a string downstream features don't expect.
		$this->options[ Shopwalk_Brand_Voice::OPTION_STATUS ] = 'rolling-out';
		$this->assertSame( 'untrained', Shopwalk_Brand_Voice::get_status() );
	}
}

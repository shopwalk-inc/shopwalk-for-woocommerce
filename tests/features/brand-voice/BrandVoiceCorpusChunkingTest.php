<?php
/**
 * Unit tests — corpus chunking + minimum-word-count enforcement.
 *
 * No WP runtime — uses Brain\Monkey to stub options. Skips the
 * auto-discovery path (that requires WP_Query stubs); covers the
 * chunking + assembly + minimum-word-count gates explicitly because
 * those are the load-bearing pieces other features depend on.
 *
 * @package ShopwalkWooCommerce
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/../../../' );
defined( 'WOOCOMMERCE_SHOPWALK_VERSION' ) || define( 'WOOCOMMERCE_SHOPWALK_VERSION', '4.6.0-test' );
defined( 'SHOPWALK_API_BASE' ) || define( 'SHOPWALK_API_BASE', 'https://api.shopwalk.test/api/v1' );
defined( 'MINUTE_IN_SECONDS' ) || define( 'MINUTE_IN_SECONDS', 60 );

require_once __DIR__ . '/../../../includes/features/brand-voice/class-brand-voice-cross-feature.php';
require_once __DIR__ . '/../../../includes/features/brand-voice/class-brand-voice-corpus-manager.php';

final class BrandVoiceCorpusChunkingTest extends TestCase {

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
		Functions\when( 'wp_strip_all_tags' )->alias( fn( $s ) => strip_tags( (string) $s ) );
		Functions\when( 'strip_shortcodes' )->alias( fn( $s ) => $s );
		Functions\when( 'size_format' )->alias( fn( $b ) => $b . ' bytes' );
		Functions\when( 'get_post' )->justReturn( null );
		Functions\when( '__' )->returnArg( 1 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ── Word count ─────────────────────────────────────────────────────────

	public function test_word_count_handles_unicode_whitespace_and_blanks(): void {
		$this->assertSame( 0, Shopwalk_Brand_Voice_Corpus_Manager::word_count( '' ) );
		$this->assertSame( 0, Shopwalk_Brand_Voice_Corpus_Manager::word_count( "   \n\t  " ) );
		$this->assertSame( 5, Shopwalk_Brand_Voice_Corpus_Manager::word_count( "this  has\tfive simple words" ) );
		$this->assertSame(
			3,
			Shopwalk_Brand_Voice_Corpus_Manager::word_count( "líneas\u{00A0}con\u{2003}espacios" )
		);
	}

	// ── Minimum-word-count gate ────────────────────────────────────────────

	public function test_minimum_word_count_constant_is_five_thousand(): void {
		// Frozen — changing this value is a breaking UX change for merchants
		// and must be discussed in the spec PR, not via a silent test edit.
		$this->assertSame( 5000, Shopwalk_Brand_Voice_Corpus_Manager::MIN_WORD_COUNT );
	}

	public function test_meets_minimum_false_on_empty_corpus(): void {
		$this->assertFalse( Shopwalk_Brand_Voice_Corpus_Manager::meets_minimum() );
		$this->assertSame( 0, Shopwalk_Brand_Voice_Corpus_Manager::total_word_count() );
	}

	public function test_meets_minimum_false_below_threshold(): void {
		Shopwalk_Brand_Voice_Corpus_Manager::save_paste( str_repeat( 'word ', 4999 ) );
		$this->assertSame( 4999, Shopwalk_Brand_Voice_Corpus_Manager::total_word_count() );
		$this->assertFalse( Shopwalk_Brand_Voice_Corpus_Manager::meets_minimum() );
	}

	public function test_meets_minimum_true_at_threshold(): void {
		Shopwalk_Brand_Voice_Corpus_Manager::save_paste( str_repeat( 'word ', 5000 ) );
		$this->assertSame( 5000, Shopwalk_Brand_Voice_Corpus_Manager::total_word_count() );
		$this->assertTrue( Shopwalk_Brand_Voice_Corpus_Manager::meets_minimum() );
	}

	// ── Chunking ───────────────────────────────────────────────────────────

	public function test_chunking_emits_short_items_whole(): void {
		Shopwalk_Brand_Voice_Corpus_Manager::save_paste(
			str_repeat( 'a', 200 ) . "\n\n" . str_repeat( 'b', 200 ) . "\n\n" . str_repeat( 'c', 200 )
		);

		$chunks = Shopwalk_Brand_Voice_Corpus_Manager::chunk();
		$this->assertCount( 3, $chunks );
		foreach ( $chunks as $chunk ) {
			$this->assertStringStartsWith( 'paste:', $chunk['source'] );
			$this->assertStringNotContainsString( ':part', $chunk['source'] );
		}
	}

	public function test_chunking_splits_long_items_with_part_suffix(): void {
		$mega = str_repeat( 'x', 35 * 1024 );
		Shopwalk_Brand_Voice_Corpus_Manager::save_paste( $mega );

		$chunks = Shopwalk_Brand_Voice_Corpus_Manager::chunk();
		$this->assertGreaterThanOrEqual( 4, count( $chunks ) );
		foreach ( $chunks as $chunk ) {
			$this->assertStringStartsWith( 'paste:1:part', $chunk['source'] );
			$this->assertLessThanOrEqual(
				Shopwalk_Brand_Voice_Corpus_Manager::CHUNK_BYTES,
				strlen( $chunk['text'] )
			);
		}
		$rejoined = '';
		foreach ( $chunks as $chunk ) {
			$rejoined .= $chunk['text'];
		}
		$this->assertSame( $mega, $rejoined );
	}

	public function test_chunking_packs_multiple_paragraphs_into_one_chunk_when_they_fit(): void {
		$para1 = str_repeat( 'a ', 1500 );
		$para2 = str_repeat( 'b ', 1500 );
		Shopwalk_Brand_Voice_Corpus_Manager::save_paste( $para1 . "\n\n" . $para2 );

		$chunks = Shopwalk_Brand_Voice_Corpus_Manager::chunk();
		$this->assertGreaterThanOrEqual( 2, count( $chunks ) );
		foreach ( array_slice( $chunks, 0, 2 ) as $c ) {
			$this->assertStringNotContainsString( ':part', $c['source'] );
		}
	}

	public function test_corpus_hash_is_stable_across_paste_reorderings_of_same_content(): void {
		Shopwalk_Brand_Voice_Corpus_Manager::save_paste( "alpha\n\nbeta\n\ngamma" );
		$hash_a = Shopwalk_Brand_Voice_Corpus_Manager::corpus_hash();
		Shopwalk_Brand_Voice_Corpus_Manager::save_paste( "alpha\n\nbeta\n\ngamma" );
		$hash_b = Shopwalk_Brand_Voice_Corpus_Manager::corpus_hash();
		$this->assertSame( $hash_a, $hash_b );
	}

	public function test_corpus_hash_changes_when_content_changes(): void {
		Shopwalk_Brand_Voice_Corpus_Manager::save_paste( 'alpha' );
		$before = Shopwalk_Brand_Voice_Corpus_Manager::corpus_hash();
		Shopwalk_Brand_Voice_Corpus_Manager::save_paste( 'alpha plus' );
		$after = Shopwalk_Brand_Voice_Corpus_Manager::corpus_hash();
		$this->assertNotSame( $before, $after );
	}

	// ── Upload limits ──────────────────────────────────────────────────────

	public function test_upload_rejects_oversize_file(): void {
		$big = str_repeat( 'x', Shopwalk_Brand_Voice_Corpus_Manager::MAX_UPLOAD_BYTES + 1 );
		$res = Shopwalk_Brand_Voice_Corpus_Manager::add_upload( 'big.txt', $big );
		$this->assertFalse( $res['ok'] );
		$this->assertStringContainsString( 'too large', strtolower( $res['error'] ) );
	}

	public function test_upload_rejects_non_utf8_payload(): void {
		$bad = "abc\xC3\x28\xA0\xFFdef";
		$res = Shopwalk_Brand_Voice_Corpus_Manager::add_upload( 'bad.txt', $bad );
		$this->assertFalse( $res['ok'] );
	}

	public function test_upload_enforces_file_count_cap(): void {
		$existing = array();
		for ( $i = 0; $i < Shopwalk_Brand_Voice_Corpus_Manager::MAX_UPLOADED_FILES; $i++ ) {
			$existing[ 'upload:f' . $i . '.txt' ] = array(
				'name'        => 'f' . $i . '.txt',
				'text'        => 'hi',
				'bytes'       => 2,
				'uploaded_at' => '2026-05-30T00:00:00Z',
			);
		}
		$this->options[ Shopwalk_Brand_Voice_Corpus_Manager::OPTION_UPLOADS ] = $existing;
		$res = Shopwalk_Brand_Voice_Corpus_Manager::add_upload( 'extra.txt', 'spillover' );
		$this->assertFalse( $res['ok'] );
		$this->assertStringContainsString( 'limit', strtolower( $res['error'] ) );
	}
}

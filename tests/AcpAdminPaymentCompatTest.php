<?php
/**
 * Tests for Shopwalk_ACP_Admin::detect_payment_compat() — the WC payment
 * gateway introspection that decides "Full in-chat checkout" vs "Deep-link
 * handoff" in the ACP status panel.
 *
 * Per the ACP spec (SHOPWALK_ACP_INTEGRATION.md §5), only WooPayments or
 * the Stripe gateway qualify as "full" for ACP shared payment tokens.
 * This is informational only — it does NOT gate ACP eligibility. A store
 * with no Stripe gateway is still in the feed; ChatGPT just deep-links
 * to the store's own checkout instead of finishing in-chat.
 *
 * @package ShopwalkWooCommerce
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

defined( 'SHOPWALK_API_BASE' ) || define( 'SHOPWALK_API_BASE', 'https://api.shopwalk.test/api/v1' );
defined( 'WOOCOMMERCE_SHOPWALK_VERSION' ) || define( 'WOOCOMMERCE_SHOPWALK_VERSION', '3.1.16-test' );
defined( 'WOOCOMMERCE_SHOPWALK_PLUGIN_DIR' ) || define( 'WOOCOMMERCE_SHOPWALK_PLUGIN_DIR', __DIR__ . '/../' );

require_once __DIR__ . '/../includes/acp/class-shopwalk-acp-client.php';
require_once __DIR__ . '/../includes/acp/class-shopwalk-acp-admin.php';

/**
 * Minimal fake gateway shape: WC iterates `get_available_payment_gateways()`
 * looking at `->id` and `->enabled`. We mimic that with stdClass objects.
 */
final class FakeAcpGateway {
	public string $id;
	public string $enabled;
	public function __construct( string $id, string $enabled = 'yes' ) {
		$this->id      = $id;
		$this->enabled = $enabled;
	}
}

final class FakeAcpPaymentGateways {
	/** @var array<int,FakeAcpGateway> */
	public array $gateways;
	public function __construct( array $gateways ) {
		$this->gateways = $gateways;
	}
	public function get_available_payment_gateways(): array {
		$out = array();
		foreach ( $this->gateways as $g ) {
			$out[ $g->id ] = $g;
		}
		return $out;
	}
}

final class FakeAcpWC {
	/** @var FakeAcpPaymentGateways */
	public FakeAcpPaymentGateways $pg;
	public function __construct( FakeAcpPaymentGateways $pg ) {
		$this->pg = $pg;
	}
	public function payment_gateways(): FakeAcpPaymentGateways {
		return $this->pg;
	}
}

final class AcpAdminPaymentCompatTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

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
	 * Define a `WC()` function that returns a fake WooCommerce singleton
	 * with the given enabled gateways.
	 *
	 * @param array<int,FakeAcpGateway> $gateways Gateways to expose.
	 */
	private function set_active_gateways( array $gateways ): void {
		$wc = new FakeAcpWC( new FakeAcpPaymentGateways( $gateways ) );
		Functions\when( 'WC' )->alias(
			function () use ( $wc ) {
				return $wc;
			}
		);
	}

	public function test_returns_full_when_woocommerce_payments_is_enabled(): void {
		$this->set_active_gateways( array( new FakeAcpGateway( 'woocommerce_payments', 'yes' ) ) );
		$this->assertSame( 'full', Shopwalk_ACP_Admin::detect_payment_compat() );
	}

	public function test_returns_full_when_stripe_is_enabled(): void {
		$this->set_active_gateways( array( new FakeAcpGateway( 'stripe', 'yes' ) ) );
		$this->assertSame( 'full', Shopwalk_ACP_Admin::detect_payment_compat() );
	}

	public function test_returns_deep_link_when_only_paypal_active(): void {
		$this->set_active_gateways( array( new FakeAcpGateway( 'paypal', 'yes' ) ) );
		$this->assertSame( 'deep_link', Shopwalk_ACP_Admin::detect_payment_compat() );
	}

	public function test_returns_deep_link_when_stripe_present_but_disabled(): void {
		// Even though Stripe's class is registered, an `enabled = no` gateway
		// is a misconfiguration — we should not advertise full-in-chat for
		// a disabled processor.
		$this->set_active_gateways(
			array(
				new FakeAcpGateway( 'stripe', 'no' ),
				new FakeAcpGateway( 'paypal', 'yes' ),
			)
		);
		$this->assertSame( 'deep_link', Shopwalk_ACP_Admin::detect_payment_compat() );
	}

	public function test_returns_deep_link_when_no_gateways_returned(): void {
		// WC() exists but returns no gateways at all (fresh install before
		// any payment provider is configured) → deep_link.
		$this->set_active_gateways( array() );
		$this->assertSame( 'deep_link', Shopwalk_ACP_Admin::detect_payment_compat() );
	}
}

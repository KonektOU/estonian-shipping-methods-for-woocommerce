<?php
/**
 * Which carriers can do what on the dispatch screen.
 *
 * @package Estonian_Shipping_Methods_For_WooCommerce
 */

require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/class-wc-esm-shipment-result.php';
require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/abstracts/class-wc-esm-shipment-provider.php';
require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/class-wc-esm-shipment-registry.php';
require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/class-wc-esm-dispatch-screen.php';
require_once __DIR__ . '/test-shipment-provider.php';

/**
 * Tests for WC_ESM_Dispatch_Screen.
 */
class Test_Dispatch_Screen extends WC_ESM_Test_Case {

	/**
	 * Stub the two WordPress functions the tab helpers reach for.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		Brain\Monkey\Functions\when( 'sanitize_key' )->alias(
			static function ( $key ) {
				return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
			}
		);

		Brain\Monkey\Functions\when( 'admin_url' )->alias(
			static function ( $path ) {
				return 'https://example.com/wp-admin/' . $path;
			}
		);
	}

	/**
	 * A registry with one carrier of each shape.
	 *
	 * @return WC_ESM_Shipment_Registry
	 */
	protected function registry() {
		$registry = new WC_ESM_Shipment_Registry();
		$registry->register( new WC_ESM_Dispatch_Test_Provider() );
		$registry->register( new WC_ESM_Plain_Test_Provider() );
		$registry->register( new WC_ESM_Test_Provider() );

		return $registry;
	}

	/**
	 * Only carriers that can close a manifest are offered one.
	 *
	 * @return void
	 */
	public function test_only_carriers_with_manifests_are_offered_one() {
		$carriers = WC_ESM_Dispatch_Screen::carriers_for( 'manifest', $this->registry() );

		$this->assertSame( array( 'withmanifest' ), array_keys( $carriers ) );
	}

	/**
	 * A feature no carrier offers yields an empty list, not a broken screen.
	 *
	 * @return void
	 */
	public function test_a_feature_nobody_offers_is_empty() {
		$this->assertSame( array(), WC_ESM_Dispatch_Screen::carriers_for( 'cod_report', $this->registry() ) );
	}

	/**
	 * A carrier that can neither be picked up from nor manifested has
	 * nothing to show, so it gets no tab.
	 *
	 * @return void
	 */
	public function test_only_dispatching_carriers_get_a_tab() {
		$this->assertSame( array( 'withmanifest', 'log' ), array_keys( WC_ESM_Dispatch_Screen::tabs( $this->registry() ) ) );
	}

	/**
	 * A carrier's tab is labelled with the carrier's own title.
	 *
	 * @return void
	 */
	public function test_a_carrier_tab_is_named_after_the_carrier() {
		$tabs = WC_ESM_Dispatch_Screen::tabs( $this->registry() );

		$this->assertSame( 'Test Carrier', $tabs['withmanifest'] );
	}

	/**
	 * A tab the screen knows is the one that opens.
	 *
	 * @return void
	 */
	public function test_a_known_tab_is_kept() {
		$this->assertSame( 'log', WC_ESM_Dispatch_Screen::active_tab( 'log', $this->registry() ) );
	}

	/**
	 * A tab nobody offers - a stale bookmark, a hand-edited URL - opens the
	 * first tab rather than an empty screen.
	 *
	 * @return void
	 */
	public function test_an_unknown_tab_falls_back_to_the_first() {
		$this->assertSame( 'withmanifest', WC_ESM_Dispatch_Screen::active_tab( 'no-such-carrier', $this->registry() ) );
		$this->assertSame( 'withmanifest', WC_ESM_Dispatch_Screen::active_tab( '', $this->registry() ) );
		$this->assertSame( 'withmanifest', WC_ESM_Dispatch_Screen::active_tab( '<script>alert(1)</script>', $this->registry() ) );
	}

	/**
	 * The screen's own URL, with the tab on it, is what a redirect after a
	 * POST needs to land back where the work was done.
	 *
	 * @return void
	 */
	public function test_screen_url_carries_the_tab() {
		$this->assertSame(
			'https://example.com/wp-admin/admin.php?page=wc-esm-dispatch&tab=withmanifest',
			WC_ESM_Dispatch_Screen::screen_url( 'withmanifest' )
		);
		$this->assertSame(
			'https://example.com/wp-admin/admin.php?page=wc-esm-dispatch',
			WC_ESM_Dispatch_Screen::screen_url()
		);
	}
}

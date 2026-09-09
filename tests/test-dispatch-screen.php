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
 * A carrier offering pickups and manifests.
 */
class WC_ESM_Dispatch_Test_Provider extends WC_ESM_Test_Provider {

	/**
	 * Declared features.
	 *
	 * @var array
	 */
	protected $features = array( 'pickup', 'manifest' );

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function get_id() {
		return 'withmanifest';
	}
}

/**
 * A carrier offering nothing beyond the label.
 */
class WC_ESM_Plain_Test_Provider extends WC_ESM_Test_Provider {

	/**
	 * Declared features.
	 *
	 * @var array
	 */
	protected $features = array();

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function get_id() {
		return 'plain';
	}
}

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

	/**
	 * A manifest entry from a carrier that can still fetch one offers the
	 * download.
	 *
	 * @return void
	 */
	public function test_manifest_entry_from_capable_carrier_offers_download() {
		$actions = WC_ESM_Dispatch_Screen::log_row_actions(
			array(
				'provider'  => 'withmanifest',
				'type'      => 'manifest',
				'reference' => 'M-1',
			),
			$this->registry()
		);

		$this->assertSame( array( 'manifest_download' ), $actions );
	}

	/**
	 * A pickup entry from a carrier that can cancel offers the cancel.
	 *
	 * @return void
	 */
	public function test_pickup_entry_from_capable_carrier_offers_cancel() {
		$actions = WC_ESM_Dispatch_Screen::log_row_actions(
			array(
				'provider'  => 'withmanifest',
				'type'      => 'pickup',
				'reference' => 'P-1',
			),
			$this->registry()
		);

		$this->assertSame( array( 'pickup_cancel' ), $actions );
	}

	/**
	 * A pickup already called off offers nothing: asking the carrier twice
	 * is a second cancellation of something that is already gone.
	 *
	 * @return void
	 */
	public function test_already_cancelled_pickup_offers_nothing() {
		$actions = WC_ESM_Dispatch_Screen::log_row_actions(
			array(
				'provider'  => 'withmanifest',
				'type'      => 'pickup',
				'reference' => 'P-1',
				'cancelled' => true,
			),
			$this->registry()
		);

		$this->assertSame( array(), $actions );
	}

	/**
	 * An entry naming a carrier that is no longer registered offers nothing.
	 *
	 * @return void
	 */
	public function test_entry_with_unregistered_provider_offers_nothing() {
		$actions = WC_ESM_Dispatch_Screen::log_row_actions(
			array(
				'provider'  => 'collectnet',
				'type'      => 'manifest',
				'reference' => 'M-1',
			),
			$this->registry()
		);

		$this->assertSame( array(), $actions );
	}

	/**
	 * An entry whose carrier no longer offers the feature offers nothing:
	 * the button would fail when pressed.
	 *
	 * @return void
	 */
	public function test_entry_whose_provider_does_not_support_the_feature_offers_nothing() {
		$actions = WC_ESM_Dispatch_Screen::log_row_actions(
			array(
				'provider'  => 'plain',
				'type'      => 'manifest',
				'reference' => 'M-1',
			),
			$this->registry()
		);

		$this->assertSame( array(), $actions );
	}

	/**
	 * An entry of a kind nobody recognises offers nothing.
	 *
	 * @return void
	 */
	public function test_entry_with_an_unrecognised_type_offers_nothing() {
		$actions = WC_ESM_Dispatch_Screen::log_row_actions(
			array(
				'provider'  => 'withmanifest',
				'type'      => 'something_else',
				'reference' => 'X-1',
			),
			$this->registry()
		);

		$this->assertSame( array(), $actions );
	}

	/**
	 * find_log_entry() returns the match.
	 *
	 * @return void
	 */
	public function test_find_log_entry_returns_the_match() {
		$entry = array(
			'provider'   => 'withmanifest',
			'type'       => 'pickup',
			'reference'  => 'P-1',
			'created_at' => '2026-01-01 09:00',
		);

		$this->assertSame( $entry, WC_ESM_Dispatch_Screen::find_log_entry( array( $entry ), 'withmanifest', 'P-1' ) );
	}

	/**
	 * A miss is null, not a fatal.
	 *
	 * @return void
	 */
	public function test_find_log_entry_returns_null_on_a_miss() {
		$this->assertNull( WC_ESM_Dispatch_Screen::find_log_entry( array(), 'withmanifest', 'P-1' ) );
	}

	/**
	 * The log is keyed by nothing, so two entries with the same carrier and
	 * reference are indistinguishable. The first is returned - the guard only
	 * needs to know that at least one match exists and whether it is already
	 * cancelled.
	 *
	 * @return void
	 */
	public function test_find_log_entry_returns_the_first_of_a_collision() {
		$first  = array(
			'provider'   => 'withmanifest',
			'type'       => 'pickup',
			'reference'  => 'P-1',
			'created_at' => '2026-01-01 09:00',
		);
		$second = array(
			'provider'   => 'withmanifest',
			'type'       => 'pickup',
			'reference'  => 'P-1',
			'created_at' => '2026-01-01 10:00',
		);

		$this->assertSame( $first, WC_ESM_Dispatch_Screen::find_log_entry( array( $first, $second ), 'withmanifest', 'P-1' ) );
	}

	/**
	 * Cancelling marks every entry that collides, not only the oldest, or a
	 * second row would go on offering to cancel something already gone.
	 *
	 * @return void
	 */
	public function test_cancelling_marks_every_colliding_entry() {
		$log = array(
			array( 'provider' => 'withmanifest', 'type' => 'pickup', 'reference' => 'P-1' ),
			array( 'provider' => 'withmanifest', 'type' => 'pickup', 'reference' => 'P-1' ),
			array( 'provider' => 'withmanifest', 'type' => 'pickup', 'reference' => 'P-2' ),
		);

		$marked = WC_ESM_Dispatch_Screen::mark_cancelled_in( $log, 'withmanifest', 'P-1' );

		$this->assertTrue( $marked[0]['cancelled'] );
		$this->assertTrue( $marked[1]['cancelled'] );
		$this->assertArrayNotHasKey( 'cancelled', $marked[2] );
	}
}

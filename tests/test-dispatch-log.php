<?php
/**
 * What has been booked and closed.
 *
 * @package Estonian_Shipping_Methods_For_WooCommerce
 */

require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/class-wc-esm-shipment-result.php';
require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/abstracts/class-wc-esm-shipment-provider.php';
require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/class-wc-esm-shipment-registry.php';
require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/class-wc-esm-dispatch-log.php';
require_once __DIR__ . '/test-shipment-provider.php';

/**
 * Tests for WC_ESM_Dispatch_Log.
 */
class Test_Dispatch_Log extends WC_ESM_Test_Case {

	/**
	 * Stand an options store up, since the log lives in one.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['wc_esm_options'] = array();

		Brain\Monkey\Functions\when( 'get_option' )->alias(
			static function ( $key, $default = false ) {
				return isset( $GLOBALS['wc_esm_options'][ $key ] ) ? $GLOBALS['wc_esm_options'][ $key ] : $default;
			}
		);

		Brain\Monkey\Functions\when( 'update_option' )->alias(
			static function ( $key, $value ) {
				$GLOBALS['wc_esm_options'][ $key ] = $value;

				return true;
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

		return $registry;
	}

	/**
	 * A booking is written down, newest last.
	 *
	 * @return void
	 */
	public function test_a_booking_is_written_down() {
		WC_ESM_Dispatch_Log::record( 'withmanifest', 'pickup', 'P-1' );
		WC_ESM_Dispatch_Log::record( 'withmanifest', 'manifest', 'M-1' );

		$entries = WC_ESM_Dispatch_Log::entries();

		$this->assertCount( 2, $entries );
		$this->assertSame( 'P-1', $entries[0]['reference'] );
		$this->assertSame( 'M-1', $entries[1]['reference'] );
		$this->assertArrayHasKey( 'created_at', $entries[0] );
	}

	/**
	 * The log is a convenience, not an audit trail - the carrier's own portal
	 * is where the real record lives - so it keeps a screen's worth and lets
	 * the rest go.
	 *
	 * @return void
	 */
	public function test_the_log_keeps_a_screens_worth() {
		for ( $i = 0; $i < 25; $i++ ) {
			WC_ESM_Dispatch_Log::record( 'withmanifest', 'pickup', 'P-' . $i );
		}

		$entries = WC_ESM_Dispatch_Log::entries();

		$this->assertCount( WC_ESM_Dispatch_Log::KEEP, $entries );
		$this->assertSame( 'P-24', $entries[ count( $entries ) - 1 ]['reference'] );
	}

	/**
	 * An empty log reads as an empty list, not as false.
	 *
	 * @return void
	 */
	public function test_an_empty_log_is_an_empty_list() {
		$this->assertSame( array(), WC_ESM_Dispatch_Log::entries() );
	}

	/**
	 * A manifest entry from a carrier that can still fetch one offers the
	 * download.
	 *
	 * @return void
	 */
	public function test_manifest_entry_from_capable_carrier_offers_download() {
		$this->assertSame(
			array( 'manifest_download' ),
			WC_ESM_Dispatch_Log::row_actions(
				array( 'provider' => 'withmanifest', 'type' => 'manifest', 'reference' => 'M-1' ),
				$this->registry()
			)
		);
	}

	/**
	 * A carrier that books couriers but cannot call one off is not offered
	 * the cancel. DPD is exactly that: its API has no endpoint for it, and a
	 * button that fails when pressed is worse than no button.
	 *
	 * @return void
	 */
	public function test_a_carrier_that_cannot_cancel_is_not_offered_the_cancel() {
		$booker = new WC_ESM_Dispatch_Test_Provider();
		$booker->declare_features( array( 'pickup', 'manifest' ) );

		$registry = new WC_ESM_Shipment_Registry();
		$registry->register( $booker );

		$this->assertSame(
			array(),
			WC_ESM_Dispatch_Log::row_actions(
				array( 'provider' => 'withmanifest', 'type' => 'pickup', 'reference' => 'P-1' ),
				$registry
			)
		);
	}

	/**
	 * A manifest with no reference cannot be fetched again, so no button
	 * offers to. DPD hands the document back when the manifest is closed and
	 * gives no reference of its own.
	 *
	 * @return void
	 */
	public function test_a_manifest_with_no_reference_offers_no_download() {
		$this->assertSame(
			array(),
			WC_ESM_Dispatch_Log::row_actions(
				array( 'provider' => 'withmanifest', 'type' => 'manifest', 'reference' => '' ),
				$this->registry()
			)
		);
	}

	/**
	 * A pickup entry from a carrier that can cancel offers the cancel.
	 *
	 * @return void
	 */
	public function test_pickup_entry_from_capable_carrier_offers_cancel() {
		$this->assertSame(
			array( 'pickup_cancel' ),
			WC_ESM_Dispatch_Log::row_actions(
				array( 'provider' => 'withmanifest', 'type' => 'pickup', 'reference' => 'P-1' ),
				$this->registry()
			)
		);
	}

	/**
	 * A pickup already called off offers nothing: asking the carrier twice
	 * cancels something that is already gone.
	 *
	 * @return void
	 */
	public function test_already_cancelled_pickup_offers_nothing() {
		$this->assertSame(
			array(),
			WC_ESM_Dispatch_Log::row_actions(
				array( 'provider' => 'withmanifest', 'type' => 'pickup', 'reference' => 'P-1', 'cancelled' => true ),
				$this->registry()
			)
		);
	}

	/**
	 * An entry naming a carrier that is no longer registered offers nothing.
	 *
	 * @return void
	 */
	public function test_entry_with_unregistered_provider_offers_nothing() {
		$this->assertSame(
			array(),
			WC_ESM_Dispatch_Log::row_actions(
				array( 'provider' => 'collectnet', 'type' => 'manifest', 'reference' => 'M-1' ),
				$this->registry()
			)
		);
	}

	/**
	 * An entry whose carrier no longer offers the feature offers nothing:
	 * the button would fail when pressed.
	 *
	 * @return void
	 */
	public function test_entry_whose_provider_does_not_support_the_feature_offers_nothing() {
		$this->assertSame(
			array(),
			WC_ESM_Dispatch_Log::row_actions(
				array( 'provider' => 'plain', 'type' => 'manifest', 'reference' => 'M-1' ),
				$this->registry()
			)
		);
	}

	/**
	 * An entry of a kind nobody recognises offers nothing.
	 *
	 * @return void
	 */
	public function test_entry_with_an_unrecognised_type_offers_nothing() {
		$this->assertSame(
			array(),
			WC_ESM_Dispatch_Log::row_actions(
				array( 'provider' => 'withmanifest', 'type' => 'something_else', 'reference' => 'X-1' ),
				$this->registry()
			)
		);
	}

	/**
	 * A booking is found again by carrier and reference.
	 *
	 * @return void
	 */
	public function test_a_booking_is_found_by_carrier_and_reference() {
		WC_ESM_Dispatch_Log::record( 'withmanifest', 'pickup', 'P-1' );

		$this->assertSame( 'P-1', WC_ESM_Dispatch_Log::find( 'withmanifest', 'P-1' )['reference'] );
	}

	/**
	 * A miss is null, not a fatal.
	 *
	 * @return void
	 */
	public function test_a_miss_is_null() {
		$this->assertNull( WC_ESM_Dispatch_Log::find( 'withmanifest', 'P-1' ) );
	}

	/**
	 * The log is keyed by nothing, so two entries with the same carrier and
	 * reference are indistinguishable. The first is returned: the guard that
	 * uses this only needs to know a match exists and whether it is already
	 * cancelled.
	 *
	 * @return void
	 */
	public function test_the_first_of_a_collision_is_returned() {
		WC_ESM_Dispatch_Log::record( 'withmanifest', 'pickup', 'P-1' );
		WC_ESM_Dispatch_Log::record( 'withmanifest', 'pickup', 'P-1' );

		$GLOBALS['wc_esm_options'][ WC_ESM_Dispatch_Log::OPTION ][0]['marker'] = 'first';

		$this->assertSame( 'first', WC_ESM_Dispatch_Log::find( 'withmanifest', 'P-1' )['marker'] );
	}

	/**
	 * Cancelling marks every entry that collides, not only the oldest, or a
	 * second row would go on offering to cancel something already gone.
	 *
	 * @return void
	 */
	public function test_cancelling_marks_every_colliding_entry() {
		WC_ESM_Dispatch_Log::record( 'withmanifest', 'pickup', 'P-1' );
		WC_ESM_Dispatch_Log::record( 'withmanifest', 'pickup', 'P-1' );
		WC_ESM_Dispatch_Log::record( 'withmanifest', 'pickup', 'P-2' );

		WC_ESM_Dispatch_Log::mark_cancelled( 'withmanifest', 'P-1' );

		$entries = WC_ESM_Dispatch_Log::entries();

		$this->assertTrue( $entries[0]['cancelled'] );
		$this->assertTrue( $entries[1]['cancelled'] );
		$this->assertArrayNotHasKey( 'cancelled', $entries[2] );
	}

	/**
	 * A cancelled booking stops offering the cancel.
	 *
	 * @return void
	 */
	public function test_a_cancelled_booking_stops_offering_the_cancel() {
		WC_ESM_Dispatch_Log::record( 'withmanifest', 'pickup', 'P-1' );
		WC_ESM_Dispatch_Log::mark_cancelled( 'withmanifest', 'P-1' );

		$this->assertSame( array(), WC_ESM_Dispatch_Log::row_actions( WC_ESM_Dispatch_Log::find( 'withmanifest', 'P-1' ), $this->registry() ) );
	}
}

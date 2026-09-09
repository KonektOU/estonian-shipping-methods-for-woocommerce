<?php
/**
 * Sending an order to its carrier, once.
 *
 * @package Estonian_Shipping_Methods_For_WooCommerce
 */

require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/class-wc-esm-shipment-result.php';
require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/class-wc-esm-shipment.php';
require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/class-wc-esm-order-snapshot.php';
require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/abstracts/class-wc-esm-shipment-provider.php';
require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/class-wc-esm-shipment-registration.php';
require_once __DIR__ . '/test-shipment.php';

/**
 * A carrier that answers however the test says, and counts the asking.
 */
class WC_ESM_Answering_Provider extends WC_ESM_Shipment_Provider {

	/**
	 * What register() will answer.
	 *
	 * @var WC_ESM_Shipment_Result|null
	 */
	public $answer = null;

	/**
	 * How many times it was asked.
	 *
	 * @var int
	 */
	public $asked = 0;

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function get_id() {
		return 'answering';
	}

	/**
	 * Title.
	 *
	 * @return string
	 */
	public function get_title() {
		return 'Answering Carrier';
	}

	/**
	 * Answer as instructed.
	 *
	 * @param array $snapshot Snapshot.
	 *
	 * @return WC_ESM_Shipment_Result
	 */
	public function register( $snapshot ) {
		$this->asked++;

		return $this->answer ? $this->answer : WC_ESM_Shipment_Result::success( array( 'barcodes' => array( 'B1' ) ) );
	}
}

/**
 * An order that also records its notes.
 */
class WC_ESM_Noting_Order extends WC_ESM_Fake_Order {

	/**
	 * Notes added.
	 *
	 * @var array
	 */
	public $notes = array();

	/**
	 * Record a note.
	 *
	 * @param string $note Note.
	 *
	 * @return void
	 */
	public function add_order_note( $note ) {
		$this->notes[] = $note;
	}
}

/**
 * Tests for WC_ESM_Shipment_Registration.
 */
class Test_Shipment_Registration extends WC_ESM_Test_Case {

	/**
	 * An order that has not been sent.
	 *
	 * @return WC_ESM_Noting_Order
	 */
	protected function order() {
		return new WC_ESM_Noting_Order();
	}

	/**
	 * A carrier that accepted the parcel leaves the order registered.
	 *
	 * @return void
	 */
	public function test_an_accepted_parcel_leaves_the_order_registered() {
		$order    = $this->order();
		$provider = new WC_ESM_Answering_Provider();

		$result = WC_ESM_Shipment_Registration::send( $order, WC_ESM_Order_Snapshot::make( $this->snapshot() ), $provider );

		$this->assertTrue( $result->is_success() );
		$this->assertTrue( WC_ESM_Shipment::is_registered( $order ) );
		$this->assertSame( 1, $provider->asked );
	}

	/**
	 * An order that already has a shipment is not sent again. This is the
	 * guard that stops a browser's back button, a double click or a repeated
	 * job from booking and billing a second real parcel.
	 *
	 * @return void
	 */
	public function test_an_order_already_sent_is_not_sent_again() {
		$order    = $this->order();
		$provider = new WC_ESM_Answering_Provider();
		WC_ESM_Shipment_Registration::send( $order, WC_ESM_Order_Snapshot::make( $this->snapshot() ), $provider );

		$result = WC_ESM_Shipment_Registration::send( $order, WC_ESM_Order_Snapshot::make( $this->snapshot() ), $provider );

		$this->assertTrue( $result->is_failure() );
		$this->assertSame( 'already_registered', $result->get_code() );
		$this->assertSame( 1, $provider->asked );
	}

	/**
	 * A refusal is written where the shop can see it, and the order stays
	 * unsent so it can be tried again.
	 *
	 * @return void
	 */
	public function test_a_refusal_is_recorded_and_the_order_stays_unsent() {
		$order            = $this->order();
		$provider         = new WC_ESM_Answering_Provider();
		$provider->answer = WC_ESM_Shipment_Result::failure( 'Omniva was down.' );

		$result = WC_ESM_Shipment_Registration::send( $order, WC_ESM_Order_Snapshot::make( $this->snapshot() ), $provider );

		$this->assertTrue( $result->is_failure() );
		$this->assertFalse( WC_ESM_Shipment::is_registered( $order ) );
		$this->assertSame( 'Omniva was down.', WC_ESM_Shipment::error( $order ) );
	}

	/**
	 * Success and failure both leave a note on the order: the shop's record
	 * of what happened is the order timeline, not a log file.
	 *
	 * @return void
	 */
	public function test_both_outcomes_leave_a_note() {
		$order = $this->order();
		WC_ESM_Shipment_Registration::send( $order, WC_ESM_Order_Snapshot::make( $this->snapshot() ), new WC_ESM_Answering_Provider() );

		$this->assertCount( 1, $order->notes );

		$failing         = $this->order();
		$provider        = new WC_ESM_Answering_Provider();
		$provider->answer = WC_ESM_Shipment_Result::failure( 'Omniva was down.' );
		WC_ESM_Shipment_Registration::send( $failing, WC_ESM_Order_Snapshot::make( $this->snapshot() ), $provider );

		$this->assertCount( 1, $failing->notes );
		$this->assertStringContainsString( 'Omniva was down.', $failing->notes[0] );
	}

	/**
	 * A carrier that throws is a failed registration, not a white screen in
	 * the middle of somebody's order status change.
	 *
	 * @return void
	 */
	public function test_a_carrier_that_throws_is_caught() {
		$order    = $this->order();
		$provider = new class() extends WC_ESM_Answering_Provider {
			/**
			 * Throw rather than answer.
			 *
			 * @param array $snapshot Snapshot.
			 *
			 * @return WC_ESM_Shipment_Result
			 */
			public function register( $snapshot ) {
				throw new \RuntimeException( 'the library exploded' );
			}
		};

		$result = WC_ESM_Shipment_Registration::send( $order, WC_ESM_Order_Snapshot::make( $this->snapshot() ), $provider );

		$this->assertTrue( $result->is_failure() );
		$this->assertStringContainsString( 'the library exploded', WC_ESM_Shipment::error( $order ) );
	}

	/**
	 * A status the carrier was not configured for sends nothing.
	 *
	 * @return void
	 */
	public function test_a_status_the_carrier_did_not_ask_for_sends_nothing() {
		$this->assertFalse( WC_ESM_Shipment_Registration::status_matches( 'completed', 'processing' ) );
		$this->assertTrue( WC_ESM_Shipment_Registration::status_matches( 'completed', 'completed' ) );
	}

	/**
	 * A carrier with no chosen status never sends automatically, whatever
	 * the order does. That default is what keeps an unconfigured shop from
	 * sending real parcels.
	 *
	 * @return void
	 */
	public function test_no_chosen_status_never_sends() {
		$this->assertFalse( WC_ESM_Shipment_Registration::status_matches( '', 'completed' ) );
		$this->assertFalse( WC_ESM_Shipment_Registration::status_matches( '', '' ) );
	}

	/**
	 * The wc- prefix WooCommerce uses internally is not part of the setting,
	 * and a status arriving with one still matches.
	 *
	 * @return void
	 */
	public function test_the_wc_prefix_does_not_break_the_match() {
		$this->assertTrue( WC_ESM_Shipment_Registration::status_matches( 'completed', 'wc-completed' ) );
	}
}

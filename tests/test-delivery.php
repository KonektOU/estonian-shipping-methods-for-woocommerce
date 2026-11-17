<?php
/**
 * Where a parcel is, and how sparingly the carriers are asked.
 *
 * @package Estonian_Shipping_Methods_For_WooCommerce
 */

require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/class-wc-esm-delivery.php';
require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/class-wc-esm-delivery-poller.php';
require_once __DIR__ . '/test-shipment-admin.php';

/**
 * A carrier that reports what the test tells it to, and counts the asking.
 */
class WC_ESM_Reporting_Provider extends WC_ESM_Capable_Provider {

	/**
	 * Declared features.
	 *
	 * @var array
	 */
	protected $features = array( 'labels', 'tracking', 'delivery' );

	/**
	 * What to answer.
	 *
	 * @var WC_ESM_Shipment_Result|null
	 */
	public $answer;

	/**
	 * Barcodes asked about, per call.
	 *
	 * @var array
	 */
	public $asked = array();

	/**
	 * Where these parcels are.
	 *
	 * @param array $barcodes Barcodes.
	 *
	 * @return WC_ESM_Shipment_Result
	 */
	public function track_parcels( $barcodes ) {
		$this->asked[] = $barcodes;

		return $this->answer ? $this->answer : WC_ESM_Shipment_Result::success( array( 'states' => array() ) );
	}
}

/**
 * Tests for WC_ESM_Delivery and WC_ESM_Delivery_Poller.
 */
class Test_Delivery extends WC_ESM_Test_Case {

	const NOW = 1794834096;

	/**
	 * An order sent with these barcodes.
	 *
	 * @param array $barcodes Barcodes.
	 * @param array $meta     Further meta.
	 *
	 * @return WC_ESM_Shipped_Order
	 */
	protected function order( $barcodes = array( 'B1' ), $meta = array() ) {
		$order                                    = new WC_ESM_Shipped_Order( 'capable_method', array( 'ref-1' ) );
		$order->meta[ WC_ESM_Shipment::BARCODES ] = $barcodes;
		$order->meta                              = array_merge( $order->meta, $meta );

		return $order;
	}

	/**
	 * A registry with one reporting carrier.
	 *
	 * @param WC_ESM_Reporting_Provider $provider Provider.
	 *
	 * @return WC_ESM_Shipment_Registry
	 */
	protected function registry( $provider ) {
		$registry = new WC_ESM_Shipment_Registry();
		$registry->register( $provider );

		return $registry;
	}

	/**
	 * An order is as far along as its slowest parcel.
	 *
	 * @return void
	 */
	public function test_an_order_is_as_far_along_as_its_slowest_parcel() {
		$this->assertSame( WC_ESM_Delivery::AWAITING_COLLECTION, WC_ESM_Delivery::combine( array( 'delivered', 'awaiting_collection', 'delivered' ) ) );
		$this->assertSame( WC_ESM_Delivery::IN_TRANSIT, WC_ESM_Delivery::combine( array( 'delivered', 'in_transit' ) ) );
		$this->assertSame( '', WC_ESM_Delivery::combine( array( '', '' ) ) );
	}

	/**
	 * A parcel on its way back speaks for the order; only all of them back
	 * makes it returned.
	 *
	 * @return void
	 */
	public function test_a_return_speaks_for_the_order() {
		$this->assertSame( WC_ESM_Delivery::RETURNING, WC_ESM_Delivery::combine( array( 'delivered', 'returning' ) ) );
		$this->assertSame( WC_ESM_Delivery::RETURNING, WC_ESM_Delivery::combine( array( 'delivered', 'returned' ) ) );
		$this->assertSame( WC_ESM_Delivery::RETURNED, WC_ESM_Delivery::combine( array( 'returned', 'returned' ) ) );
	}

	/**
	 * A parcel waiting in a locker is asked about less often than one on
	 * the move, and an old one less often still.
	 *
	 * @return void
	 */
	public function test_waiting_and_old_parcels_are_asked_about_less() {
		$moving  = WC_ESM_Delivery::interval( WC_ESM_Delivery::IN_TRANSIT, 0 );
		$waiting = WC_ESM_Delivery::interval( WC_ESM_Delivery::AWAITING_COLLECTION, 0 );
		$old     = WC_ESM_Delivery::interval( WC_ESM_Delivery::IN_TRANSIT, 20 * WC_ESM_Delivery::DAY );

		$this->assertGreaterThan( $moving, $waiting );
		$this->assertGreaterThan( $moving, $old );
		$this->assertGreaterThanOrEqual( 12 * WC_ESM_Delivery::HOUR, $old );
	}

	/**
	 * A state is written with when it began, and the next check is put in
	 * the diary.
	 *
	 * @return void
	 */
	public function test_a_state_is_written_with_the_next_check() {
		$order = $this->order();

		WC_ESM_Delivery::apply( $order, WC_ESM_Delivery::IN_TRANSIT, false, self::NOW, 0 );

		$this->assertSame( WC_ESM_Delivery::IN_TRANSIT, WC_ESM_Delivery::state( $order ) );
		$this->assertSame( self::NOW, $order->meta[ WC_ESM_Delivery::SINCE ] );
		$this->assertSame( self::NOW + WC_ESM_Delivery::interval( WC_ESM_Delivery::IN_TRANSIT, 0 ), $order->meta[ WC_ESM_Delivery::NEXT_CHECK ] );
		$this->assertFalse( WC_ESM_Delivery::is_finished( $order ) );
	}

	/**
	 * Nothing new keeps the state and its start, and still moves the next
	 * check on.
	 *
	 * @return void
	 */
	public function test_nothing_new_keeps_the_state_and_moves_the_check() {
		$order = $this->order(
			array( 'B1' ),
			array(
				WC_ESM_Delivery::STATE => WC_ESM_Delivery::IN_TRANSIT,
				WC_ESM_Delivery::SINCE => self::NOW - 100,
			)
		);

		WC_ESM_Delivery::apply( $order, '', false, self::NOW, 0 );

		$this->assertSame( WC_ESM_Delivery::IN_TRANSIT, WC_ESM_Delivery::state( $order ) );
		$this->assertSame( self::NOW - 100, $order->meta[ WC_ESM_Delivery::SINCE ] );
		$this->assertGreaterThan( self::NOW, $order->meta[ WC_ESM_Delivery::NEXT_CHECK ] );
	}

	/**
	 * Delivered ends the journey: no next check, ever.
	 *
	 * @return void
	 */
	public function test_delivered_ends_the_journey() {
		$order = $this->order();

		WC_ESM_Delivery::apply( $order, WC_ESM_Delivery::DELIVERED, false, self::NOW, 0 );

		$this->assertTrue( WC_ESM_Delivery::is_finished( $order ) );
		$this->assertArrayNotHasKey( WC_ESM_Delivery::NEXT_CHECK, $order->meta );
	}

	/**
	 * Returned ends it too.
	 *
	 * @return void
	 */
	public function test_returned_ends_the_journey() {
		$order = $this->order();

		WC_ESM_Delivery::apply( $order, WC_ESM_Delivery::RETURNED, false, self::NOW, 0 );

		$this->assertTrue( WC_ESM_Delivery::is_finished( $order ) );
	}

	/**
	 * A delivery the carrier cannot vouch for is checked once more a day
	 * later, and believed when it has held.
	 *
	 * @return void
	 */
	public function test_a_tentative_delivery_is_believed_after_a_day() {
		$order = $this->order();

		WC_ESM_Delivery::apply( $order, WC_ESM_Delivery::DELIVERED, true, self::NOW, 0 );

		$this->assertFalse( WC_ESM_Delivery::is_finished( $order ) );
		$this->assertSame( self::NOW + WC_ESM_Delivery::DAY, $order->meta[ WC_ESM_Delivery::NEXT_CHECK ] );

		WC_ESM_Delivery::apply( $order, WC_ESM_Delivery::DELIVERED, true, self::NOW + WC_ESM_Delivery::DAY, 0 );

		$this->assertTrue( WC_ESM_Delivery::is_finished( $order ) );
	}

	/**
	 * A tentative delivery that turns into a return was not a delivery.
	 *
	 * @return void
	 */
	public function test_a_tentative_delivery_can_turn_into_a_return() {
		$order = $this->order();

		WC_ESM_Delivery::apply( $order, WC_ESM_Delivery::DELIVERED, true, self::NOW, 0 );
		WC_ESM_Delivery::apply( $order, WC_ESM_Delivery::RETURNING, false, self::NOW + WC_ESM_Delivery::DAY, 0 );

		$this->assertSame( WC_ESM_Delivery::RETURNING, WC_ESM_Delivery::state( $order ) );
		$this->assertFalse( WC_ESM_Delivery::is_finished( $order ) );
	}

	/**
	 * Delivered after a return began means back with the sender.
	 *
	 * @return void
	 */
	public function test_delivered_after_returning_is_returned() {
		$order = $this->order( array( 'B1' ), array( WC_ESM_Delivery::STATE => WC_ESM_Delivery::RETURNING ) );

		WC_ESM_Delivery::apply( $order, WC_ESM_Delivery::DELIVERED, false, self::NOW, 0 );

		$this->assertSame( WC_ESM_Delivery::RETURNED, WC_ESM_Delivery::state( $order ) );
		$this->assertTrue( WC_ESM_Delivery::is_finished( $order ) );
	}

	/**
	 * An order seen for the first time is not asked about: it was just
	 * sent. Its first check is put in the diary instead.
	 *
	 * @return void
	 */
	public function test_a_just_sent_order_is_diaried_not_asked() {
		$provider = new WC_ESM_Reporting_Provider();
		$order    = $this->order();

		$asked = WC_ESM_Delivery_Poller::poll( array( $order ), $this->registry( $provider ), self::NOW );

		$this->assertSame( 0, $asked );
		$this->assertSame( array(), $provider->asked );
		$this->assertGreaterThan( self::NOW, $order->meta[ WC_ESM_Delivery::NEXT_CHECK ] );
	}

	/**
	 * Every due parcel of a carrier is asked about in one call, and what it
	 * says lands on the right order.
	 *
	 * @return void
	 */
	public function test_due_parcels_are_asked_about_together() {
		$provider         = new WC_ESM_Reporting_Provider();
		$provider->answer = WC_ESM_Shipment_Result::success(
			array(
				'states' => array(
					'B1' => WC_ESM_Delivery::AWAITING_COLLECTION,
					'B2' => WC_ESM_Delivery::DELIVERED,
				),
			)
		);

		$due   = array( WC_ESM_Delivery::NEXT_CHECK => self::NOW - 1 );
		$first = $this->order( array( 'B1' ), $due );
		$other = $this->order( array( 'B2' ), $due );

		WC_ESM_Delivery_Poller::poll( array( $first, $other ), $this->registry( $provider ), self::NOW );

		$this->assertSame( array( array( 'B1', 'B2' ) ), $provider->asked );
		$this->assertSame( WC_ESM_Delivery::AWAITING_COLLECTION, WC_ESM_Delivery::state( $first ) );
		$this->assertTrue( WC_ESM_Delivery::is_finished( $other ) );
	}

	/**
	 * A carrier that refused still has its due orders moved on, so it is not
	 * asked again every hour.
	 *
	 * @return void
	 */
	public function test_a_refusal_still_moves_the_check_on() {
		$provider         = new WC_ESM_Reporting_Provider();
		$provider->answer = WC_ESM_Shipment_Result::failure( 'Down.' );
		$order            = $this->order( array( 'B1' ), array( WC_ESM_Delivery::NEXT_CHECK => self::NOW - 1 ) );

		WC_ESM_Delivery_Poller::poll( array( $order ), $this->registry( $provider ), self::NOW );

		$this->assertGreaterThan( self::NOW, $order->meta[ WC_ESM_Delivery::NEXT_CHECK ] );
		$this->assertSame( '', WC_ESM_Delivery::state( $order ) );
	}

	/**
	 * A finished order, an order with no barcode and a carrier that reports
	 * nothing are all left alone.
	 *
	 * @return void
	 */
	public function test_nothing_is_asked_that_cannot_be_answered() {
		$silent = new WC_ESM_Capable_Provider();
		$groups = WC_ESM_Delivery_Poller::plan(
			array(
				$this->order( array( 'B1' ), array( WC_ESM_Delivery::FINISHED => self::NOW ) ),
				$this->order( array() ),
			),
			$this->registry( new WC_ESM_Reporting_Provider() )
		);

		$this->assertSame( array(), $groups );
		$this->assertSame( array(), WC_ESM_Delivery_Poller::plan( array( $this->order() ), $this->registry( $silent ) ) );
	}
}

<?php
/**
 * What the orders list says about each order's shipment.
 *
 * @package Estonian_Shipping_Methods_For_WooCommerce
 */

require_once __DIR__ . '/test-shipment-admin.php';
require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/class-wc-esm-order-list.php';

/**
 * Tests for WC_ESM_Order_List.
 */
class Test_Order_List extends WC_ESM_Test_Case {

	/**
	 * A registry with one configurable carrier.
	 *
	 * @param WC_ESM_Capable_Provider|null $provider Provider.
	 *
	 * @return WC_ESM_Shipment_Registry
	 */
	protected function registry( $provider = null ) {
		$registry = new WC_ESM_Shipment_Registry();
		$registry->register( $provider ? $provider : new WC_ESM_Capable_Provider() );

		return $registry;
	}

	/**
	 * An order nobody here carries has no state: the column stays empty.
	 *
	 * @return void
	 */
	public function test_an_order_we_do_not_carry_has_no_state() {
		$this->assertSame( '', WC_ESM_Order_List::state( new WC_ESM_Shipped_Order( 'flat_rate' ), $this->registry(), false ) );
	}

	/**
	 * An order nobody has sent yet says so.
	 *
	 * @return void
	 */
	public function test_an_unsent_order_is_not_sent() {
		$this->assertSame( 'not_sent', WC_ESM_Order_List::state( new WC_ESM_Shipped_Order( 'capable_method' ), $this->registry(), false ) );
	}

	/**
	 * An order waiting in the queue is queued, not unsent: it will go on its
	 * own.
	 *
	 * @return void
	 */
	public function test_a_waiting_order_is_queued() {
		$this->assertSame( 'queued', WC_ESM_Order_List::state( new WC_ESM_Shipped_Order( 'capable_method' ), $this->registry(), true ) );
	}

	/**
	 * An order the carrier refused shows the failure.
	 *
	 * @return void
	 */
	public function test_a_refused_order_has_failed() {
		$order                                 = new WC_ESM_Shipped_Order( 'capable_method' );
		$order->meta[ WC_ESM_Shipment::ERROR ] = 'Omniva was down.';

		$this->assertSame( 'failed', WC_ESM_Order_List::state( $order, $this->registry(), false ) );
	}

	/**
	 * A refused order that is queued again is waiting for the retry, which
	 * is the more current fact.
	 *
	 * @return void
	 */
	public function test_a_retry_in_the_queue_outranks_the_old_failure() {
		$order                                 = new WC_ESM_Shipped_Order( 'capable_method' );
		$order->meta[ WC_ESM_Shipment::ERROR ] = 'Omniva was down.';

		$this->assertSame( 'queued', WC_ESM_Order_List::state( $order, $this->registry(), true ) );
	}

	/**
	 * A registered order is sent, whatever else is lying around.
	 *
	 * @return void
	 */
	public function test_a_registered_order_is_sent() {
		$this->assertSame( 'sent', WC_ESM_Order_List::state( new WC_ESM_Shipped_Order( 'capable_method', array( 'ref-1' ) ), $this->registry(), true ) );
	}

	/**
	 * The queue is only asked about orders that could be in it, because it
	 * is a database query per row.
	 *
	 * @return void
	 */
	public function test_the_queue_is_not_asked_about_a_sent_order() {
		$asked = false;
		$queue = function () use ( &$asked ) {
			$asked = true;

			return false;
		};

		WC_ESM_Order_List::state( new WC_ESM_Shipped_Order( 'capable_method', array( 'ref-1' ) ), $this->registry(), $queue );
		WC_ESM_Order_List::state( new WC_ESM_Shipped_Order( 'flat_rate' ), $this->registry(), $queue );

		$this->assertFalse( $asked );
	}

	/**
	 * A sent order offers its label.
	 *
	 * @return void
	 */
	public function test_a_sent_order_offers_its_label() {
		$this->assertSame( array( 'label' ), WC_ESM_Order_List::actions( new WC_ESM_Shipped_Order( 'capable_method', array( 'ref-1' ) ), $this->registry(), false ) );
	}

	/**
	 * A carrier that prints nothing gets no print button in the list either.
	 *
	 * @return void
	 */
	public function test_a_carrier_that_prints_nothing_offers_no_label() {
		$provider = new WC_ESM_Capable_Provider();
		$provider->declare_features( array( 'tracking' ) );

		$this->assertSame( array(), WC_ESM_Order_List::actions( new WC_ESM_Shipped_Order( 'capable_method', array( 'ref-1' ) ), $this->registry( $provider ), false ) );
	}

	/**
	 * An unsent or refused order offers sending.
	 *
	 * @return void
	 */
	public function test_an_unsent_or_refused_order_offers_sending() {
		$refused                                 = new WC_ESM_Shipped_Order( 'capable_method' );
		$refused->meta[ WC_ESM_Shipment::ERROR ] = 'No.';

		$this->assertSame( array( 'register' ), WC_ESM_Order_List::actions( new WC_ESM_Shipped_Order( 'capable_method' ), $this->registry(), false ) );
		$this->assertSame( array( 'register' ), WC_ESM_Order_List::actions( $refused, $this->registry(), false ) );
	}

	/**
	 * A queued order offers nothing: sending it by hand as well would race
	 * the queue to the carrier.
	 *
	 * @return void
	 */
	public function test_a_queued_order_offers_nothing() {
		$this->assertSame( array(), WC_ESM_Order_List::actions( new WC_ESM_Shipped_Order( 'capable_method' ), $this->registry(), true ) );
	}

	/**
	 * Somebody else's order is left alone.
	 *
	 * @return void
	 */
	public function test_somebody_elses_order_offers_nothing() {
		$this->assertSame( array(), WC_ESM_Order_List::actions( new WC_ESM_Shipped_Order( 'flat_rate' ), $this->registry(), false ) );
	}

	/**
	 * The column goes after the order status, where a shopkeeper reads
	 * the state of an order.
	 *
	 * @return void
	 */
	public function test_the_column_follows_the_order_status() {
		$columns = WC_ESM_Order_List::add_column(
			array(
				'cb'           => '',
				'order_number' => 'Order',
				'order_status' => 'Status',
				'order_total'  => 'Total',
			)
		);

		$this->assertSame( array( 'cb', 'order_number', 'order_status', WC_ESM_Order_List::COLUMN, 'order_total' ), array_keys( $columns ) );
	}

	/**
	 * A list with no status column still gets ours, at the end.
	 *
	 * @return void
	 */
	public function test_the_column_is_appended_when_there_is_no_status() {
		$columns = WC_ESM_Order_List::add_column( array( 'order_number' => 'Order' ) );

		$this->assertSame( array( 'order_number', WC_ESM_Order_List::COLUMN ), array_keys( $columns ) );
	}

	/**
	 * A sent order whose label has been printed says so.
	 *
	 * @return void
	 */
	public function test_a_printed_order_says_so() {
		$order                                         = new WC_ESM_Shipped_Order( 'capable_method', array( 'ref-1' ) );
		$order->meta[ WC_ESM_Shipment::LABEL_PRINTED ] = 1794834096;

		$this->assertSame( 'printed', WC_ESM_Order_List::state( $order, $this->registry(), false ) );
	}

	/**
	 * A printed order can be printed again.
	 *
	 * @return void
	 */
	public function test_a_printed_order_can_be_printed_again() {
		$order                                         = new WC_ESM_Shipped_Order( 'capable_method', array( 'ref-1' ) );
		$order->meta[ WC_ESM_Shipment::LABEL_PRINTED ] = 1794834096;

		$this->assertSame( array( 'label' ), WC_ESM_Order_List::actions( $order, $this->registry(), false ) );
	}

	/**
	 * The filter puts each order in exactly the state its column shows, by
	 * the same precedence: sent beats everything, printed is a kind of sent,
	 * a queued retry beats its failure.
	 *
	 * @return void
	 */
	public function test_the_filter_follows_the_columns_precedence() {
		$sets = array(
			'ours'       => array( 1, 2, 3, 4, 5, 6 ),
			'registered' => array( 1, 2, 9 ),
			'printed'    => array( 2 ),
			'failed'     => array( 1, 3, 4 ),
			'queued'     => array( 1, 4, 5 ),
		);

		$this->assertSame( array( 1 ), WC_ESM_Order_List::ids_in_state( 'sent', $sets ) );
		$this->assertSame( array( 2 ), WC_ESM_Order_List::ids_in_state( 'printed', $sets ) );
		$this->assertSame( array( 4, 5 ), WC_ESM_Order_List::ids_in_state( 'queued', $sets ) );
		$this->assertSame( array( 3 ), WC_ESM_Order_List::ids_in_state( 'failed', $sets ) );
		$this->assertSame( array( 6 ), WC_ESM_Order_List::ids_in_state( 'not_sent', $sets ) );
	}

	/**
	 * Somebody else's order is in no state, whatever meta it carries.
	 *
	 * @return void
	 */
	public function test_the_filter_leaves_other_orders_out() {
		$sets = array(
			'ours'       => array(),
			'registered' => array( 9 ),
			'failed'     => array( 9 ),
		);

		foreach ( array_keys( WC_ESM_Order_List::states() ) as $state ) {
			$this->assertSame( array(), WC_ESM_Order_List::ids_in_state( $state, $sets ), $state );
		}
	}

	/**
	 * A filter that matched nothing must not read as no filter at all.
	 *
	 * @return void
	 */
	public function test_an_empty_match_still_restricts_the_list() {
		$this->assertSame( array( 0 ), WC_ESM_Order_List::ids_or_none( array() ) );
		$this->assertSame( array( 3, 7 ), WC_ESM_Order_List::ids_or_none( array( 3, 7 ) ) );
	}
}

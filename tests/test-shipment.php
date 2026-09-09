<?php
/**
 * One set of meta keys for every carrier.
 *
 * @package Estonian_Shipping_Methods_For_WooCommerce
 */

require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/class-wc-esm-shipment-result.php';
require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/class-wc-esm-shipment.php';

/**
 * The parts of WC_Order this code touches, and nothing more.
 */
class WC_ESM_Fake_Order {

	/**
	 * Stored meta.
	 *
	 * @var array
	 */
	public $meta = array();

	/**
	 * How many times the order was saved.
	 *
	 * @var int
	 */
	public $saves = 0;

	/**
	 * Read one meta value.
	 *
	 * @param string $key    Meta key.
	 * @param bool   $single Single value.
	 *
	 * @return mixed
	 */
	public function get_meta( $key, $single = true ) {
		return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : '';
	}

	/**
	 * Write one meta value.
	 *
	 * @param string $key   Meta key.
	 * @param mixed  $value Value.
	 *
	 * @return void
	 */
	public function update_meta_data( $key, $value ) {
		$this->meta[ $key ] = $value;
	}

	/**
	 * Remove one meta value.
	 *
	 * @param string $key Meta key.
	 *
	 * @return void
	 */
	public function delete_meta_data( $key ) {
		unset( $this->meta[ $key ] );
	}

	/**
	 * Persist.
	 *
	 * @return void
	 */
	public function save() {
		$this->saves++;
	}
}

/**
 * Tests for WC_ESM_Shipment.
 */
class Test_Shipment extends WC_ESM_Test_Case {

	/**
	 * An order nobody has sent yet is not registered and carries nothing.
	 *
	 * @return void
	 */
	public function test_an_unsent_order_is_not_registered() {
		$order = new WC_ESM_Fake_Order();

		$this->assertFalse( WC_ESM_Shipment::is_registered( $order ) );
		$this->assertSame( array(), WC_ESM_Shipment::barcodes( $order ) );
		$this->assertSame( array(), WC_ESM_Shipment::label_refs( $order ) );
	}

	/**
	 * What the carrier answered is written onto the order.
	 *
	 * @return void
	 */
	public function test_a_successful_call_is_written_onto_the_order() {
		$order = new WC_ESM_Fake_Order();

		WC_ESM_Shipment::record(
			$order,
			WC_ESM_Shipment_Result::success(
				array(
					'barcodes'   => array( '00364300487158212149' ),
					'label_refs' => array( 'ref-1' ),
				)
			)
		);

		$this->assertSame( array( '00364300487158212149' ), WC_ESM_Shipment::barcodes( $order ) );
		$this->assertSame( array( 'ref-1' ), WC_ESM_Shipment::label_refs( $order ) );
		$this->assertTrue( WC_ESM_Shipment::is_registered( $order ) );
		$this->assertSame( 1, $order->saves );
	}

	/**
	 * A carrier that gives a barcode but no separate label reference still
	 * leaves a registered order.
	 *
	 * @return void
	 */
	public function test_a_barcode_alone_registers_the_order() {
		$order = new WC_ESM_Fake_Order();

		WC_ESM_Shipment::record( $order, WC_ESM_Shipment_Result::success( array( 'barcodes' => array( 'B1' ) ) ) );

		$this->assertTrue( WC_ESM_Shipment::is_registered( $order ) );
	}

	/**
	 * A carrier that gives only a label reference does too: Cleveron returns
	 * no barcode at all.
	 *
	 * @return void
	 */
	public function test_a_label_reference_alone_registers_the_order() {
		$order = new WC_ESM_Fake_Order();

		WC_ESM_Shipment::record( $order, WC_ESM_Shipment_Result::success( array( 'label_refs' => array( 'ref-1' ) ) ) );

		$this->assertTrue( WC_ESM_Shipment::is_registered( $order ) );
	}

	/**
	 * A single value from a carrier is stored as a list, so every reader
	 * gets an array whatever the carrier sent.
	 *
	 * @return void
	 */
	public function test_a_single_value_is_stored_as_a_list() {
		$order = new WC_ESM_Fake_Order();

		WC_ESM_Shipment::record( $order, WC_ESM_Shipment_Result::success( array( 'barcodes' => 'B1' ) ) );

		$this->assertSame( array( 'B1' ), WC_ESM_Shipment::barcodes( $order ) );
	}

	/**
	 * Recording a success clears a failure left by an earlier attempt: the
	 * order is no longer in error, and the "send again" button reads this.
	 *
	 * @return void
	 */
	public function test_a_success_clears_an_earlier_error() {
		$order = new WC_ESM_Fake_Order();
		WC_ESM_Shipment::record_error( $order, 'Omniva was down.' );

		WC_ESM_Shipment::record( $order, WC_ESM_Shipment_Result::success( array( 'barcodes' => array( 'B1' ) ) ) );

		$this->assertSame( '', WC_ESM_Shipment::error( $order ) );
	}

	/**
	 * A failure is written where the order screen can show it.
	 *
	 * @return void
	 */
	public function test_a_failure_is_written_where_the_screen_can_show_it() {
		$order = new WC_ESM_Fake_Order();

		WC_ESM_Shipment::record_error( $order, 'Omniva was down.' );

		$this->assertSame( 'Omniva was down.', WC_ESM_Shipment::error( $order ) );
		$this->assertFalse( WC_ESM_Shipment::is_registered( $order ) );
	}

	/**
	 * Recording a failed result records its message rather than pretending
	 * the order was sent.
	 *
	 * @return void
	 */
	public function test_recording_a_failure_records_the_message() {
		$order = new WC_ESM_Fake_Order();

		WC_ESM_Shipment::record( $order, WC_ESM_Shipment_Result::failure( 'Omniva refused the parcel.' ) );

		$this->assertSame( 'Omniva refused the parcel.', WC_ESM_Shipment::error( $order ) );
		$this->assertFalse( WC_ESM_Shipment::is_registered( $order ) );
	}

	/**
	 * Meta written by an older version, as a bare string rather than a list,
	 * still reads: shops carry orders sent before this code existed.
	 *
	 * @return void
	 */
	public function test_meta_stored_as_a_bare_string_still_reads() {
		$order = new WC_ESM_Fake_Order();
		$order->meta[ WC_ESM_Shipment::BARCODES ] = 'B1';

		$this->assertSame( array( 'B1' ), WC_ESM_Shipment::barcodes( $order ) );
		$this->assertTrue( WC_ESM_Shipment::is_registered( $order ) );
	}

	/**
	 * Empty entries are not barcodes; a carrier that answered with a blank
	 * must not leave an order looking registered.
	 *
	 * @return void
	 */
	public function test_blank_entries_are_not_barcodes() {
		$order = new WC_ESM_Fake_Order();
		$order->meta[ WC_ESM_Shipment::BARCODES ] = array( '', null );

		$this->assertSame( array(), WC_ESM_Shipment::barcodes( $order ) );
		$this->assertFalse( WC_ESM_Shipment::is_registered( $order ) );
	}
}

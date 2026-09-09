<?php
/**
 * One carrier-independent view of an order.
 *
 * @package Estonian_Shipping_Methods_For_WooCommerce
 */

require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/class-wc-esm-order-snapshot.php';

/**
 * Tests for WC_ESM_Order_Snapshot.
 */
class Test_Order_Snapshot extends WC_ESM_Test_Case {

	/**
	 * The empty shape carries every key a payload builder reads, so no
	 * builder has to guard each one.
	 *
	 * @return void
	 */
	public function test_the_empty_shape_carries_every_key() {
		$defaults = WC_ESM_Order_Snapshot::defaults();

		foreach ( array(
			'order_id',
			'order_number',
			'method_id',
			'terminal_id',
			'terminal',
			'recipient',
			'address',
			'weight',
			'cod_amount',
			'currency',
			'content',
			'timewindow',
			'base_country',
			'created_at',
			'shop_name',
		) as $key ) {
			$this->assertArrayHasKey( $key, $defaults, $key );
		}
	}

	/**
	 * What the caller gives replaces what the defaults hold.
	 *
	 * @return void
	 */
	public function test_given_facts_replace_the_defaults() {
		$snapshot = WC_ESM_Order_Snapshot::make(
			array(
				'order_id'  => 1234,
				'method_id' => 'omniva_parcel_machines',
			)
		);

		$this->assertSame( 1234, $snapshot['order_id'] );
		$this->assertSame( 'omniva_parcel_machines', $snapshot['method_id'] );
	}

	/**
	 * A nested group is merged key by key, so giving one part of an address
	 * does not blank the rest of the shape.
	 *
	 * @return void
	 */
	public function test_a_nested_group_is_merged_key_by_key() {
		$snapshot = WC_ESM_Order_Snapshot::make( array( 'address' => array( 'city' => 'Tallinn' ) ) );

		$this->assertSame( 'Tallinn', $snapshot['address']['city'] );
		$this->assertArrayHasKey( 'postcode', $snapshot['address'] );
		$this->assertSame( '', $snapshot['address']['postcode'] );
	}

	/**
	 * A key no snapshot has is dropped rather than carried through: payload
	 * builders read this shape and nothing else.
	 *
	 * @return void
	 */
	public function test_an_unknown_key_is_dropped() {
		$this->assertArrayNotHasKey( 'sender_iban', WC_ESM_Order_Snapshot::make( array( 'sender_iban' => 'EE00' ) ) );
	}

	/**
	 * Weight and cash on delivery are numbers whatever the order stored
	 * them as: a carrier that is handed the string "1.25" rejects the parcel.
	 *
	 * @return void
	 */
	public function test_weight_and_cod_are_numbers() {
		$snapshot = WC_ESM_Order_Snapshot::make(
			array(
				'weight'     => '1.25',
				'cod_amount' => '19.90',
			)
		);

		$this->assertSame( 1.25, $snapshot['weight'] );
		$this->assertSame( 19.90, $snapshot['cod_amount'] );
	}

	/**
	 * An order id is an integer, so a carrier expecting a number is not
	 * handed the string WooCommerce sometimes hands us.
	 *
	 * @return void
	 */
	public function test_the_order_id_is_an_integer() {
		$this->assertSame( 1234, WC_ESM_Order_Snapshot::make( array( 'order_id' => '1234' ) )['order_id'] );
	}

	/**
	 * A shop with no order-number plugin has no separate number, so the id
	 * stands in for it rather than the carrier being sent an empty label.
	 *
	 * @return void
	 */
	public function test_a_missing_order_number_falls_back_to_the_id() {
		$this->assertSame( '1234', WC_ESM_Order_Snapshot::make( array( 'order_id' => 1234 ) )['order_number'] );
	}

	/**
	 * An order number the shop does keep is left alone.
	 *
	 * @return void
	 */
	public function test_a_real_order_number_is_kept() {
		$snapshot = WC_ESM_Order_Snapshot::make(
			array(
				'order_id'     => 1234,
				'order_number' => 'TP-2026-0001',
			)
		);

		$this->assertSame( 'TP-2026-0001', $snapshot['order_number'] );
	}

	/**
	 * Whether the parcel is going to a terminal or to a door is a question
	 * about the snapshot, not something each payload builder re-derives.
	 *
	 * @return void
	 */
	public function test_a_snapshot_knows_whether_it_goes_to_a_terminal() {
		$this->assertTrue( WC_ESM_Order_Snapshot::goes_to_terminal( $this->snapshot() ) );
		$this->assertFalse( WC_ESM_Order_Snapshot::goes_to_terminal( $this->snapshot( array( 'terminal_id' => '' ) ) ) );
	}

	/**
	 * Cash on delivery is on only when there is money to collect.
	 *
	 * @return void
	 */
	public function test_cash_on_delivery_is_on_only_when_there_is_money() {
		$this->assertFalse( WC_ESM_Order_Snapshot::has_cod( $this->snapshot() ) );
		$this->assertTrue( WC_ESM_Order_Snapshot::has_cod( $this->snapshot( array( 'cod_amount' => 19.90 ) ) ) );
		$this->assertFalse( WC_ESM_Order_Snapshot::has_cod( $this->snapshot( array( 'cod_amount' => 0.0 ) ) ) );
	}

	/**
	 * The test case's own fixture is a valid snapshot; if it drifts from the
	 * shape, every payload test built on it is testing a fiction.
	 *
	 * @return void
	 */
	public function test_the_fixture_matches_the_shape() {
		$this->assertSame(
			array_keys( WC_ESM_Order_Snapshot::defaults() ),
			array_keys( WC_ESM_Order_Snapshot::make( $this->snapshot() ) )
		);
	}
}

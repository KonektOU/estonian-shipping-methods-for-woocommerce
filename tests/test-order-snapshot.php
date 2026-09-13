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

	/**
	 * Omniva's and DPD's terminal lists carry no country. The terminal is in
	 * the country its shipping method serves, so that is what fills it in -
	 * without it DPD refuses the parcel and Omniva has no address to route.
	 *
	 * @return void
	 */
	public function test_a_terminal_without_a_country_takes_the_methods() {
		$terminal = WC_ESM_Order_Snapshot::normalise_terminal(
			array( 'place_id' => 'EE90100', 'zipcode' => '79805', 'name' => 'Automaat Kohila', 'address' => 'Viljandi mnt 3A', 'city' => 'Kohila' ),
			'EE'
		);

		$this->assertSame( 'EE', $terminal['country'] );
	}

	/**
	 * A terminal list that does carry its country keeps it: Smartposti's
	 * Finnish machines say FI themselves.
	 *
	 * @return void
	 */
	public function test_a_terminal_that_names_its_country_keeps_it() {
		$terminal = WC_ESM_Order_Snapshot::normalise_terminal( array( 'place_id' => '1', 'country' => 'FI' ), 'EE' );

		$this->assertSame( 'FI', $terminal['country'] );
	}

	/**
	 * Omniva and DPD call the postcode zipcode. The snapshot calls it
	 * postalcode everywhere, so one name is read by every payload builder.
	 *
	 * @return void
	 */
	public function test_a_zipcode_becomes_the_postalcode() {
		$terminal = WC_ESM_Order_Snapshot::normalise_terminal( array( 'place_id' => 'EE90100', 'zipcode' => '79805' ), 'EE' );

		$this->assertSame( '79805', $terminal['postalcode'] );
	}

	/**
	 * A key the shape does not have is dropped from a nested group too, not
	 * only at the top: a fixed shape that lets zipcode through is not fixed.
	 *
	 * @return void
	 */
	public function test_an_unknown_nested_key_is_dropped() {
		$snapshot = WC_ESM_Order_Snapshot::make( array( 'terminal' => array( 'place_id' => 'EE90100', 'zipcode' => '79805' ) ) );

		$this->assertArrayNotHasKey( 'zipcode', $snapshot['terminal'] );
	}

	/**
	 * A customer who gave a phone number only on the shipping address is
	 * still reachable.
	 *
	 * @return void
	 */
	public function test_the_shipping_phone_stands_in_for_a_missing_billing_phone() {
		$this->assertSame( '+37255512345', WC_ESM_Order_Snapshot::recipient_phone( '', '+37255512345' ) );
		$this->assertSame( '+3725001', WC_ESM_Order_Snapshot::recipient_phone( '+3725001', '+37255512345' ) );
		$this->assertSame( '', WC_ESM_Order_Snapshot::recipient_phone( '', '' ) );
	}
}

<?php
/**
 * Three destination shapes Smartposti accepts.
 *
 * @package Estonian_Shipping_Methods_For_WooCommerce
 */

require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/class-wc-esm-order-snapshot.php';
require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/abstracts/class-wc-esm-payload.php';
require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/payloads/class-wc-esm-payload-smartpost.php';

/**
 * Tests for WC_ESM_Payload_Smartpost.
 */
class Test_Payload_Smartpost extends WC_ESM_Test_Case {

	/**
	 * The one item a request carries.
	 *
	 * @param array $overrides Snapshot overrides.
	 * @param array $settings  Carrier settings.
	 *
	 * @return array
	 */
	protected function item( $overrides = array(), $settings = array() ) {
		$request = WC_ESM_Payload_Smartpost::build( WC_ESM_Order_Snapshot::make( $this->snapshot( $overrides ) ), $settings );

		return $request['orders']['item'][0];
	}

	/**
	 * Smartposti wants the parcels wrapped in orders.item, even for one.
	 *
	 * @return void
	 */
	public function test_a_parcel_is_wrapped_in_orders_item() {
		$request = WC_ESM_Payload_Smartpost::build( WC_ESM_Order_Snapshot::make( $this->snapshot() ), array() );

		$this->assertArrayHasKey( 'orders', $request );
		$this->assertCount( 1, $request['orders']['item'] );
	}

	/**
	 * An Estonian parcel machine is named by its id and country, and nothing
	 * about a street is sent.
	 *
	 * @return void
	 */
	public function test_an_estonian_parcel_machine_is_named_by_its_id() {
		$destination = $this->item()['destination'];

		$this->assertSame( '01007220', $destination['place_id'] );
		$this->assertSame( 'EE', $destination['country'] );
		$this->assertArrayNotHasKey( 'street', $destination );
		$this->assertArrayNotHasKey( 'routingcode', $destination );
	}

	/**
	 * A Finnish parcel machine is named by postcode and routing code
	 * instead: Posti routes on those, not on the place id.
	 *
	 * @return void
	 */
	public function test_a_finnish_parcel_machine_is_named_by_its_routing_code() {
		$destination = $this->item(
			array(
				'method_id' => 'smartpost_finland',
				'terminal'  => array(
					'country'     => 'FI',
					'postalcode'  => '00100',
					'routingcode' => '1234',
				),
			)
		)['destination'];

		$this->assertSame( '00100', $destination['postalcode'] );
		$this->assertSame( '1234', $destination['routingcode'] );
		$this->assertArrayNotHasKey( 'place_id', $destination );
	}

	/**
	 * A courier delivery carries the customer's own address and the time
	 * window they chose.
	 *
	 * @return void
	 */
	public function test_a_courier_delivery_carries_the_address() {
		$destination = $this->item(
			array(
				'method_id'   => 'smartpost_courier',
				'terminal_id' => '',
				'timewindow'  => '2',
			)
		)['destination'];

		$this->assertSame( '2', $destination['timewindow'] );
		$this->assertSame( 'Pikk', $destination['street'] );
		$this->assertSame( '12', $destination['house'] );
		$this->assertSame( 'Tallinn', $destination['city'] );
		$this->assertSame( '10123', $destination['postalcode'] );
	}

	/**
	 * The order number is the reference the shop and the carrier both see.
	 *
	 * @return void
	 */
	public function test_the_order_number_is_the_reference() {
		$this->assertSame( '1234', $this->item()['reference'] );
	}

	/**
	 * The recipient is who the parcel is for, not who the order was billed to.
	 *
	 * @return void
	 */
	public function test_the_recipient_comes_from_the_snapshot() {
		$recipient = $this->item()['recipient'];

		$this->assertSame( 'Mari Maasikas', $recipient['name'] );
		$this->assertSame( '+37255512345', $recipient['phone'] );
		$this->assertSame( 'mari@example.com', $recipient['email'] );
	}

	/**
	 * The parcel leaves the country the shop is based in.
	 *
	 * @return void
	 */
	public function test_the_parcel_leaves_the_shops_own_country() {
		$this->assertSame( 'EE', $this->item()['source']['country'] );
	}

	/**
	 * The weight travels as a number.
	 *
	 * @return void
	 */
	public function test_the_weight_travels_as_a_number() {
		$this->assertSame( 1.25, $this->item()['weight'] );
	}

	/**
	 * A shop that never set a package size sends none, and sends no sender
	 * block either: Smartposti only wants those together.
	 *
	 * @return void
	 */
	public function test_no_package_size_means_no_sender_block() {
		$item = $this->item();

		$this->assertArrayNotHasKey( 'size', $item );
		$this->assertArrayNotHasKey( 'sender', $item );
	}

	/**
	 * A shop that did set one sends both.
	 *
	 * @return void
	 */
	public function test_a_package_size_brings_the_sender_block_with_it() {
		$item = $this->item(
			array(),
			array(
				'package_size' => 'M',
				'sender_name'  => 'Testpood',
				'sender_phone' => '+3726001234',
				'sender_email' => 'pood@example.com',
			)
		);

		$this->assertSame( 'M', $item['size'] );
		$this->assertSame( 'Testpood', $item['sender']['name'] );
		$this->assertSame( '+3726001234', $item['sender']['phone'] );
		$this->assertSame( 'pood@example.com', $item['sender']['email'] );
	}

	/**
	 * What the parcel says it holds names the shop and the order, so a
	 * customs form or a courier's screen is not blank.
	 *
	 * @return void
	 */
	public function test_the_content_names_the_shop_and_the_order() {
		$content = $this->item()['content'];

		$this->assertStringContainsString( 'Testpood', $content );
		$this->assertStringContainsString( '1234', $content );
	}

	/**
	 * An order with money to collect carries the amount as goods: that is
	 * what Smartposti transfers back to the sender. Without it the parcel
	 * goes out and nobody collects anything.
	 *
	 * @return void
	 */
	public function test_cash_on_delivery_travels_as_goods() {
		$this->assertSame( 19.90, $this->item( array( 'cod_amount' => 19.90 ) )['recipient']['goods'] );
	}

	/**
	 * An order with nothing to collect says nothing about money.
	 *
	 * @return void
	 */
	public function test_an_order_with_nothing_to_collect_says_nothing_about_money() {
		$this->assertArrayNotHasKey( 'goods', $this->item()['recipient'] );
	}

	/**
	 * A courier delivery carries place_id 1, which is how Smartposti is told
	 * this one goes to an address rather than to a machine.
	 *
	 * @return void
	 */
	public function test_a_courier_delivery_carries_place_id_one() {
		$destination = $this->item(
			array(
				'method_id'   => 'smartpost_courier',
				'terminal_id' => '',
			)
		)['destination'];

		$this->assertSame( 1, $destination['place_id'] );
	}

	/**
	 * An apartment number reaches the courier. A block of flats without one
	 * is a parcel left downstairs.
	 *
	 * @return void
	 */
	public function test_the_apartment_number_reaches_the_courier() {
		$destination = $this->item(
			array(
				'method_id'   => 'smartpost_courier',
				'terminal_id' => '',
			)
		)['destination'];

		$this->assertSame( '3', $destination['apartment'] );
	}
}

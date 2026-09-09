<?php
/**
 * What Omniva's OMX wants for a business-to-client parcel.
 *
 * @package Estonian_Shipping_Methods_For_WooCommerce
 */

require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/class-wc-esm-order-snapshot.php';
require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/abstracts/class-wc-esm-payload.php';
require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/payloads/class-wc-esm-payload-omniva.php';

/**
 * Tests for WC_ESM_Payload_Omniva.
 */
class Test_Payload_Omniva extends WC_ESM_Test_Case {

	/**
	 * A shop with a sender address filled in.
	 *
	 * @param array $overrides Settings overrides.
	 *
	 * @return array
	 */
	protected function settings( $overrides = array() ) {
		return array_merge(
			array(
				'customer_code'   => 'AXA123',
				'sender_name'     => 'Testpood',
				'sender_phone'    => '+3726001234',
				'sender_email'    => 'pood@example.com',
				'sender_street'   => 'Pikk',
				'sender_house'    => '12',
				'sender_postcode' => '10123',
				'sender_city'     => 'Tallinn',
				'sender_country'  => 'EE',
			),
			$overrides
		);
	}

	/**
	 * The one shipment a request carries.
	 *
	 * @param array $overrides Snapshot overrides.
	 * @param array $settings  Settings overrides.
	 *
	 * @return array
	 */
	protected function shipment( $overrides = array(), $settings = array() ) {
		$request = WC_ESM_Payload_Omniva::build(
			WC_ESM_Order_Snapshot::make( $this->snapshot( $overrides ) ),
			$this->settings( $settings )
		);

		return $request['shipments'][0];
	}

	/**
	 * The request names the shop's contract and carries a batch id.
	 *
	 * @return void
	 */
	public function test_the_request_names_the_contract() {
		$request = WC_ESM_Payload_Omniva::build( WC_ESM_Order_Snapshot::make( $this->snapshot() ), $this->settings() );

		$this->assertSame( 'AXA123', $request['customerCode'] );
		$this->assertNotSame( '', $request['fileId'] );
		$this->assertCount( 1, $request['shipments'] );
	}

	/**
	 * A parcel machine is named by its offload postcode and nothing else:
	 * Omniva rejects an address that carries both a terminal and a street.
	 *
	 * @return void
	 */
	public function test_a_parcel_machine_is_named_by_its_offload_postcode() {
		$address = $this->shipment()['receiverAddressee']['address'];

		$this->assertSame( '01007220', $address['offloadPostcode'] );
		$this->assertSame( 'EE', $address['country'] );
		$this->assertArrayNotHasKey( 'street', $address );
		$this->assertArrayNotHasKey( 'postcode', $address );
	}

	/**
	 * A parcel machine delivery says so on the shipment.
	 *
	 * @return void
	 */
	public function test_a_parcel_machine_delivery_says_so() {
		$this->assertSame( 'PARCEL_MACHINE', $this->shipment()['deliveryChannel'] );
	}

	/**
	 * A courier delivery carries the customer's own address instead.
	 *
	 * @return void
	 */
	public function test_a_courier_delivery_carries_the_customers_address() {
		$shipment = $this->shipment( array( 'terminal_id' => '', 'method_id' => 'omniva_courier' ) );
		$address  = $shipment['receiverAddressee']['address'];

		$this->assertSame( 'COURIER', $shipment['deliveryChannel'] );
		$this->assertSame( 'Pikk', $address['street'] );
		$this->assertSame( '12', $address['houseNo'] );
		$this->assertSame( '10123', $address['postcode'] );
		$this->assertSame( 'Tallinn', $address['deliverypoint'] );
		$this->assertArrayNotHasKey( 'offloadPostcode', $address );
	}

	/**
	 * The customer is the receiver.
	 *
	 * @return void
	 */
	public function test_the_customer_is_the_receiver() {
		$receiver = $this->shipment()['receiverAddressee'];

		$this->assertSame( 'Mari Maasikas', $receiver['personName'] );
		$this->assertSame( '+37255512345', $receiver['contactMobile'] );
		$this->assertSame( 'mari@example.com', $receiver['contactEmail'] );
	}

	/**
	 * The shop is the sender, from its own settings.
	 *
	 * @return void
	 */
	public function test_the_shop_is_the_sender() {
		$sender = $this->shipment()['senderAddressee'];

		$this->assertSame( 'Testpood', $sender['personName'] );
		$this->assertSame( 'pood@example.com', $sender['contactEmail'] );
		$this->assertSame( 'Pikk', $sender['address']['street'] );
		$this->assertSame( '12', $sender['address']['houseNo'] );
		$this->assertSame( 'Tallinn', $sender['address']['deliverypoint'] );
		$this->assertSame( 'EE', $sender['address']['country'] );
	}

	/**
	 * The order number is what ties Omniva's shipment back to the shop's.
	 *
	 * @return void
	 */
	public function test_the_order_number_ties_the_two_systems_together() {
		$this->assertSame( '1234', $this->shipment()['partnerShipmentId'] );
	}

	/**
	 * Omniva wants the weight as a string, in kilograms.
	 *
	 * @return void
	 */
	public function test_the_weight_travels_as_a_string() {
		$this->assertSame( '1.25', $this->shipment()['measurement']['weight'] );
	}

	/**
	 * A parcel staying in the sender's own country needs no content
	 * description; one crossing a border does, and customs will hold a
	 * parcel that arrives without one.
	 *
	 * @return void
	 */
	public function test_only_a_crossing_parcel_describes_its_contents() {
		$this->assertArrayNotHasKey( 'contentDescription', $this->shipment() );

		$crossing = $this->shipment(
			array(
				'terminal' => array( 'country' => 'LT' ),
			)
		);

		$this->assertArrayHasKey( 'contentDescription', $crossing );
		$this->assertNotSame( '', $crossing['contentDescription'] );
	}

	/**
	 * An order with nothing to collect asks for no additional service.
	 *
	 * @return void
	 */
	public function test_an_order_with_nothing_to_collect_asks_for_nothing() {
		$this->assertArrayNotHasKey( 'addServices', $this->shipment() );
	}

	/**
	 * An order with money to collect carries the service code the shop's
	 * own contract names, because Omniva issues that list per customer.
	 *
	 * @return void
	 */
	public function test_cash_on_delivery_uses_the_shops_own_service_code() {
		$shipment = $this->shipment(
			array( 'cod_amount' => 19.90 ),
			array( 'cod_service_code' => 'BP' )
		);

		$this->assertSame( array( array( 'code' => 'BP' ) ), $shipment['addServices'] );
	}

	/**
	 * A shop that never returns a parcel does not offer the customer the
	 * option; the setting is Omniva's, not ours to assume.
	 *
	 * @return void
	 */
	public function test_returns_are_not_offered_by_default() {
		$this->assertFalse( $this->shipment()['returnAllowed'] );
	}
}

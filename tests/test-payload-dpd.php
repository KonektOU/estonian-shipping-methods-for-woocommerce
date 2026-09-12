<?php
/**
 * A DPD shipment is a pudo id and a weight.
 *
 * @package Estonian_Shipping_Methods_For_WooCommerce
 */

require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/class-wc-esm-order-snapshot.php';
require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/abstracts/class-wc-esm-payload.php';
require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/payloads/class-wc-esm-payload-dpd.php';

/**
 * Tests for WC_ESM_Payload_Dpd.
 */
class Test_Payload_Dpd extends WC_ESM_Test_Case {

	/**
	 * A shop with its contract filled in.
	 *
	 * @param array $overrides Settings overrides.
	 *
	 * @return array
	 */
	protected function settings( $overrides = array() ) {
		return array_merge(
			array(
				'payer_code'      => '123456',
				'service_alias'   => 'PS',
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
	 * The request for one order.
	 *
	 * @param array $overrides Snapshot overrides.
	 * @param array $settings  Settings overrides.
	 *
	 * @return array
	 */
	protected function body( $overrides = array(), $settings = array() ) {
		return WC_ESM_Payload_Dpd::build(
			WC_ESM_Order_Snapshot::make( $this->snapshot( $overrides ) ),
			$this->settings( $settings )
		);
	}

	/**
	 * A parcel shop delivery is a pudo id: DPD needs no street for one, and
	 * sending one alongside is how a parcel ends up at the wrong place.
	 *
	 * @return void
	 */
	public function test_a_parcel_shop_delivery_is_a_pudo_id() {
		$receiver = $this->body()['receiverAddress'];

		$this->assertSame( '01007220', $receiver['pudoId'] );
		$this->assertArrayNotHasKey( 'street', $receiver );
	}

	/**
	 * The customer is still named on a parcel shop delivery: somebody has to
	 * be asked for at the counter.
	 *
	 * @return void
	 */
	public function test_the_customer_is_named_even_at_a_parcel_shop() {
		$receiver = $this->body()['receiverAddress'];

		$this->assertSame( 'Mari Maasikas', $receiver['name'] );
		$this->assertSame( '+37255512345', $receiver['phone'] );
		$this->assertSame( 'mari@example.com', $receiver['email'] );
	}

	/**
	 * A courier delivery carries the address instead.
	 *
	 * @return void
	 */
	public function test_a_courier_delivery_carries_the_address() {
		$receiver = $this->body( array( 'terminal_id' => '' ) )['receiverAddress'];

		$this->assertSame( 'Pikk', $receiver['street'] );
		$this->assertSame( '12', $receiver['streetNo'] );
		$this->assertSame( 'Tallinn', $receiver['city'] );
		$this->assertSame( '10123', $receiver['postalCode'] );
		$this->assertSame( 'EE', $receiver['country'] );
		$this->assertArrayNotHasKey( 'pudoId', $receiver );
	}

	/**
	 * The shop is the sender, from its own settings.
	 *
	 * @return void
	 */
	public function test_the_shop_is_the_sender() {
		$sender = $this->body()['senderAddress'];

		$this->assertSame( 'Testpood', $sender['name'] );
		$this->assertSame( 'Pikk', $sender['street'] );
		$this->assertSame( '12', $sender['streetNo'] );
		$this->assertSame( '10123', $sender['postalCode'] );
		$this->assertSame( 'EE', $sender['country'] );
	}

	/**
	 * Somebody has to be billed, and DPD is told which contract.
	 *
	 * @return void
	 */
	public function test_the_contract_is_named() {
		$this->assertSame( '123456', $this->body()['payerCode'] );
	}

	/**
	 * The service travels as its alias - the short code DPD lists against
	 * the contract, like PS or CLASSIC - and under the field name DPD asks
	 * for. The human-readable name is refused.
	 *
	 * @return void
	 */
	public function test_the_service_travels_as_an_alias() {
		$service = $this->body()['service'];

		$this->assertSame( 'PS', $service['serviceAlias'] );
		$this->assertArrayNotHasKey( 'serviceName', $service );
	}

	/**
	 * A shipment is one parcel of a known weight.
	 *
	 * @return void
	 */
	public function test_a_shipment_is_one_parcel_of_a_known_weight() {
		$parcels = $this->body()['parcels'];

		$this->assertCount( 1, $parcels );
		$this->assertSame( 1.25, $parcels[0]['weight'] );
	}

	/**
	 * The order number goes on the shipment, so a parcel found later can be
	 * traced back to an order.
	 *
	 * @return void
	 */
	public function test_the_order_number_travels_with_the_parcel() {
		$this->assertSame( array( '1234' ), $this->body()['shipmentReferences'] );
	}

	/**
	 * An order with nothing to collect asks for no additional service.
	 *
	 * @return void
	 */
	public function test_an_order_with_nothing_to_collect_asks_for_nothing() {
		$this->assertArrayNotHasKey( 'additionalServices', $this->body() );
	}

	/**
	 * An order with money to collect names the service alias from the shop's
	 * own contract, and the amount and currency to collect.
	 *
	 * @return void
	 */
	public function test_cash_on_delivery_names_the_contracts_own_alias() {
		$body = $this->body(
			array( 'cod_amount' => 19.90 ),
			array( 'cod_service_alias' => 'COD' )
		);

		$this->assertSame( 'COD', $body['additionalServices'][0]['serviceAlias'] );
		$this->assertSame( 19.90, $body['additionalServices'][0]['fields']['amount'] );
		$this->assertSame( 'EUR', $body['additionalServices'][0]['fields']['currency'] );
	}

	/**
	 * A shop that never set a cash-on-delivery alias sends no such service
	 * even for an order that has money on it: guessing an alias its contract
	 * does not carry would have DPD refuse the whole shipment.
	 *
	 * @return void
	 */
	public function test_no_alias_means_no_cash_on_delivery_service() {
		$body = $this->body( array( 'cod_amount' => 19.90 ), array( 'cod_service_alias' => '' ) );

		$this->assertArrayNotHasKey( 'additionalServices', $body );
	}
}

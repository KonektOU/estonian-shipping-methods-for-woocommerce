<?php
/**
 * Omniva over OMX, without the SOAP.
 *
 * @package Estonian_Shipping_Methods_For_WooCommerce
 */

require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/class-wc-esm-shipment-result.php';
require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/class-wc-esm-order-snapshot.php';
require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/abstracts/class-wc-esm-shipment-provider.php';
require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/abstracts/class-wc-esm-payload.php';
require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/payloads/class-wc-esm-payload-omniva.php';
require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/providers/class-wc-esm-provider-omniva.php';

/**
 * The provider with the network taken out.
 */
class WC_ESM_Omniva_Test_Provider extends WC_ESM_Provider_Omniva {

	/**
	 * Requests made.
	 *
	 * @var array
	 */
	public $requests = array();

	/**
	 * Answers to give, in order.
	 *
	 * @var array
	 */
	public $answers = array();

	/**
	 * Record and answer.
	 *
	 * @param string $endpoint Endpoint.
	 * @param array  $body     Request body.
	 * @param string $method   HTTP method.
	 *
	 * @return array
	 */
	protected function request( $endpoint, $body = array(), $method = 'POST' ) {
		$this->requests[] = compact( 'endpoint', 'body', 'method' );

		return array_shift( $this->answers );
	}
}

/**
 * Tests for WC_ESM_Provider_Omniva.
 */
class Test_Provider_Omniva extends WC_ESM_Test_Case {

	/**
	 * A provider that will answer with what the test prepared.
	 *
	 * @param array $answers Answers.
	 *
	 * @return WC_ESM_Omniva_Test_Provider
	 */
	protected function provider( $answers = array() ) {
		$provider          = new WC_ESM_Omniva_Test_Provider();
		$provider->answers = $answers;
		$provider->set_settings(
			array(
				'username'        => 'user',
				'password'        => 'pass',
				'customer_code'   => 'AXA123',
				'agent_id'        => 'Developer_123456_654321',
				'sender_name'     => 'Testpood',
				'sender_phone'    => '+3726001234',
				'sender_email'    => 'pood@example.com',
				'sender_street'   => 'Pikk',
				'sender_house'    => '12',
				'sender_postcode' => '10123',
				'sender_city'     => 'Tallinn',
				'sender_country'  => 'EE',
			)
		);

		return $provider;
	}

	/**
	 * Identity, which is written into orders and does not change.
	 *
	 * @return void
	 */
	public function test_it_knows_what_it_is() {
		$this->assertSame( 'omniva', ( new WC_ESM_Provider_Omniva() )->get_id() );
	}

	/**
	 * Omniva carries the shop's Omniva methods, parcel machines and post
	 * offices alike, and nobody else's.
	 *
	 * @return void
	 */
	public function test_it_carries_its_own_methods() {
		$provider = new WC_ESM_Provider_Omniva();

		foreach ( array( 'omniva_parcel_machines_ee', 'omniva_parcel_machines_lv', 'omniva_parcel_machines_lt', 'omniva_post_offices_ee' ) as $method ) {
			$this->assertTrue( $provider->carries( $method ), $method );
		}

		$this->assertFalse( $provider->carries( 'smartpost_estonia' ) );
	}

	/**
	 * Omniva books couriers and takes returns; it closes no manifests.
	 *
	 * @return void
	 */
	public function test_it_declares_what_it_offers() {
		$provider = new WC_ESM_Provider_Omniva();

		$this->assertTrue( $provider->supports( 'pickup' ) );
		$this->assertTrue( $provider->supports( 'return' ) );
		$this->assertTrue( $provider->supports( 'labels' ) );
		$this->assertFalse( $provider->supports( 'manifest' ) );
	}

	/**
	 * An accepted parcel comes back with the barcode Omniva assigned.
	 *
	 * @return void
	 */
	public function test_an_accepted_parcel_comes_back_with_its_barcode() {
		$provider = $this->provider( array( array( 'code' => 200, 'body' => array( 'barcodes' => array( 'CE123456789EE' ) ) ) ) );

		$result = $provider->register( WC_ESM_Order_Snapshot::make( $this->snapshot() ) );

		$this->assertTrue( $result->is_success() );
		$this->assertSame( array( 'CE123456789EE' ), $result->get( 'barcodes' ) );
		$this->assertSame( 'shipments/business-to-client', $provider->requests[0]['endpoint'] );
	}

	/**
	 * A rejection is a failure, and Omniva's own words are what the shop is
	 * shown: "HTTP 400" alone tells a shopkeeper nothing.
	 *
	 * @return void
	 */
	public function test_a_rejection_carries_omnivas_own_words() {
		$provider = $this->provider(
			array(
				array(
					'code' => 400,
					'body' => array( 'errors' => array( array( 'msg' => 'offloadPostcode is invalid' ) ) ),
				),
			)
		);

		$result = $provider->register( WC_ESM_Order_Snapshot::make( $this->snapshot() ) );

		$this->assertTrue( $result->is_failure() );
		$this->assertStringContainsString( 'offloadPostcode is invalid', $result->get_message() );
	}

	/**
	 * A 200 with no barcode is a failure: nothing can be printed or tracked.
	 *
	 * @return void
	 */
	public function test_a_200_without_a_barcode_is_a_failure() {
		$provider = $this->provider( array( array( 'code' => 200, 'body' => array( 'barcodes' => array() ) ) ) );

		$this->assertTrue( $provider->register( WC_ESM_Order_Snapshot::make( $this->snapshot() ) )->is_failure() );
	}

	/**
	 * Labels come back base64-encoded, one per barcode, and are handed on
	 * decoded so the merger never has to know that.
	 *
	 * @return void
	 */
	public function test_labels_come_back_decoded() {
		$provider = $this->provider(
			array(
				array(
					'code' => 200,
					'body' => array( 'labels' => array( 'CE1' => base64_encode( '%PDF-one' ), 'CE2' => base64_encode( '%PDF-two' ) ) ),
				),
			)
		);

		$result = $provider->fetch_labels( array( 'CE1', 'CE2' ) );

		$this->assertTrue( $result->is_success() );
		$this->assertSame( array( '%PDF-one', '%PDF-two' ), $result->get( 'pdfs' ) );
		$this->assertSame( 'shipments/package-labels', $provider->requests[0]['endpoint'] );
		$this->assertSame( array( 'CE1', 'CE2' ), $provider->requests[0]['body']['barcodes'] );
	}

	/**
	 * A courier is booked for a window on a day, at the shop's own address.
	 *
	 * @return void
	 */
	public function test_a_courier_is_booked_for_a_window() {
		$provider = $this->provider( array( array( 'code' => 200, 'body' => array( 'orderNumber' => 'P-1' ) ) ) );

		$result = $provider->request_pickup(
			array(
				'date'      => '2026-09-10',
				'time_from' => '09:00',
				'time_to'   => '17:00',
				'comment'   => 'Ring the bell',
			)
		);

		$this->assertTrue( $result->is_success() );
		$this->assertSame( 'P-1', $result->get( 'reference' ) );
		$this->assertSame( 'courierorders/create-pickup-order', $provider->requests[0]['endpoint'] );

		$body = $provider->requests[0]['body'];
		$this->assertSame( '2026-09-10T09:00:00.000', $body['startTime'] );
		$this->assertSame( '2026-09-10T17:00:00.000', $body['endTime'] );
		$this->assertSame( 'Tallinn', $body['pickupAddress']['deliverypoint'] );
	}

	/**
	 * A booked courier can be called off by the reference the booking gave.
	 *
	 * @return void
	 */
	public function test_a_booked_courier_can_be_called_off() {
		$provider = $this->provider( array( array( 'code' => 200, 'body' => array() ) ) );

		$this->assertTrue( $provider->cancel_pickup( 'P-1' )->is_success() );
		$this->assertSame( 'courierorders/cancel-pickup-order', $provider->requests[0]['endpoint'] );
	}

	/**
	 * A customer can follow the parcel.
	 *
	 * @return void
	 */
	public function test_a_customer_can_follow_the_parcel() {
		$this->assertStringContainsString(
			'CE123456789EE',
			( new WC_ESM_Provider_Omniva() )->get_tracking_url( 'CE123456789EE' )
		);
		$this->assertSame( '', ( new WC_ESM_Provider_Omniva() )->get_tracking_url( '' ) );
	}
}

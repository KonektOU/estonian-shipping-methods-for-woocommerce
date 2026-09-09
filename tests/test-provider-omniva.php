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
require_once __DIR__ . '/class-wc-esm-fake-client.php';

/**
 * The provider with the network taken out.
 */
class WC_ESM_Omniva_Test_Provider extends WC_ESM_Provider_Omniva {

	/**
	 * The stand-in for the network.
	 *
	 * @var WC_ESM_Fake_Client
	 */
	public $fake;

	/**
	 * Constructor.
	 *
	 * @param array $answers Answers to give.
	 */
	public function __construct( $answers = array() ) {
		$this->fake = new WC_ESM_Fake_Client( $answers );
	}

	/**
	 * The stand-in.
	 *
	 * @return WC_ESM_Carrier_Client
	 */
	protected function client() {
		return $this->fake;
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
		$provider = new WC_ESM_Omniva_Test_Provider( $answers );
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
		$provider = $this->provider( array( array( 200, array( 'barcodes' => array( 'CE123456789EE' ) ) ) ) );

		$result = $provider->register( WC_ESM_Order_Snapshot::make( $this->snapshot() ) );

		$this->assertTrue( $result->is_success() );
		$this->assertSame( array( 'CE123456789EE' ), $result->get( 'barcodes' ) );
		$this->assertSame( 'shipments/business-to-client', $provider->fake->endpoint() );
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
					400,
					array( 'errors' => array( array( 'msg' => 'offloadPostcode is invalid' ) ) ),
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
		$provider = $this->provider( array( array( 200, array( 'barcodes' => array() ) ) ) );

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
					200,
					array( 'labels' => array( 'CE1' => base64_encode( '%PDF-one' ), 'CE2' => base64_encode( '%PDF-two' ) ) ),
				),
			)
		);

		$result = $provider->fetch_labels( array( 'CE1', 'CE2' ) );

		$this->assertTrue( $result->is_success() );
		$this->assertSame( array( '%PDF-one', '%PDF-two' ), $result->get( 'pdfs' ) );
		$this->assertSame( 'shipments/package-labels', $provider->fake->endpoint() );
		$this->assertSame( array( 'CE1', 'CE2' ), $provider->fake->body()['barcodes'] );
	}

	/**
	 * A courier is booked for a window on a day, at the shop's own address.
	 *
	 * @return void
	 */
	public function test_a_courier_is_booked_for_a_window() {
		$provider = $this->provider( array( array( 200, array( 'courierOrderNumber' => '4141146' ) ) ) );

		$result = $provider->request_pickup(
			array(
				'date'      => '2026-09-10',
				'time_from' => '09:00',
				'time_to'   => '17:00',
				'comment'   => 'Ring the bell',
			)
		);

		$this->assertTrue( $result->is_success() );
		$this->assertSame( '4141146', $result->get( 'reference' ) );
		$this->assertSame( 'courierorders/create-pickup-order', $provider->fake->endpoint() );

		$body = $provider->fake->body();
		$this->assertSame( 'Tallinn', $body['pickupAddress']['deliverypoint'] );
	}

	/**
	 * Omniva reads the window in UTC. A shop in Tallinn asking for nine in
	 * the morning means nine local, and sending the wall clock unconverted
	 * puts the courier there three hours late in summer.
	 *
	 * @return void
	 */
	public function test_the_window_is_sent_in_utc() {
		$this->assertSame(
			'2026-09-10T06:00:00.000',
			WC_ESM_Provider_Omniva::utc_moment( '2026-09-10', '09:00', 'Europe/Tallinn' )
		);
		$this->assertSame(
			'2026-01-10T07:00:00.000',
			WC_ESM_Provider_Omniva::utc_moment( '2026-01-10', '09:00', 'Europe/Tallinn' )
		);
	}

	/**
	 * A shop that runs on UTC already is not shifted.
	 *
	 * @return void
	 */
	public function test_a_shop_already_on_utc_is_not_shifted() {
		$this->assertSame( '2026-09-10T09:00:00.000', WC_ESM_Provider_Omniva::utc_moment( '2026-09-10', '09:00', 'UTC' ) );
	}

	/**
	 * A booked courier is called off by the number Omniva files it under,
	 * and Omniva asks for it by that name.
	 *
	 * @return void
	 */
	public function test_a_cancellation_names_the_courier_order() {
		$provider = $this->provider( array( array( 200, array() ) ) );
		$provider->cancel_pickup( '4141146' );

		$this->assertSame( '4141146', $provider->fake->body()['courierOrderNumber'] );
	}

	/**
	 * A booked courier can be called off by the reference the booking gave.
	 *
	 * @return void
	 */
	public function test_a_booked_courier_can_be_called_off() {
		$provider = $this->provider( array( array( 200, array() ) ) );

		$this->assertTrue( $provider->cancel_pickup( 'P-1' )->is_success() );
		$this->assertSame( 'courierorders/cancel-pickup-order', $provider->fake->endpoint() );
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

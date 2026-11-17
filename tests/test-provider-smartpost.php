<?php
/**
 * Smartposti takes an order and gives back a barcode.
 *
 * @package Estonian_Shipping_Methods_For_WooCommerce
 */

require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/class-wc-esm-delivery.php';
require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/class-wc-esm-shipment-result.php';
require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/class-wc-esm-order-snapshot.php';
require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/abstracts/class-wc-esm-shipment-provider.php';
require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/abstracts/class-wc-esm-payload.php';
require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/payloads/class-wc-esm-payload-smartpost.php';
require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/providers/class-wc-esm-provider-smartpost.php';
require_once __DIR__ . '/class-wc-esm-fake-client.php';

/**
 * The provider with the network taken out: every call is recorded and the
 * answer is whatever the test put there.
 */
class WC_ESM_Smartpost_Test_Provider extends WC_ESM_Provider_Smartpost {

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
 * Tests for WC_ESM_Provider_Smartpost.
 */
class Test_Provider_Smartpost extends WC_ESM_Test_Case {

	/**
	 * A provider that will answer with what the test prepared.
	 *
	 * @param array $answers Answers.
	 * @param array $settings Carrier settings.
	 *
	 * @return WC_ESM_Smartpost_Test_Provider
	 */
	protected function provider( $answers = array(), $settings = array() ) {
		$provider = new WC_ESM_Smartpost_Test_Provider( $answers );
		$provider->set_settings( array_merge( array( 'api_key' => 'test-key' ), $settings ) );

		return $provider;
	}

	/**
	 * A carrier is known by an id that is written into orders, so it does
	 * not change even when the name on the box does.
	 *
	 * @return void
	 */
	public function test_the_id_outlives_the_rename() {
		$this->assertSame( 'smartpost', ( new WC_ESM_Provider_Smartpost() )->get_id() );
		$this->assertSame( 'Smartposti', ( new WC_ESM_Provider_Smartpost() )->get_title() );
	}

	/**
	 * Smartposti carries the shop's Smartposti methods and nobody else's.
	 *
	 * @return void
	 */
	public function test_it_carries_its_own_methods() {
		$provider = new WC_ESM_Provider_Smartpost();

		foreach ( array( 'smartpost_estonia', 'smartpost_finland', 'smartpost_latvia', 'smartpost_lithuania', 'smartpost_courier' ) as $method ) {
			$this->assertTrue( $provider->carries( $method ), $method );
		}

		$this->assertFalse( $provider->carries( 'omniva_parcel_machines' ) );
		$this->assertFalse( $provider->carries( 'flat_rate' ) );
	}

	/**
	 * It prints labels, tracks and collects cash on delivery.
	 *
	 * @return void
	 */
	public function test_it_declares_what_it_offers() {
		$provider = new WC_ESM_Provider_Smartpost();

		$this->assertTrue( $provider->supports( 'labels' ) );
		$this->assertTrue( $provider->supports( 'tracking' ) );
		$this->assertTrue( $provider->supports( 'cod' ) );
		// No endpoint for reading back what was collected is documented, so
		// the capability is not claimed. See the provider's docblock.
		$this->assertTrue( $provider->supports( 'cod_report' ) );
	}

	/**
	 * A parcel Smartposti accepted comes back with its barcode.
	 *
	 * @return void
	 */
	public function test_an_accepted_parcel_comes_back_with_its_barcode() {
		$provider = $this->provider(
			array( array( 200, '{"orders":{"item":[{"barcode":"00364300487158212149"}]}}' ) )
		);

		$result = $provider->register( WC_ESM_Order_Snapshot::make( $this->snapshot() ) );

		$this->assertTrue( $result->is_success() );
		$this->assertSame( array( '00364300487158212149' ), $result->get( 'barcodes' ) );
	}

	/**
	 * The parcel is posted to the orders endpoint, as JSON.
	 *
	 * @return void
	 */
	public function test_the_parcel_is_posted_to_the_orders_endpoint() {
		$provider = $this->provider( array( array( 200, '{"orders":{"item":[{"barcode":"B1"}]}}' ) ) );
		$provider->register( WC_ESM_Order_Snapshot::make( $this->snapshot() ) );

		$this->assertSame( 'orders', $provider->fake->endpoint() );
		$this->assertSame( 'POST', $provider->fake->calls[0]['method'] );
		$this->assertSame( '01007220', $provider->fake->body()['orders']['item'][0]['destination']['place_id'] );
	}

	/**
	 * A carrier that answers with anything but 200 has refused the parcel,
	 * and the order must not end up looking sent.
	 *
	 * @return void
	 */
	public function test_a_refused_parcel_is_a_failure() {
		$provider = $this->provider( array( array( 400, 'Bad request' ) ) );

		$result = $provider->register( WC_ESM_Order_Snapshot::make( $this->snapshot() ) );

		$this->assertTrue( $result->is_failure() );
		$this->assertStringContainsString( '400', $result->get_message() );
	}

	/**
	 * Smartposti answering 200 with no barcode in it is still a failure: the
	 * parcel cannot be printed or tracked, so calling it sent would strand
	 * the order.
	 *
	 * @return void
	 */
	public function test_a_200_without_a_barcode_is_a_failure() {
		$provider = $this->provider( array( array( 200, '{"orders":{"item":[{}]}}' ) ) );

		$result = $provider->register( WC_ESM_Order_Snapshot::make( $this->snapshot() ) );

		$this->assertTrue( $result->is_failure() );
	}

	/**
	 * A body that is not JSON at all is a failure rather than a fatal.
	 *
	 * @return void
	 */
	public function test_a_body_that_is_not_json_is_a_failure() {
		$provider = $this->provider( array( array( 200, '<html>maintenance</html>' ) ) );

		$this->assertTrue( $provider->register( WC_ESM_Order_Snapshot::make( $this->snapshot() ) )->is_failure() );
	}

	/**
	 * Labels are fetched by barcode, in the format the shop chose, one
	 * barcode parameter each.
	 *
	 * @return void
	 */
	public function test_labels_are_fetched_by_barcode() {
		$provider = $this->provider(
			array( array( 200, '%PDF-1.4 label' ) ),
			array( 'label_format' => 'A6' )
		);

		$result = $provider->fetch_labels( array( 'B1', 'B2' ) );

		$this->assertTrue( $result->is_success() );
		$this->assertSame( array( '%PDF-1.4 label' ), $result->get( 'pdfs' ) );
		$this->assertSame( 'GET', $provider->fake->calls[0]['method'] );
		$this->assertStringContainsString( 'format=A6', $provider->fake->endpoint() );
		$this->assertStringContainsString( 'barcode=B1&barcode=B2', $provider->fake->endpoint() );
	}

	/**
	 * Asking for labels with no barcodes asks the carrier nothing.
	 *
	 * @return void
	 */
	public function test_no_barcodes_asks_the_carrier_nothing() {
		$provider = $this->provider();

		$this->assertTrue( $provider->fetch_labels( array() )->is_failure() );
		$this->assertSame( array(), $provider->fake->calls );
	}

	/**
	 * A customer can follow the parcel by its barcode.
	 *
	 * @return void
	 */
	public function test_a_customer_can_follow_the_parcel() {
		$url = ( new WC_ESM_Provider_Smartpost() )->get_tracking_url( '00364300487158212149' );

		$this->assertStringContainsString( '00364300487158212149', $url );
		$this->assertStringStartsWith( 'https://', $url );
	}

	/**
	 * A parcel with no barcode has nowhere to be followed, and the caller is
	 * told so with an empty string rather than a broken link.
	 *
	 * @return void
	 */
	public function test_no_barcode_means_no_tracking_link() {
		$this->assertSame( '', ( new WC_ESM_Provider_Smartpost() )->get_tracking_url( '' ) );
	}

	/**
	 * Smartposti caps a label request at a hundred barcodes, so a bulk print
	 * of more than that is asked for in batches rather than silently losing
	 * the rest.
	 *
	 * @return void
	 */
	public function test_a_large_print_is_asked_for_in_batches() {
		$barcodes = array();

		for ( $i = 0; $i < 150; $i++ ) {
			$barcodes[] = 'B' . $i;
		}

		$provider = $this->provider(
			array(
				array( 200, '%PDF-first' ),
				array( 200, '%PDF-second' ),
			)
		);

		$result = $provider->fetch_labels( $barcodes );

		$this->assertTrue( $result->is_success() );
		$this->assertCount( 2, $provider->fake->calls );
		$this->assertSame( array( '%PDF-first', '%PDF-second' ), $result->get( 'pdfs' ) );
	}

	/**
	 * A batch that fails takes the whole print with it rather than handing
	 * back half a job the shopkeeper would not notice was half.
	 *
	 * @return void
	 */
	public function test_a_failed_batch_fails_the_print() {
		$barcodes = array();

		for ( $i = 0; $i < 150; $i++ ) {
			$barcodes[] = 'B' . $i;
		}

		$provider = $this->provider(
			array(
				array( 200, '%PDF-first' ),
				array( 500, 'boom' ),
			)
		);

		$this->assertTrue( $provider->fetch_labels( $barcodes )->is_failure() );
	}

	/**
	 * What the carrier says it collected, for a single day.
	 *
	 * @return void
	 */
	public function test_the_cod_report_asks_for_a_day() {
		$provider = $this->provider( array( array( 200, '{"payments":[]}' ) ) );

		$result = $provider->fetch_cod_report( '2026-09-01', '2026-09-01' );

		$this->assertTrue( $result->is_success() );
		$this->assertStringContainsString( 'cod-payments', $provider->fake->endpoint() );
		$this->assertStringContainsString( 'bank_transaction_date=2026-09-01', $provider->fake->endpoint() );
		$this->assertStringContainsString( 'get_one_day=1', $provider->fake->endpoint() );
	}

	/**
	 * A range of days is not one day, and Smartposti is told so.
	 *
	 * @return void
	 */
	public function test_a_range_is_not_one_day() {
		$provider = $this->provider( array( array( 200, '{"payments":[]}' ) ) );
		$provider->fetch_cod_report( '2026-09-01', '2026-09-30' );

		$this->assertStringContainsString( 'get_one_day=0', $provider->fake->endpoint() );
	}

	/**
	 * Every due parcel goes in one request, barcode by barcode.
	 *
	 * @return void
	 */
	public function test_parcels_are_tracked_in_one_request() {
		$provider = $this->provider( array( array( 200, array() ) ) );
		$provider->track_parcels( array( 'A1', 'B2' ) );

		$this->assertCount( 1, $provider->fake->calls );
		$this->assertSame( 'tracking?barcode=A1&barcode=B2', $provider->fake->endpoint() );
	}

	/**
	 * The furthest stage reached is the state; a parcel out of the
	 * destination locker is delivered, but only tentatively.
	 *
	 * @return void
	 */
	public function test_the_furthest_stage_is_the_state() {
		$provider = $this->provider(
			array(
				array(
					200,
					array(
						array( 'barcode' => 'A1', 'sourcein' => '2026-11-10 10:00', 'termin' => '2026-11-10 18:00' ),
						array( 'barcode' => 'B2', 'termout' => '2026-11-10 20:00', 'destinationin' => '2026-11-11 08:00' ),
						array( 'barcode' => 'C3', 'destinationin' => '2026-11-11 08:00', 'destinationout' => '2026-11-11 17:00' ),
						array( 'barcode' => 'D4', 'destinationout' => '2026-11-11 17:00', 'returntermin' => '2026-11-12 09:00' ),
						array( 'barcode' => 'E5', 'generated' => '2026-11-10 09:00' ),
					),
				),
			)
		);

		$result = $provider->track_parcels( array( 'A1', 'B2', 'C3', 'D4', 'E5' ) );

		$this->assertSame(
			array(
				'A1' => WC_ESM_Delivery::IN_TRANSIT,
				'B2' => WC_ESM_Delivery::AWAITING_COLLECTION,
				'C3' => WC_ESM_Delivery::DELIVERED,
				'D4' => WC_ESM_Delivery::RETURNING,
			),
			$result->get( 'states' )
		);
		$this->assertSame( array( 'C3' ), $result->get( 'tentative' ) );
	}

	/**
	 * However the answer is wrapped, the items are found.
	 *
	 * @return void
	 */
	public function test_tracking_items_are_found_however_wrapped() {
		$one = array( 'barcode' => 'A1' );

		$this->assertSame( array( $one ), WC_ESM_Provider_Smartpost::tracking_items( array( $one ) ) );
		$this->assertSame( array( $one ), WC_ESM_Provider_Smartpost::tracking_items( array( 'item' => array( $one ) ) ) );
		$this->assertSame( array( $one ), WC_ESM_Provider_Smartpost::tracking_items( $one ) );
		$this->assertSame( array(), WC_ESM_Provider_Smartpost::tracking_items( null ) );
	}

	/**
	 * A refused request is a failure, not a batch of parcels with no news.
	 *
	 * @return void
	 */
	public function test_a_refused_tracking_request_fails() {
		$this->assertTrue( $this->provider( array( array( 401, array() ) ) )->track_parcels( array( 'A1' ) )->is_failure() );
	}
}

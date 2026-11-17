<?php
/**
 * Omniva over OMX, without the SOAP.
 *
 * @package Estonian_Shipping_Methods_For_WooCommerce
 */

require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/class-wc-esm-delivery.php';
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
	 * Omniva prints labels and takes returns.
	 *
	 * @return void
	 */
	public function test_it_declares_what_it_offers() {
		$provider = new WC_ESM_Provider_Omniva();

		$this->assertTrue( $provider->supports( 'return' ) );
		$this->assertTrue( $provider->supports( 'labels' ) );
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

	/**
	 * Options held in memory, for the feed's cursor and buffer.
	 *
	 * @return void
	 */
	protected function stub_options() {
		$GLOBALS['wc_esm_options'] = array();

		Brain\Monkey\Functions\when( 'get_option' )->alias(
			static function ( $key, $default = false ) {
				return array_key_exists( $key, $GLOBALS['wc_esm_options'] ) ? $GLOBALS['wc_esm_options'][ $key ] : $default;
			}
		);
		Brain\Monkey\Functions\when( 'update_option' )->alias(
			static function ( $key, $value ) {
				$GLOBALS['wc_esm_options'][ $key ] = $value;

				return true;
			}
		);
	}

	/**
	 * One event in the feed.
	 *
	 * @param int    $id      Event id.
	 * @param string $barcode Barcode.
	 * @param string $code    Event code.
	 *
	 * @return array
	 */
	protected function event( $id, $barcode, $code ) {
		return array(
			'eventId'         => $id,
			'shipmentBarcode' => $barcode,
			'eventCode'       => $code,
		);
	}

	/**
	 * The feed's latest meaningful event per parcel is its state; the cursor
	 * moves past everything read.
	 *
	 * @return void
	 */
	public function test_the_feed_gives_each_parcel_its_latest_state() {
		$this->stub_options();

		$provider = $this->provider(
			array(
				array(
					200,
					array(
						'content' => array(
							$this->event( 11, 'CE1', 'TRT_SHIPMENT_HANDOVER_TO_PM' ),
							$this->event( 12, 'CE1', 'TRT_SHIPMENT_STORED_IN_PM' ),
							$this->event( 13, 'CE1', 'TRT_SHIPMENT_ARRIVAL_PM_NOTIFICATION' ),
							$this->event( 14, 'CE2', 'TRT_SHIPMENT_DELIVERED' ),
						),
						'last'    => true,
					),
				),
			)
		);

		$result = $provider->track_parcels( array( 'CE1', 'CE2' ) );

		$this->assertSame(
			array(
				'CE1' => WC_ESM_Delivery::AWAITING_COLLECTION,
				'CE2' => WC_ESM_Delivery::DELIVERED,
			),
			$result->get( 'states' )
		);
		$this->assertSame( 14, $GLOBALS['wc_esm_options'][ WC_ESM_Provider_Omniva::FEED_CURSOR ] );
		$this->assertStringContainsString( 'fromTimestamp=', $provider->fake->endpoint() );
	}

	/**
	 * Reading on starts after the last event read.
	 *
	 * @return void
	 */
	public function test_reading_on_starts_after_the_last_event() {
		$this->stub_options();
		$GLOBALS['wc_esm_options'][ WC_ESM_Provider_Omniva::FEED_CURSOR ] = 99;

		$provider = $this->provider( array( array( 200, array( 'content' => array() ) ) ) );
		$provider->track_parcels( array( 'CE1' ) );

		$this->assertStringContainsString( 'fromTrackEventId=99', $provider->fake->endpoint() );
		$this->assertStringNotContainsString( 'fromTimestamp', $provider->fake->endpoint() );
	}

	/**
	 * News about a parcel that was not due is kept until it is asked about,
	 * because the feed will not repeat it.
	 *
	 * @return void
	 */
	public function test_news_about_a_parcel_not_due_is_kept() {
		$this->stub_options();

		$provider = $this->provider(
			array(
				array( 200, array( 'content' => array( $this->event( 5, 'CE9', 'TRT_SHIPMENT_DELIVERED' ) ) ) ),
				array( 200, array( 'content' => array() ) ),
			)
		);

		$this->assertSame( array(), $provider->track_parcels( array( 'CE1' ) )->get( 'states' ) );
		$this->assertSame( array( 'CE9' => WC_ESM_Delivery::DELIVERED ), $provider->track_parcels( array( 'CE9' ) )->get( 'states' ) );
		$this->assertSame( array(), $GLOBALS['wc_esm_options'][ WC_ESM_Provider_Omniva::FEED_BUFFER ] );
	}

	/**
	 * A delivery after a return began is the parcel back with the sender.
	 *
	 * @return void
	 */
	public function test_a_delivery_after_a_return_is_returned() {
		$this->stub_options();

		$provider = $this->provider(
			array(
				array(
					200,
					array(
						'content' => array(
							$this->event( 1, 'CE1', 'TRT_SHIPMENT_RETURN' ),
							$this->event( 2, 'CE1', 'TRT_SHIPMENT_DELIVERED' ),
						),
					),
				),
			)
		);

		$this->assertSame( array( 'CE1' => WC_ESM_Delivery::RETURNED ), $provider->track_parcels( array( 'CE1' ) )->get( 'states' ) );
	}

	/**
	 * A full page is followed by the next, but never more pages than
	 * Omniva's rate limit leaves room for.
	 *
	 * @return void
	 */
	public function test_the_feed_is_read_within_the_rate_limit() {
		$this->stub_options();

		$full    = array();
		$answers = array();

		for ( $page = 0; $page < 6; $page++ ) {
			$full = array();

			for ( $i = 1; $i <= WC_ESM_Provider_Omniva::FEED_PAGE; $i++ ) {
				$full[] = $this->event( $page * WC_ESM_Provider_Omniva::FEED_PAGE + $i, 'X', 'TRT_SHIPMENT_REGISTERED' );
			}

			$answers[] = array( 200, array( 'content' => $full ) );
		}

		$provider = $this->provider( $answers );
		$provider->track_parcels( array( 'CE1' ) );

		$this->assertCount( WC_ESM_Provider_Omniva::FEED_PAGES_PER_RUN, $provider->fake->calls );
		$this->assertStringContainsString( 'fromTrackEventId=' . WC_ESM_Provider_Omniva::FEED_PAGE, $provider->fake->endpoint( 1 ) );
	}

	/**
	 * A refused first page is a failure.
	 *
	 * @return void
	 */
	public function test_a_refused_feed_fails() {
		$this->stub_options();

		$this->assertTrue( $this->provider( array( array( 429, array() ) ) )->track_parcels( array( 'CE1' ) )->is_failure() );
	}
}

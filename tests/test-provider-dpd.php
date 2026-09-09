<?php
/**
 * DPD takes an order and gives back a label.
 *
 * @package Estonian_Shipping_Methods_For_WooCommerce
 */

require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/class-wc-esm-shipment-result.php';
require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/class-wc-esm-order-snapshot.php';
require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/class-wc-esm-dpd-token.php';
require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/abstracts/class-wc-esm-shipment-provider.php';
require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/abstracts/class-wc-esm-payload.php';
require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/payloads/class-wc-esm-payload-dpd.php';
require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/providers/class-wc-esm-provider-dpd.php';

/**
 * The provider with the network taken out. Everything above request() -
 * the token, the retry, the parsing - runs for real.
 */
class WC_ESM_Dpd_Test_Provider extends WC_ESM_Provider_Dpd {

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
	 * @param string $method   HTTP method.
	 * @param array  $body     Request body.
	 * @param string $token    Bearer token, empty while logging in.
	 *
	 * @return array
	 */
	protected function request( $endpoint, $method = 'POST', $body = array(), $token = '' ) {
		$this->requests[] = compact( 'endpoint', 'method', 'body', 'token' );

		return array_shift( $this->answers );
	}
}

/**
 * Tests for WC_ESM_Provider_Dpd.
 */
class Test_Provider_Dpd extends WC_ESM_Test_Case {

	/**
	 * Stand a transient store up, since the token lives in one.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['wc_esm_transients'] = array();

		Brain\Monkey\Functions\when( 'get_transient' )->alias(
			static function ( $key ) {
				return isset( $GLOBALS['wc_esm_transients'][ $key ] ) ? $GLOBALS['wc_esm_transients'][ $key ] : false;
			}
		);
		Brain\Monkey\Functions\when( 'set_transient' )->alias(
			static function ( $key, $value ) {
				$GLOBALS['wc_esm_transients'][ $key ] = $value;
				return true;
			}
		);
		Brain\Monkey\Functions\when( 'delete_transient' )->alias(
			static function ( $key ) {
				unset( $GLOBALS['wc_esm_transients'][ $key ] );
				return true;
			}
		);
	}

	/**
	 * A provider that will answer with what the test prepared.
	 *
	 * @param array $answers Answers.
	 *
	 * @return WC_ESM_Dpd_Test_Provider
	 */
	protected function provider( $answers = array() ) {
		$provider          = new WC_ESM_Dpd_Test_Provider();
		$provider->answers = $answers;
		$provider->set_settings(
			array(
				'country'         => 'EE',
				'username'        => 'shop',
				'password'        => 'secret',
				'payer_code'      => '123456',
				'service_alias'   => 'DPD CLASSIC',
				'label_format'    => 'A6',
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
	 * A successful login answer.
	 *
	 * @param string $token Token.
	 *
	 * @return array
	 */
	protected function login( $token = 'tok-1' ) {
		return array( 'code' => 200, 'body' => array( 'token' => $token ) );
	}

	/**
	 * Identity and what it carries.
	 *
	 * @return void
	 */
	public function test_it_knows_what_it_is_and_what_it_carries() {
		$provider = new WC_ESM_Provider_Dpd();

		$this->assertSame( 'dpd', $provider->get_id() );
		$this->assertTrue( $provider->carries( 'dpd_shops_ee' ) );
		$this->assertTrue( $provider->carries( 'dpd_shops_lv' ) );
		$this->assertFalse( $provider->carries( 'omniva_post_offices_ee' ) );
	}

	/**
	 * DPD is the only carrier here that closes manifests.
	 *
	 * @return void
	 */
	public function test_it_is_the_one_that_closes_manifests() {
		$provider = new WC_ESM_Provider_Dpd();

		$this->assertTrue( $provider->supports( 'manifest' ) );
		$this->assertTrue( $provider->supports( 'pickup' ) );
		$this->assertTrue( $provider->supports( 'labels' ) );
		$this->assertFalse( $provider->supports( 'return' ) );
	}

	/**
	 * The first call logs in, and the token it got is used for the work.
	 *
	 * @return void
	 */
	public function test_the_first_call_logs_in_and_uses_the_token() {
		$provider = $this->provider(
			array(
				$this->login( 'tok-1' ),
				array( 'code' => 201, 'body' => array( 'id' => 'ship-1', 'parcels' => array( array( 'parcelNumber' => 'P1' ) ) ) ),
			)
		);

		$result = $provider->register( WC_ESM_Order_Snapshot::make( $this->snapshot() ) );

		$this->assertTrue( $result->is_success() );
		$this->assertSame( 'auth/tokens', $provider->requests[0]['endpoint'] );
		$this->assertSame( 'shipments', $provider->requests[1]['endpoint'] );
		$this->assertSame( 'tok-1', $provider->requests[1]['token'] );
	}

	/**
	 * A remembered token means the next call does not log in again: twenty
	 * labels are twenty requests, not forty.
	 *
	 * @return void
	 */
	public function test_a_remembered_token_is_reused() {
		WC_ESM_Dpd_Token::remember( WC_ESM_Dpd_Token::cache_key( 'telli.dpd.ee', 'shop' ), 'tok-cached' );
		$provider = $this->provider( array( array( 'code' => 201, 'body' => array( 'id' => 'ship-1' ) ) ) );

		$provider->register( WC_ESM_Order_Snapshot::make( $this->snapshot() ) );

		$this->assertCount( 1, $provider->requests );
		$this->assertSame( 'tok-cached', $provider->requests[0]['token'] );
	}

	/**
	 * A token DPD has stopped accepting is thrown away, a new one fetched,
	 * and the work retried once - the shop never sees the expiry.
	 *
	 * @return void
	 */
	public function test_an_expired_token_is_replaced_and_the_work_retried() {
		WC_ESM_Dpd_Token::remember( WC_ESM_Dpd_Token::cache_key( 'telli.dpd.ee', 'shop' ), 'tok-dead' );
		$provider = $this->provider(
			array(
				array( 'code' => 401, 'body' => array() ),
				$this->login( 'tok-fresh' ),
				array( 'code' => 201, 'body' => array( 'id' => 'ship-1' ) ),
			)
		);

		$result = $provider->register( WC_ESM_Order_Snapshot::make( $this->snapshot() ) );

		$this->assertTrue( $result->is_success() );
		$this->assertSame( 'tok-dead', $provider->requests[0]['token'] );
		$this->assertSame( 'auth/tokens', $provider->requests[1]['endpoint'] );
		$this->assertSame( 'tok-fresh', $provider->requests[2]['token'] );
	}

	/**
	 * The retry happens once. A second 401 is a credentials problem, not an
	 * expiry, and retrying forever would hammer DPD with a bad password.
	 *
	 * @return void
	 */
	public function test_the_retry_happens_only_once() {
		$provider = $this->provider(
			array(
				$this->login( 'tok-1' ),
				array( 'code' => 401, 'body' => array() ),
				$this->login( 'tok-2' ),
				array( 'code' => 401, 'body' => array() ),
			)
		);

		$result = $provider->register( WC_ESM_Order_Snapshot::make( $this->snapshot() ) );

		$this->assertTrue( $result->is_failure() );
		$this->assertCount( 4, $provider->requests );
	}

	/**
	 * A login DPD refuses is a failure the shop can act on, and no shipment
	 * request is made on top of it.
	 *
	 * @return void
	 */
	public function test_a_refused_login_stops_there() {
		$provider = $this->provider( array( array( 'code' => 401, 'body' => array() ) ) );

		$result = $provider->register( WC_ESM_Order_Snapshot::make( $this->snapshot() ) );

		$this->assertTrue( $result->is_failure() );
		$this->assertCount( 1, $provider->requests );
	}

	/**
	 * A registered shipment gives back the id DPD files it under and the
	 * parcel numbers the customer can follow.
	 *
	 * @return void
	 */
	public function test_a_registered_shipment_gives_back_its_numbers() {
		$provider = $this->provider(
			array(
				$this->login(),
				array(
					'code' => 201,
					'body' => array(
						'id'      => 'ship-1',
						'parcels' => array( array( 'parcelNumber' => 'P1' ), array( 'parcelNumber' => 'P2' ) ),
					),
				),
			)
		);

		$result = $provider->register( WC_ESM_Order_Snapshot::make( $this->snapshot() ) );

		$this->assertSame( array( 'P1', 'P2' ), $result->get( 'barcodes' ) );
		$this->assertSame( array( 'ship-1' ), $result->get( 'label_refs' ) );
	}

	/**
	 * Labels are asked for by shipment id, as a PDF, on the paper the shop
	 * chose.
	 *
	 * @return void
	 */
	public function test_labels_are_asked_for_as_a_pdf() {
		$provider = $this->provider(
			array(
				$this->login(),
				array( 'code' => 200, 'body' => '%PDF-label' ),
			)
		);

		$result = $provider->fetch_labels( array( 'ship-1' ) );

		$this->assertTrue( $result->is_success() );
		$this->assertSame( '%PDF-label', $result->get( 'pdf' ) );
		$this->assertSame( 'shipments/labels', $provider->requests[1]['endpoint'] );
		$this->assertSame( array( 'ship-1' ), $provider->requests[1]['body']['shipmentIds'] );
		$this->assertSame( 'A6', $provider->requests[1]['body']['paperSize'] );
	}

	/**
	 * Closing a manifest hands DPD the shipments it covers and gives back
	 * the reference the manifest is filed under.
	 *
	 * @return void
	 */
	public function test_closing_a_manifest_gives_back_its_reference() {
		$provider = $this->provider(
			array(
				$this->login(),
				array( 'code' => 201, 'body' => array( 'id' => 'man-1' ) ),
			)
		);

		$result = $provider->close_manifest( array( 'ship-1', 'ship-2' ) );

		$this->assertTrue( $result->is_success() );
		$this->assertSame( 'man-1', $result->get( 'reference' ) );
		$this->assertSame( 'shipments/manifests', $provider->requests[1]['endpoint'] );
	}

	/**
	 * Closing a manifest over nothing asks DPD nothing.
	 *
	 * @return void
	 */
	public function test_a_manifest_over_nothing_asks_nothing() {
		$provider = $this->provider();

		$this->assertTrue( $provider->close_manifest( array() )->is_failure() );
		$this->assertSame( array(), $provider->requests );
	}

	/**
	 * A closed manifest can be fetched again as a PDF.
	 *
	 * @return void
	 */
	public function test_a_closed_manifest_can_be_fetched_again() {
		$provider = $this->provider(
			array(
				$this->login(),
				array( 'code' => 200, 'body' => '%PDF-manifest' ),
			)
		);

		$result = $provider->fetch_manifest( 'man-1' );

		$this->assertTrue( $result->is_success() );
		$this->assertSame( '%PDF-manifest', $result->get( 'pdf' ) );
		$this->assertStringContainsString( 'man-1', $provider->requests[1]['endpoint'] );
	}

	/**
	 * A courier is booked for a window at the shop's own address.
	 *
	 * @return void
	 */
	public function test_a_courier_is_booked_for_a_window() {
		$provider = $this->provider(
			array(
				$this->login(),
				array( 'code' => 201, 'body' => array( 'id' => 'pick-1' ) ),
			)
		);

		$result = $provider->request_pickup(
			array(
				'date'      => '2026-09-10',
				'time_from' => '09:00',
				'time_to'   => '17:00',
				'comment'   => 'Back door',
			)
		);

		$this->assertTrue( $result->is_success() );
		$this->assertSame( 'pick-1', $result->get( 'reference' ) );

		$body = $provider->requests[1]['body'];
		$this->assertSame( 'pickups', $provider->requests[1]['endpoint'] );
		$this->assertSame( '2026-09-10', $body['pickupDate'] );
		$this->assertSame( '09:00', $body['pickupTimeFrom'] );
		$this->assertSame( '17:00', $body['pickupTimeTo'] );
		$this->assertSame( 'Back door', $body['messageToCourier'] );
		$this->assertSame( 'Tallinn', $body['address']['city'] );
	}

	/**
	 * A customer can follow the parcel by its number.
	 *
	 * @return void
	 */
	public function test_a_customer_can_follow_the_parcel() {
		$this->assertStringContainsString( 'P1', ( new WC_ESM_Provider_Dpd() )->get_tracking_url( 'P1' ) );
		$this->assertSame( '', ( new WC_ESM_Provider_Dpd() )->get_tracking_url( '' ) );
	}

	/**
	 * The shop's country decides which DPD it talks to: the Baltic
	 * countries are three separate portals, not one.
	 *
	 * @return void
	 */
	public function test_the_country_decides_which_dpd_answers() {
		$this->assertSame( 'telli.dpd.ee', WC_ESM_Provider_Dpd::host_for( 'EE' ) );
		$this->assertSame( 'eserviss.dpd.lv', WC_ESM_Provider_Dpd::host_for( 'LV' ) );
		$this->assertSame( 'esiunta.dpd.lt', WC_ESM_Provider_Dpd::host_for( 'LT' ) );
		$this->assertSame( 'telli.dpd.ee', WC_ESM_Provider_Dpd::host_for( 'XX' ) );
	}
}

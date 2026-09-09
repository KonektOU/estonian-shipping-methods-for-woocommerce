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
require_once __DIR__ . '/class-wc-esm-fake-client.php';

/**
 * The provider with the network taken out. Everything above the call - the
 * token, the retry, the parsing - runs for real.
 */
class WC_ESM_Dpd_Test_Provider extends WC_ESM_Provider_Dpd {

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
		$provider = new WC_ESM_Dpd_Test_Provider( $answers );
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
		return array( 200, array( 'token' => $token, 'secretId' => 's1', 'validUntil' => '2030-01-01T00:00:00' ) );
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
		// DPD's API offers no way to call a booked courier off.
		$this->assertFalse( $provider->supports( 'pickup_cancel' ) );
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
				array( 201, array( 'id' => 'ship-1', 'parcelNumbers' => array( 'P1' ) ) ),
			)
		);

		$result = $provider->register( WC_ESM_Order_Snapshot::make( $this->snapshot() ) );

		$this->assertTrue( $result->is_success() );
		$this->assertSame( 'auth/tokens', $provider->fake->endpoint( 0 ) );
		$this->assertSame( 'shipments', $provider->fake->endpoint( 1 ) );
		$this->assertSame( 'tok-1', $provider->fake->token( 1 ) );
	}

	/**
	 * A remembered token means the next call does not log in again: twenty
	 * labels are twenty requests, not forty.
	 *
	 * @return void
	 */
	public function test_a_remembered_token_is_reused() {
		WC_ESM_Dpd_Token::remember( WC_ESM_Dpd_Token::cache_key( 'telli.dpd.ee', 'shop' ), 'tok-cached' );
		$provider = $this->provider( array( array( 201, array( 'id' => 'ship-1' ) ) ) );

		$provider->register( WC_ESM_Order_Snapshot::make( $this->snapshot() ) );

		$this->assertCount( 1, $provider->fake->calls );
		$this->assertSame( 'tok-cached', $provider->fake->token( 0 ) );
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
				array( 401, array() ),
				$this->login( 'tok-fresh' ),
				array( 201, array( 'id' => 'ship-1' ) ),
			)
		);

		$result = $provider->register( WC_ESM_Order_Snapshot::make( $this->snapshot() ) );

		$this->assertTrue( $result->is_success() );
		$this->assertSame( 'tok-dead', $provider->fake->token( 0 ) );
		$this->assertSame( 'auth/tokens', $provider->fake->endpoint( 1 ) );
		$this->assertSame( 'tok-fresh', $provider->fake->token( 2 ) );
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
				array( 401, array() ),
				$this->login( 'tok-2' ),
				array( 401, array() ),
			)
		);

		$result = $provider->register( WC_ESM_Order_Snapshot::make( $this->snapshot() ) );

		$this->assertTrue( $result->is_failure() );
		$this->assertCount( 4, $provider->fake->calls );
	}

	/**
	 * A login DPD refuses is a failure the shop can act on, and no shipment
	 * request is made on top of it.
	 *
	 * @return void
	 */
	public function test_a_refused_login_stops_there() {
		$provider = $this->provider( array( array( 401, array() ) ) );

		$result = $provider->register( WC_ESM_Order_Snapshot::make( $this->snapshot() ) );

		$this->assertTrue( $result->is_failure() );
		$this->assertCount( 1, $provider->fake->calls );
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
					201,
					array(
						'id'            => 'ship-1',
						'parcelNumbers' => array( 'P1', 'P2' ),
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
				array( 200, array( 'labels' => array( array( 'binaryData' => base64_encode( '%PDF-label' ) ) ) ) ),
			)
		);

		$result = $provider->fetch_labels( array( 'ship-1' ) );

		$this->assertTrue( $result->is_success() );
		$this->assertSame( array( '%PDF-label' ), $result->get( 'pdfs' ) );
		$this->assertSame( 'shipments/labels', $provider->fake->endpoint( 1 ) );
		$this->assertSame( array( 'ship-1' ), $provider->fake->body( 1 )['shipmentIds'] );
		$this->assertSame( 'A6', $provider->fake->body( 1 )['paperSize'] );
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
				array( 200, array( 'shipmentIds' => array( 'ship-1', 'ship-2' ), 'binaryData' => base64_encode( '%PDF-manifest' ) ) ),
			)
		);

		$result = $provider->close_manifest( array( 'ship-1', 'ship-2' ) );

		$this->assertTrue( $result->is_success() );
		$this->assertSame( '%PDF-manifest', $result->get( 'pdf' ) );
		$this->assertSame( 'shipments/manifests', $provider->fake->endpoint( 1 ) );
	}

	/**
	 * Closing a manifest over nothing asks DPD nothing.
	 *
	 * @return void
	 */
	public function test_a_manifest_over_nothing_asks_nothing() {
		$provider = $this->provider();

		$this->assertTrue( $provider->close_manifest( array() )->is_failure() );
		$this->assertSame( array(), $provider->fake->calls );
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
				array( 200, array( 'binaryData' => base64_encode( '%PDF-manifest' ) ) ),
			)
		);

		$result = $provider->fetch_manifest( 'man-1' );

		$this->assertTrue( $result->is_success() );
		$this->assertSame( '%PDF-manifest', $result->get( 'pdf' ) );
		$this->assertStringContainsString( 'man-1', $provider->fake->endpoint( 1 ) );
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
				array( 201, array( 'id' => 'pick-1' ) ),
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

		$body = $provider->fake->body( 1 );
		$this->assertSame( 'pickups', $provider->fake->endpoint( 1 ) );
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

	/**
	 * A shipment is posted as a list. DPD's endpoint takes an array of them
	 * and refuses a bare object, however many there are.
	 *
	 * @return void
	 */
	public function test_a_shipment_is_posted_as_a_list() {
		$provider = $this->provider( array( $this->login(), array( 201, array( 'id' => 'ship-1' ) ) ) );
		$provider->register( WC_ESM_Order_Snapshot::make( $this->snapshot() ) );

		$body = $provider->fake->body( 1 );

		$this->assertArrayHasKey( 0, $body );
		$this->assertSame( '123456', $body[0]['payerCode'] );
	}

	/**
	 * The contract's credentials go as HTTP basic authentication, and the
	 * body names the token and how long it should live. Sending the username
	 * and password in the body is refused.
	 *
	 * @return void
	 */
	public function test_logging_in_uses_basic_authentication() {
		$provider = $this->provider( array( $this->login(), array( 201, array( 'id' => 'ship-1' ) ) ) );
		$provider->register( WC_ESM_Order_Snapshot::make( $this->snapshot() ) );

		$login = $provider->fake->calls[0];

		$this->assertSame( 'auth/tokens', $login['endpoint'] );
		$this->assertSame( 'Basic ' . base64_encode( 'shop:secret' ), $login['headers']['Authorization'] );
		$this->assertArrayHasKey( 'name', $login['body'] );
		$this->assertArrayHasKey( 'ttl', $login['body'] );
		$this->assertArrayNotHasKey( 'password', $login['body'] );
	}

	/**
	 * A manifest comes back as the PDF itself, because DPD returns no
	 * reference to fetch it by later.
	 *
	 * @return void
	 */
	public function test_a_closed_manifest_has_no_reference_to_offer() {
		$provider = $this->provider(
			array(
				$this->login(),
				array( 200, array( 'binaryData' => base64_encode( '%PDF-manifest' ) ) ),
			)
		);

		$this->assertSame( '', $provider->close_manifest( array( 'ship-1' ) )->get( 'reference' ) );
	}

	/**
	 * Asked to call a courier off, DPD says honestly that it cannot: its API
	 * has no endpoint for it, and a button that fails when pressed is worse
	 * than no button.
	 *
	 * @return void
	 */
	public function test_a_booked_courier_cannot_be_called_off() {
		$result = ( new WC_ESM_Provider_Dpd() )->cancel_pickup( 'pick-1' );

		$this->assertSame( 'unsupported', $result->get_code() );
	}
}

<?php
/**
 * One way to call a carrier.
 *
 * @package Estonian_Shipping_Methods_For_WooCommerce
 */

require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/class-wc-esm-api-response.php';
require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/class-wc-esm-carrier-client.php';

/**
 * Tests for WC_ESM_Carrier_Client.
 */
class Test_Carrier_Client extends WC_ESM_Test_Case {

	/**
	 * The last call WordPress was asked to make.
	 *
	 * @var array
	 */
	protected $sent = array();

	/**
	 * Stand WordPress's HTTP functions up, recording what they are asked.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->sent            = array();
		$GLOBALS['wc_esm_http'] = array(
			'code' => 200,
			'body' => '{"ok":true}',
			'error' => null,
		);
		$sent = &$this->sent;

		Brain\Monkey\Functions\when( 'wp_remote_request' )->alias(
			static function ( $url, $args ) use ( &$sent ) {
				$sent = compact( 'url', 'args' );

				return $GLOBALS['wc_esm_http']['error'] ? $GLOBALS['wc_esm_http']['error'] : $GLOBALS['wc_esm_http'];
			}
		);

		Brain\Monkey\Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static function ( $response ) {
				return $response['code'];
			}
		);

		Brain\Monkey\Functions\when( 'wp_remote_retrieve_body' )->alias(
			static function ( $response ) {
				return $response['body'];
			}
		);
	}

	/**
	 * A client for a carrier.
	 *
	 * @param array $headers Default headers.
	 *
	 * @return WC_ESM_Carrier_Client
	 */
	protected function client( $headers = array( 'Authorization' => 'key' ) ) {
		return new WC_ESM_Carrier_Client( 'https://api.example.com/v1/', $headers );
	}

	/**
	 * The endpoint hangs off the base, with exactly one slash between them
	 * however either was written.
	 *
	 * @return void
	 */
	public function test_the_endpoint_hangs_off_the_base() {
		$this->client()->get( 'orders' );
		$this->assertSame( 'https://api.example.com/v1/orders', $this->sent['url'] );

		( new WC_ESM_Carrier_Client( 'https://api.example.com/v1', array() ) )->get( '/orders' );
		$this->assertSame( 'https://api.example.com/v1/orders', $this->sent['url'] );
	}

	/**
	 * A query string on the endpoint survives.
	 *
	 * @return void
	 */
	public function test_a_query_string_survives() {
		$this->client()->get( 'labels?format=A6&barcode=B1' );

		$this->assertSame( 'https://api.example.com/v1/labels?format=A6&barcode=B1', $this->sent['url'] );
	}

	/**
	 * The carrier's own headers go on every call, and JSON is asked for.
	 *
	 * @return void
	 */
	public function test_the_carriers_headers_go_on_every_call() {
		$this->client()->get( 'orders' );

		$this->assertSame( 'key', $this->sent['args']['headers']['Authorization'] );
		$this->assertSame( 'application/json', $this->sent['args']['headers']['Accept'] );
	}

	/**
	 * A header for one call only is added without disturbing the rest - a
	 * bearer token that changes between calls is the case for this.
	 *
	 * @return void
	 */
	public function test_a_header_can_be_added_for_one_call() {
		$this->client()->get( 'orders', array( 'Authorization' => 'Bearer fresh' ) );

		$this->assertSame( 'Bearer fresh', $this->sent['args']['headers']['Authorization'] );
	}

	/**
	 * An array body travels as JSON, because that is what every one of these
	 * carriers speaks.
	 *
	 * @return void
	 */
	public function test_an_array_body_travels_as_json() {
		$this->client()->post( 'orders', array( 'weight' => 1.25 ) );

		$this->assertSame( 'POST', $this->sent['args']['method'] );
		$this->assertSame( array( 'weight' => 1.25 ), json_decode( $this->sent['args']['body'], true ) );
	}

	/**
	 * A body that is already a string is sent as it is: a caller that
	 * encoded it itself is not encoded twice.
	 *
	 * @return void
	 */
	public function test_a_string_body_is_sent_as_it_is() {
		$this->client()->post( 'orders', '{"already":"encoded"}' );

		$this->assertSame( '{"already":"encoded"}', $this->sent['args']['body'] );
	}

	/**
	 * A GET carries no body at all.
	 *
	 * @return void
	 */
	public function test_a_get_carries_no_body() {
		$this->client()->get( 'orders' );

		$this->assertArrayNotHasKey( 'body', $this->sent['args'] );
	}

	/**
	 * Any method can be asked for: one carrier cancels a pickup with DELETE.
	 *
	 * @return void
	 */
	public function test_any_method_can_be_asked_for() {
		$this->client()->request( 'DELETE', 'pickups', array( 'ids' => 'P-1' ) );

		$this->assertSame( 'DELETE', $this->sent['args']['method'] );
	}

	/**
	 * What came back is a response, carrying the status and the body.
	 *
	 * @return void
	 */
	public function test_what_comes_back_is_a_response() {
		$GLOBALS['wc_esm_http'] = array( 'code' => 201, 'body' => '{"id":"ship-1"}', 'error' => null );

		$response = $this->client()->post( 'orders', array() );

		$this->assertTrue( $response->ok() );
		$this->assertSame( 201, $response->code() );
		$this->assertSame( 'ship-1', $response->get( 'id' ) );
	}

	/**
	 * A request WordPress could not make at all is a transport error, not a
	 * carrier refusal: no status, and the reason kept.
	 *
	 * @return void
	 */
	public function test_a_request_that_could_not_be_made_is_a_transport_error() {
		$GLOBALS['wc_esm_http']['error'] = new WP_Error( 'http_request_failed', 'could not resolve host' );

		$response = $this->client()->post( 'orders', array() );

		$this->assertFalse( $response->ok() );
		$this->assertSame( 0, $response->code() );
		$this->assertSame( 'could not resolve host', $response->raw() );
	}

	/**
	 * A carrier that answers with a PDF is not mangled into JSON.
	 *
	 * @return void
	 */
	public function test_a_pdf_answer_comes_back_whole() {
		$GLOBALS['wc_esm_http'] = array( 'code' => 200, 'body' => '%PDF-1.4 label', 'error' => null );

		$this->assertSame( '%PDF-1.4 label', $this->client()->get( 'labels' )->raw() );
	}
}

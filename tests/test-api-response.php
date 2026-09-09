<?php
/**
 * What a carrier answered, as one thing.
 *
 * @package Estonian_Shipping_Methods_For_WooCommerce
 */

require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/class-wc-esm-api-response.php';

/**
 * Tests for WC_ESM_Api_Response.
 */
class Test_Api_Response extends WC_ESM_Test_Case {

	/**
	 * Any 2xx is a success; everything else is not.
	 *
	 * @return void
	 */
	public function test_the_two_hundreds_are_the_successes() {
		$this->assertTrue( ( new WC_ESM_Api_Response( 200, '' ) )->ok() );
		$this->assertTrue( ( new WC_ESM_Api_Response( 201, '' ) )->ok() );
		$this->assertTrue( ( new WC_ESM_Api_Response( 204, '' ) )->ok() );
		$this->assertFalse( ( new WC_ESM_Api_Response( 400, '' ) )->ok() );
		$this->assertFalse( ( new WC_ESM_Api_Response( 401, '' ) )->ok() );
		$this->assertFalse( ( new WC_ESM_Api_Response( 500, '' ) )->ok() );
	}

	/**
	 * A carrier that wants an exact code can ask for one: DPD's expired
	 * token is a 401 and nothing else.
	 *
	 * @return void
	 */
	public function test_an_exact_code_can_be_asked_for() {
		$this->assertTrue( ( new WC_ESM_Api_Response( 401, '' ) )->is( 401 ) );
		$this->assertFalse( ( new WC_ESM_Api_Response( 403, '' ) )->is( 401 ) );
	}

	/**
	 * A request that never reached the carrier is not a 200 with an empty
	 * body: it has no code at all, and says why.
	 *
	 * @return void
	 */
	public function test_a_request_that_never_arrived_says_so() {
		$response = WC_ESM_Api_Response::transport_error( 'could not resolve host' );

		$this->assertFalse( $response->ok() );
		$this->assertSame( 0, $response->code() );
		$this->assertSame( 'could not resolve host', $response->raw() );
	}

	/**
	 * A JSON body is read as an array.
	 *
	 * @return void
	 */
	public function test_a_json_body_reads_as_an_array() {
		$response = new WC_ESM_Api_Response( 200, '{"barcodes":["B1"],"id":"ship-1"}' );

		$this->assertSame( array( 'B1' ), $response->get( 'barcodes' ) );
		$this->assertSame( 'ship-1', $response->get( 'id' ) );
	}

	/**
	 * A key the carrier did not send reads as the default.
	 *
	 * @return void
	 */
	public function test_a_missing_key_reads_as_the_default() {
		$response = new WC_ESM_Api_Response( 200, '{"id":"ship-1"}' );

		$this->assertNull( $response->get( 'barcodes' ) );
		$this->assertSame( array(), $response->get( 'barcodes', array() ) );
	}

	/**
	 * A label and a manifest come back as PDF bytes rather than JSON. Asking
	 * for them as JSON gives nothing rather than a warning, and the bytes are
	 * there for whoever wants them.
	 *
	 * @return void
	 */
	public function test_a_body_that_is_not_json_is_still_readable() {
		$response = new WC_ESM_Api_Response( 200, '%PDF-1.4 label' );

		$this->assertSame( array(), $response->json() );
		$this->assertNull( $response->get( 'anything' ) );
		$this->assertSame( '%PDF-1.4 label', $response->raw() );
	}

	/**
	 * A JSON body that is a bare list, not an object, is still an array.
	 *
	 * @return void
	 */
	public function test_a_bare_json_list_is_still_an_array() {
		$this->assertSame( array( 'a', 'b' ), ( new WC_ESM_Api_Response( 200, '["a","b"]' ) )->json() );
	}

	/**
	 * An empty body is an empty array, not a null anybody has to guard.
	 *
	 * @return void
	 */
	public function test_an_empty_body_is_an_empty_array() {
		$this->assertSame( array(), ( new WC_ESM_Api_Response( 204, '' ) )->json() );
	}
}

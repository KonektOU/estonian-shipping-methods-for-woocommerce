<?php
/**
 * A carrier client that talks to the test instead of the network.
 *
 * @package Estonian_Shipping_Methods_For_WooCommerce
 */

require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/class-wc-esm-api-response.php';
require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/class-wc-esm-carrier-client.php';

/**
 * Records what it was asked and answers with what the test prepared.
 *
 * One of these stands in for the network in every provider's tests, so
 * everything above the call - the payload, the token, the retry, the reading
 * of the answer - runs for real.
 */
class WC_ESM_Fake_Client extends WC_ESM_Carrier_Client {

	/**
	 * Calls made, in order.
	 *
	 * @var array
	 */
	public $calls = array();

	/**
	 * Answers to give, in order.
	 *
	 * @var array
	 */
	public $answers = array();

	/**
	 * Constructor.
	 *
	 * @param array $answers Answers to give, as code => body pairs or
	 *                       WC_ESM_Api_Response objects.
	 */
	public function __construct( $answers = array() ) {
		parent::__construct( 'https://carrier.test', array() );

		foreach ( $answers as $answer ) {
			$this->answers[] = $answer instanceof WC_ESM_Api_Response
				? $answer
				: new WC_ESM_Api_Response( $answer[0], is_string( $answer[1] ) ? $answer[1] : wp_json_encode( $answer[1] ) );
		}
	}

	/**
	 * Record the call and hand back the next prepared answer.
	 *
	 * @param string       $method   HTTP method.
	 * @param string       $endpoint Endpoint.
	 * @param array|string $body     Body.
	 * @param array        $headers  Headers for this call.
	 *
	 * @return WC_ESM_Api_Response
	 */
	public function request( $method, $endpoint, $body = null, $headers = array() ) {
		$this->calls[] = compact( 'method', 'endpoint', 'body', 'headers' );

		$answer = array_shift( $this->answers );

		return $answer ? $answer : new WC_ESM_Api_Response( 200, '' );
	}

	/**
	 * The body of one call, decoded.
	 *
	 * @param int $index Which call.
	 *
	 * @return array
	 */
	public function body( $index = 0 ) {
		$body = $this->calls[ $index ]['body'];

		return is_array( $body ) ? $body : (array) json_decode( (string) $body, true );
	}

	/**
	 * The bearer token one call carried, if any.
	 *
	 * @param int $index Which call.
	 *
	 * @return string
	 */
	public function token( $index = 0 ) {
		$headers = $this->calls[ $index ]['headers'];

		return isset( $headers['Authorization'] ) ? str_replace( 'Bearer ', '', $headers['Authorization'] ) : '';
	}

	/**
	 * The endpoint of one call.
	 *
	 * @param int $index Which call.
	 *
	 * @return string
	 */
	public function endpoint( $index = 0 ) {
		return $this->calls[ $index ]['endpoint'];
	}
}

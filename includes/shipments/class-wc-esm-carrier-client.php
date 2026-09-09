<?php
/**
 * One way to call a carrier.
 *
 * @package Estonian_Shipping_Methods_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Talks to one carrier's API.
 *
 * Four carriers, four different hosts, four different ways of proving who you
 * are - and beyond that, the same call every time: put an endpoint on a base
 * URL, send JSON, read a status and a body back, and turn a request that never
 * left the building into something a caller can tell apart from a refusal.
 *
 * Each provider held its own copy of that. Now each provider says what its
 * base URL and headers are, and this makes the call.
 */
class WC_ESM_Carrier_Client {

	/**
	 * Where the carrier's API lives, including any path prefix.
	 *
	 * @var string
	 */
	protected $base_url;

	/**
	 * Headers every call carries.
	 *
	 * @var array
	 */
	protected $headers;

	/**
	 * How long to wait.
	 *
	 * @var int
	 */
	protected $timeout;

	/**
	 * Constructor.
	 *
	 * @param string $base_url Where the API lives.
	 * @param array  $headers  Headers every call carries.
	 * @param int    $timeout  Seconds to wait.
	 */
	public function __construct( $base_url, $headers = array(), $timeout = 30 ) {
		$this->base_url = (string) $base_url;
		$this->headers  = (array) $headers;
		$this->timeout  = (int) $timeout;
	}

	/**
	 * A GET.
	 *
	 * @param string $endpoint Endpoint, with any query string.
	 * @param array  $headers  Headers for this call only.
	 *
	 * @return WC_ESM_Api_Response
	 */
	public function get( $endpoint, $headers = array() ) {
		return $this->request( 'GET', $endpoint, null, $headers );
	}

	/**
	 * A POST.
	 *
	 * @param string       $endpoint Endpoint.
	 * @param array|string $body     Body; an array is encoded as JSON.
	 * @param array        $headers  Headers for this call only.
	 *
	 * @return WC_ESM_Api_Response
	 */
	public function post( $endpoint, $body = null, $headers = array() ) {
		return $this->request( 'POST', $endpoint, $body, $headers );
	}

	/**
	 * One call.
	 *
	 * @param string       $method   HTTP method.
	 * @param string       $endpoint Endpoint, with any query string.
	 * @param array|string $body     Body; an array is encoded as JSON, a
	 *                               string is sent as it is, null sends none.
	 * @param array        $headers  Headers for this call only, which win over
	 *                               the carrier's own.
	 *
	 * @return WC_ESM_Api_Response
	 */
	public function request( $method, $endpoint, $body = null, $headers = array() ) {
		$args = array(
			'method'  => $method,
			'timeout' => $this->timeout,
			'headers' => array_merge(
				array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				$this->headers,
				$headers
			),
		);

		if ( null !== $body ) {
			$args['body'] = is_string( $body ) ? $body : wp_json_encode( $body );
		}

		$response = wp_remote_request( $this->url( $endpoint ), $args );

		if ( is_wp_error( $response ) ) {
			// A host that does not resolve and a parcel the carrier refused
			// are different problems; a caller can tell them apart by the
			// status being zero.
			return WC_ESM_Api_Response::transport_error( $response->get_error_message() );
		}

		return new WC_ESM_Api_Response(
			wp_remote_retrieve_response_code( $response ),
			wp_remote_retrieve_body( $response )
		);
	}

	/**
	 * The full URL for an endpoint, with exactly one slash in the join
	 * however either side was written.
	 *
	 * @param string $endpoint Endpoint.
	 *
	 * @return string
	 */
	protected function url( $endpoint ) {
		return rtrim( $this->base_url, '/' ) . '/' . ltrim( (string) $endpoint, '/' );
	}
}

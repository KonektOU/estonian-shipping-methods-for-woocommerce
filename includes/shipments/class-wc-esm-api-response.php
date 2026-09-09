<?php
/**
 * What a carrier answered, as one thing.
 *
 * @package Estonian_Shipping_Methods_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One carrier's answer to one request.
 *
 * Carriers answer in two shapes and the same carrier uses both: JSON for
 * everything about a shipment, PDF bytes for a label or a manifest. Before
 * this, each provider passed around an array whose 'body' was sometimes a
 * string, sometimes a decoded array and in one case either - a difference
 * nothing named and every caller had to know.
 *
 * Here the body is always the bytes that arrived, and asking for it as JSON
 * is a separate question with an answer that is always an array.
 */
class WC_ESM_Api_Response {

	/**
	 * HTTP status, or zero when the request never arrived.
	 *
	 * @var int
	 */
	protected $code;

	/**
	 * The body exactly as it arrived.
	 *
	 * @var string
	 */
	protected $body;

	/**
	 * Decoded body, once anybody has asked for it.
	 *
	 * @var array|null
	 */
	protected $decoded = null;

	/**
	 * Constructor.
	 *
	 * @param int    $code HTTP status.
	 * @param string $body Body as it arrived.
	 */
	public function __construct( $code, $body ) {
		$this->code = (int) $code;
		$this->body = (string) $body;
	}

	/**
	 * A request that never reached the carrier.
	 *
	 * Not a failure the carrier chose, so it carries no status: a nonexistent
	 * host and a refused parcel are different problems, and a caller reading
	 * a zero can tell.
	 *
	 * @param string $message Why it did not arrive.
	 *
	 * @return self
	 */
	public static function transport_error( $message ) {
		return new self( 0, $message );
	}

	/**
	 * Whether the carrier did what was asked.
	 *
	 * @return bool
	 */
	public function ok() {
		return $this->code >= 200 && $this->code < 300;
	}

	/**
	 * Whether the status is exactly this one.
	 *
	 * @param int $code Status to test for.
	 *
	 * @return bool
	 */
	public function is( $code ) {
		return (int) $code === $this->code;
	}

	/**
	 * The HTTP status, or zero when the request never arrived.
	 *
	 * @return int
	 */
	public function code() {
		return $this->code;
	}

	/**
	 * The body exactly as it arrived - PDF bytes, an error page, anything.
	 *
	 * @return string
	 */
	public function raw() {
		return $this->body;
	}

	/**
	 * The body as JSON, or an empty array when it is not JSON at all.
	 *
	 * @return array
	 */
	public function json() {
		if ( null === $this->decoded ) {
			$decoded       = json_decode( $this->body, true );
			$this->decoded = is_array( $decoded ) ? $decoded : array();
		}

		return $this->decoded;
	}

	/**
	 * One value out of a JSON body.
	 *
	 * @param string $key     Key.
	 * @param mixed  $default What to answer when the carrier did not send it.
	 *
	 * @return mixed
	 */
	public function get( $key, $default = null ) {
		$json = $this->json();

		return array_key_exists( $key, $json ) ? $json[ $key ] : $default;
	}
}

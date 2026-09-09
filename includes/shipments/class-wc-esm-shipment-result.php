<?php
/**
 * What a carrier call answers with.
 *
 * @package Estonian_Shipping_Methods_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The outcome of asking a carrier to do something.
 *
 * Every provider method that talks to a carrier answers with one of these
 * rather than with a bare array or a WP_Error: a caller has to look at the
 * outcome before it can read anything, and a carrier that does not offer a
 * feature answers in the same shape as one whose API was down.
 *
 * A result is made through one of the three named constructors and never
 * changes afterwards.
 */
class WC_ESM_Shipment_Result {

	/**
	 * Whether the call did what was asked.
	 *
	 * @var bool
	 */
	protected $success;

	/**
	 * What to tell the shopkeeper.
	 *
	 * @var string
	 */
	protected $message;

	/**
	 * Machine-readable outcome, for a caller that needs to branch.
	 *
	 * @var string
	 */
	protected $code;

	/**
	 * What the carrier sent back. Empty on a failure.
	 *
	 * @var array
	 */
	protected $data;

	/**
	 * Use success(), failure() or unsupported().
	 *
	 * @param bool   $success Whether the call did what was asked.
	 * @param string $message What to tell the shopkeeper.
	 * @param string $code    Machine-readable outcome.
	 * @param array  $data    What the carrier sent back.
	 */
	protected function __construct( $success, $message, $code, $data ) {
		$this->success = (bool) $success;
		$this->message = (string) $message;
		$this->code    = (string) $code;
		$this->data    = (array) $data;
	}

	/**
	 * The carrier did what was asked.
	 *
	 * @param array  $data    What it sent back: barcodes, label references.
	 * @param string $message What to tell the shopkeeper, where there is
	 *                        anything worth saying beyond that it worked.
	 *
	 * @return self
	 */
	public static function success( $data = array(), $message = '' ) {
		return new self( true, $message, 'ok', $data );
	}

	/**
	 * The carrier did not.
	 *
	 * @param string $message What to tell the shopkeeper.
	 * @param string $code    Machine-readable outcome; a generic error when
	 *                        the caller has nothing more specific.
	 *
	 * @return self
	 */
	public static function failure( $message, $code = 'error' ) {
		return new self( false, $message, '' !== (string) $code ? $code : 'error', array() );
	}

	/**
	 * This carrier does not do that.
	 *
	 * A distinct code, because it is not a failure to recover from: no retry
	 * will help and nothing is wrong with the shop. Every capability is
	 * optional on some carrier - Cleveron prints no labels and tracks
	 * nothing - and callers gate on supports() before asking. This is what
	 * answers the ones that do not.
	 *
	 * @param string $feature Capability that was asked for.
	 *
	 * @return self
	 */
	public static function unsupported( $feature ) {
		return self::failure(
			sprintf(
				/* translators: %s: capability name, e.g. labels. */
				__( 'This carrier does not offer %s.', 'wc-estonian-shipping-methods' ),
				$feature
			),
			'unsupported'
		);
	}

	/**
	 * Whether the call did what was asked.
	 *
	 * @return bool
	 */
	public function is_success() {
		return $this->success;
	}

	/**
	 * Whether it did not.
	 *
	 * @return bool
	 */
	public function is_failure() {
		return ! $this->success;
	}

	/**
	 * What to tell the shopkeeper.
	 *
	 * @return string
	 */
	public function get_message() {
		return $this->message;
	}

	/**
	 * Machine-readable outcome.
	 *
	 * @return string
	 */
	public function get_code() {
		return $this->code;
	}

	/**
	 * One thing the carrier sent back.
	 *
	 * @param string $key     Key.
	 * @param mixed  $default What to answer when the carrier did not send it.
	 *
	 * @return mixed
	 */
	public function get( $key, $default = null ) {
		return array_key_exists( $key, $this->data ) ? $this->data[ $key ] : $default;
	}

	/**
	 * Everything the carrier sent back.
	 *
	 * @return array
	 */
	public function get_data() {
		return $this->data;
	}
}

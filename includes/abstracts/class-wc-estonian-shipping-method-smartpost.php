<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Smartpost shipping method
 *
 * @class     WC_Estonian_Shipping_Method_Smartpost
 * @extends   WC_Shipping_Method
 * @category  Shipping Methods
 * @package   Estonian_Shipping_Methods_For_WooCommerce
 */
abstract class WC_Estonian_Shipping_Method_Smartpost extends WC_Estonian_Shipping_Method_Terminals {

	/**
	 * Holds terminals once fetched
	 *
	 * @var mixed
	 */
	public $terminals = FALSE;

	/**
	 * Terminals meta and input field name
	 *
	 * @var string
	 */
	public $field_name;

	/**
	 * Formatting option for terminal name
	 *
	 * @var string
	 */
	public $name_format = FALSE;

	/**
	 * Current order ID that is being showed
	 *
	 * @var integer
	 */
	public $order_id = FALSE;

	/**
	 * Just a tracker to understand whether terminals have already been fetched
	 *
	 * @var boolean
	 */
	public $terminals_fetched = FALSE;

	/**
	 * API URL
	 *
	 * @var string
	 */
	public $api_url = 'https://my.smartpost.ee/api/ext/v1/places';

	/**
	 * Shipping method country.
	 *
	 * @var string
	 */
	public $country = 'EE';

	/**
	 * Prefix for terminal number in new system
	 *
	 * @var string
	 */
	public $country_prefix = '01007';

	/**
	 * Class constructor
	 */
	public function __construct() {
		// Set template file name for this method.
		$this->terminals_template = 'smartpost';

		parent::__construct();
	}

	/**
	 * Fetches locations and stores them to cache.
	 *
	 * @return array Terminals
	 */
	public function get_terminals() {
		// Fetch terminals from cache.
		$terminals_transient = $this->get_terminals_cache();
		$terminals           = array();

		// Check if terminals transient exists.
		if ( null !== $terminals_transient ) {
			// Get terminals from transient.
			$terminals = $terminals_transient;
		} else {
			// Get all of the possible places.
			$terminals_request = $this->request_remote_url( $this->get_terminals_url() );

			// Check if successful request.
			if ( true === $terminals_request['success'] ) {
				$terminals = json_decode( $terminals_request['data'] );
				$terminals = $terminals->places->item;
			}

			// Set transient for cache.
			$this->save_terminals_cache( $terminals );
		}

		// Set terminals locally.
		$this->terminals = $terminals;

		// Return terminals.
		return apply_filters( "wc_shipping_{$this->id}_terminals", $terminals, $this->get_shipping_country() );
	}

	/**
	 * Get selected terminal ID from order meta
	 *
	 * @param  integer $order_id Order ID.
	 *
	 * @return integer           Selected terminal
	 */
	public function get_order_terminal( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( $order ) {
			$terminal_id = $order->get_meta( $this->field_name, true );

			if ( strlen( $terminal_id ) < 5 ) {
				return $this->get_prefixed_order_terminal( $terminal_id );
			} else {
				return $terminal_id;
			}
		}

		return false;
	}

	/**
	 * Prepend country prefix to terminal ID
	 *
	 * @param integer $terminal_id Terminal ID.
	 *
	 * @return integer
	 */
	public function get_prefixed_order_terminal( $terminal_id ) {
		return $this->country_prefix . $terminal_id;
	}
}

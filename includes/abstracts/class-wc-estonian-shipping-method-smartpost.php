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
	public $api_url = 'http://iseteenindus.smartpost.ee/api/';

	/**
	 * Class constructor
	 */
	public function __construct() {
		// Set template file name for this method
		$this->terminals_template = 'smartpost';

		parent::__construct();
	}

	/**
	 * Fetches locations and stores them to cache.
	 *
	 * @return array Terminals
	 */
	public function get_terminals() {
		// Fetch terminals from cache
		$terminals_transient = $this->get_terminals_cache();
		$shipping_country    = $this->get_shipping_country();
		$terminals           = array();

		// Check if terminals transient exists
		if ( $terminals_transient !== null ) {
			// Get terminals from transient
			$terminals         = $terminals_transient;
		}
		else {
			// Get all of the possible places
			$terminals_request = $this->request_remote_url( $this->get_terminals_url() );

			// Check if successful request
			if( true === $terminals_request['success'] ) {
				// XML to array
				$terminals     = $this->xml_to_array( $terminals_request['data'] );

				// Check if shipping country if Finland
				if( $shipping_country == 'FI' ) {
					// We may need to merge terminals and post offices together
					if( $this->get_option( 'terminals_filter', 'all' ) == 'both' && $this->terminals_fetched === FALSE ) {
						$this->terminals_fetched = TRUE;
						$more_terminals          = $this->get_terminals();

						$terminals               = array_merge( $terminals, $more_terminals );
					}
				}
			}

			// Set transient for cache
			$this->save_terminals_cache( $terminals );
		}

		// Set terminals locally
		$this->terminals = $terminals;

		// Return terminals
		return apply_filters( 'wc_shipping_'. $this->id .'_terminals', $terminals, $shipping_country );
	}

	/**
	 * Reformat XML to array
	 *
	 * @param  string $data XML as string
	 * @return array        XML as array
	 */
	function xml_to_array( $data ) {
		$xml   = simplexml_load_string( $data );
		$array = array();

		foreach( $xml->item as $item ) {
			$array_row = array();

			foreach( $item as $key => $value ) {
				$array_row[ (string) $key ] = (string) $value;
			}

			$array[] = (object) $array_row;
		}

		return $array;
	}
}
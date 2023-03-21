<?php
defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

/**
 * Smartpost shipping method
 *
 * @class     WC_Estonian_Shipping_Method_Smartpost_Lithuania
 * @extends   WC_Shipping_Method
 * @category  Shipping Methods
 * @package   Estonian_Shipping_Methods_For_WooCommerce
 */
class WC_Estonian_Shipping_Method_Smartpost_Lithuania extends WC_Estonian_Shipping_Method_Smartpost {

	/**
	 * Class constructor
	 */
	public function __construct() {
		// Identify method.
		$this->id           = 'smartpost_lithuania';
		$this->method_title = __( 'Smartpost Lithuania', 'wc-estonian-shipping-methods' );

		// Construct parent.
		parent::__construct();

		// Set country.
		$this->country        = 'LT';
		$this->country_prefix = '01009';
	}

	/**
	 * Get URL where to fetch terminals from
	 *
	 * @return string Terminals remote URL
	 */
	public function get_terminals_url() {
		// Get terminals URL if exists.
		$terminals_url = add_query_arg( 'country', $this->country, $this->api_url );

		return apply_filters( 'wc_shipping_smartpost_terminals_url', $terminals_url, $this->country, $this->api_url );
	}
}

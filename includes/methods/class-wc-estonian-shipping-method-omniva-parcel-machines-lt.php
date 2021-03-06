<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Omniva parcel machines shipping method
 *
 * @class     WC_Estonian_Shipping_Method_Omniva_Parcel_Machines_LT
 * @extends   WC_Estonian_Shipping_Method
 * @category  Shipping Methods
 * @package   Estonian_Shipping_Methods_For_WooCommerce
 */
class WC_Estonian_Shipping_Method_Omniva_Parcel_Machines_LT extends WC_Estonian_Shipping_Method_Omniva {

	/**
	 * Class constructor
	 */
	function __construct() {
		// Identify method
		$this->id               = 'omniva_parcel_machines_lt';
		$this->method_title     = __( 'Omniva Lithuania', 'wc-estonian-shipping-methods' );

		// Construct parent
		parent::__construct();

		$this->country          = 'LT';

		// Set variables which will contain address and which city in locations
		$this->variable_address = 'A2_NAME';
		$this->variable_city    = 'A1_NAME';
	}
}
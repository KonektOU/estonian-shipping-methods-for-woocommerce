<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Omniva post offices shipping method
 *
 * @class     WC_Estonian_Shipping_Method_Omniva_Post_Offices_EE
 * @extends   WC_Estonian_Shipping_Method
 * @category  Shipping Methods
 * @package   Estonian_Shipping_Methods_For_WooCommerce
 */
class WC_Estonian_Shipping_Method_Omniva_Post_Offices_EE extends WC_Estonian_Shipping_Method_Omniva {

	/**
	 * Class constructor
	 */
	function __construct() {
		// Identify method
		$this->id           = 'omniva_post_offices_ee';
		$this->method_title = __( 'Omniva Post Offices Estonia', 'wc-estonian-shipping-methods' );
		
		
		// Construct parent
		parent::__construct();
		$this->terminals_template = 'omniva-postoffice';

		$this->country      = 'EE';
	}

	public function get_terminals( $filter_country = false, $filter_type = 1 ) {
		return parent::get_terminals( $filter_country, $filter_type );
	}
}

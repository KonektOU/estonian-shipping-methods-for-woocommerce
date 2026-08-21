<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Omniva parcel machines shipping method
 *
 * @class     WC_Estonian_Shipping_Method_Omniva_Parcel_Machines_EE
 * @extends   WC_Estonian_Shipping_Method
 * @category  Shipping Methods
 * @package   Estonian_Shipping_Methods_For_WooCommerce
 */
class WC_Estonian_Shipping_Method_Omniva_Parcel_Machines_EE extends WC_Estonian_Shipping_Method_Omniva {

	/**
	 * Class constructor
	 */
	function __construct( $instance_id = 0 ) {
		// Identify method
		$this->id           = 'omniva_parcel_machines_ee';
		$this->method_title = __( 'Omniva Estonia', 'wc-estonian-shipping-methods' );

		// Construct parent
		parent::__construct( $instance_id );

		$this->country      = 'EE';
	}
}
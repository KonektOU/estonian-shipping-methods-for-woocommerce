<?php
defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

/**
 * Omniva parcel machines shipping method
 *
 * @class     WC_Estonian_Shipping_Method_DPD_Shops_LV
 * @extends   WC_Estonian_Shipping_Method_Terminals
 * @category  Shipping Methods
 * @package   Estonian_Shipping_Methods_For_WooCommerce
 */
class WC_Estonian_Shipping_Method_DPD_Shops_LV extends WC_Estonian_Shipping_Method_DPD_Shops {

	/**
	 * Class constructor
	 */
	public function __construct() {
		// Identify method.
		$this->id           = 'dpd_shops_lv';
		$this->method_title = __( 'DPD Shops Latvia', 'wc-estonian-shipping-methods' );

		// Construct parent.
		parent::__construct();

		$this->country            = 'LV';
		$this->terminals_template = 'dpd';
	}
}

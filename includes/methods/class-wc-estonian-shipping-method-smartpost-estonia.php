<?php
defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

/**
 * Smartpost shipping method
 *
 * @class     WC_Estonian_Shipping_Method_Smartpost_Estonia
 * @extends   WC_Shipping_Method
 * @category  Shipping Methods
 * @package   Estonian_Shipping_Methods_For_WooCommerce
 */
class WC_Estonian_Shipping_Method_Smartpost_Estonia extends WC_Estonian_Shipping_Method_Smartpost {

	/**
	 * Class constructor
	 */
	public function __construct() {
		// Identify method.
		$this->id           = 'smartpost_estonia';
		$this->method_title = __( 'Smartpost Estonia', 'wc-estonian-shipping-methods' );

		// Construct parent.
		parent::__construct();

		// Set country.
		$this->country        = 'EE';
		$this->country_prefix = '01007';

		// Add/merge form fields.
		$this->add_extra_form_fields();
	}

	/**
	 * Add terminals filter option to settings.
	 *
	 * @return void
	 */
	public function add_extra_form_fields() {
		$this->form_fields = array_merge(
			$this->form_fields,
			array(
				'terminals_filter' => array(
					'title'   => __( 'Terminals filter', 'wc-estonian-shipping-methods' ),
					'type'    => 'select',
					'default' => 'all',
					'options' => array(
						'express' => __( 'Only terminals with Express delivery', 'wc-estonian-shipping-methods' ),
						'all'     => __( 'All terminals', 'wc-estonian-shipping-methods' ),
					),
				),
			)
		);
	}

	/**
	 * Get URL where to fetch terminals from
	 *
	 * @return string Terminals remote URL
	 */
	public function get_terminals_url() {
		// Get terminals URL if exists.
		$terminals_url = add_query_arg( 'country', $this->country, $this->api_url );

		if ( $terminals_url ) {
			if ( 'express' === $this->get_option( 'terminals_filter', 'all' ) ) {
				$terminals_url = add_query_arg( 'filter', 'express', $terminals_url );
			}
		}

		return apply_filters( 'wc_shipping_smartpost_terminals_url', $terminals_url, $this->country, $this->api_url );
	}
}

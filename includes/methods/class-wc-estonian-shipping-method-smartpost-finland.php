<?php
defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

/**
 * Smartpost shipping method
 *
 * @class     WC_Estonian_Shipping_Method_Smartpost_Finland
 * @extends   WC_Shipping_Method
 * @category  Shipping Methods
 * @package   Estonian_Shipping_Methods_For_WooCommerce
 */
class WC_Estonian_Shipping_Method_Smartpost_Finland extends WC_Estonian_Shipping_Method_Smartpost {

	/**
	 * Class constructor
	 */
	public function __construct() {
		// Identify method.
		$this->id           = 'smartpost_finland';
		$this->method_title = __( 'Smartpost Finland', 'wc-estonian-shipping-methods' );

		// Construct parent.
		parent::__construct();

		// Set country.
		$this->country        = 'FI';
		$this->country_prefix = '';

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
					'default' => 'terminals',
					'options' => array(
						'terminals'   => __( 'Only terminals', 'wc-estonian-shipping-methods' ),
						'postoffices' => __( 'Only post offices', 'wc-estonian-shipping-methods' ),
						'both'        => __( 'Both', 'wc-estonian-shipping-methods' ),
					),
				),
			)
		);
	}

	/**
	 * Get URL where to fetch terminals from.
	 *
	 * @return string Terminals remote URL
	 */
	public function get_terminals_url() {
		// Get terminals URL if exists.
		$terminals_url = add_query_arg( 'country', $this->country, $this->api_url );

		if ( $terminals_url ) {
			if ( 'terminals' === $this->get_option( 'terminals_filter', 'terminals' ) ) {
				$terminals_url = add_query_arg( 'type', 'apt', $terminals_url );
			} elseif ( 'postoffices' === $this->get_option( 'terminals_filter', 'terminals' ) ) {
				$terminals_url = add_query_arg( 'type', 'po', $terminals_url );
			}
		}

		return apply_filters( 'wc_shipping_smartpost_terminals_url', $terminals_url, $this->country, $this->api_url );
	}
}

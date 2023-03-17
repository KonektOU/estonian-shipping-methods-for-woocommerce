<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Abstract class for all of our shipping methods
 *
 * @class     WC_Estonian_Shipping_Method
 * @extends   WC_Shipping_Method
 * @category  Shipping Methods
 * @package   Estonian_Shipping_Methods_For_WooCommerce
 */
abstract class WC_Estonian_Shipping_Method extends WC_Shipping_Method {
	/**
	 * Shipping method country
	 *
	 * @var string
	 */
	public $country = 'EE';

	/**
	 * __construct function.
	 *
	 * @access public
	 * @return void
	 */
	public function __construct() {
		// Get the settings
		$this->title                        = $this->get_option( 'title', $this->method_title );
		$this->enabled                      = $this->get_option( 'enabled', 'no' );
		$this->shipping_price               = $this->get_option( 'shipping_price', 0 );
		$this->free_shipping_amount         = $this->get_option( 'free_shipping_amount', 0 );
		$this->enable_free_shipping_coupons = $this->get_option( 'enable_free_shipping_coupons', 'no' ) == 'yes';
		$this->tax_status                   = $this->get_option( 'tax_status', 0 );

		// Load the settings
		$this->init_form_fields();
		$this->init_settings();

		// Actions
		add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/**
	 * Set settings fields
	 *
	 * @return void
	 */
	function init_form_fields() {
		// Set fields
		$this->form_fields = array(
			'enabled'                  => array(
				'title'                => __( 'Enable method', 'wc-estonian-shipping-methods' ),
				'type'                 => 'checkbox',
				'default'              => 'no',
				'label'                => __( 'Enable', 'wc-estonian-shipping-methods' )
			),
			'title'                    => array(
				'title'                => __( 'Title', 'wc-estonian-shipping-methods' ),
				'type'                 => 'text',
				'description'          => __( 'This controls the title which user sees during checkout.', 'wc-estonian-shipping-methods' ),
				'default'              => $this->get_title(),
				'desc_tip'             => TRUE
			),
			'shipping_price'           => array(
				'title'                => __( 'Shipping price', 'wc-estonian-shipping-methods' ),
				'type'                 => 'price',
				'placeholder'          => wc_format_localized_price( 0 ),
				'description'          => __( 'Without taxes', 'wc-estonian-shipping-methods' ),
				'default'              => '0',
				'desc_tip'             => TRUE
			),
			'free_shipping_amount'     => array(
				'title'                => __( 'Free shipping amount', 'wc-estonian-shipping-methods' ),
				'type'                 => 'price',
				'placeholder'          => wc_format_localized_price( 0 ),
				'description'          => __( 'Shipping will be free of charge, if order total is equal or bigger than this value. Zero will disable free shipping.', 'wc-estonian-shipping-methods' ),
				'default'              => '0',
				'desc_tip'             => TRUE
			),
			'enable_free_shipping_coupons' => array(
				'title'                    => __( 'Enable free shipping coupons', 'wc-estonian-shipping-methods' ),
				'type'                     => 'checkbox',
				'default'                  => 'no',
				'label'                    => __( 'Enable', 'wc-estonian-shipping-methods' ),
				'description'              => sprintf( __( 'Enable this if you want to make this shipping method free of charge when free shipping coupon is applied to customer&rsquo;s cart. Read more about free shipping and coupons from %s.', 'wc-estonian-shipping-methods' ), sprintf( '<a href="https://docs.woocommerce.com/document/free-shipping/#section-2" target="_blank">%s</a>', __( 'WooCommerce&rsquo;s documentation', 'wc-estonian-shipping-methods' ) ) )
			),
			'tax_status'               => array(
				'title'                => __( 'Tax status', 'wc-estonian-shipping-methods' ),
				'type'                 => 'select',
				'description'          => '',
				'default'              => 'none',
				'options'              => array(
					'taxable'          => __( 'Taxable', 'wc-estonian-shipping-methods' ),
					'none'             => __( 'None', 'wc-estonian-shipping-methods' )
				)
			),
		);
	}

	/**
	 * Check if shipping is available based on country and packages.
	 *
	 * @param array $package Shipping package.
	 * @return bool
	 */
	public function is_available( $package = array() ) {
		return ! ( 'no' === $this->enabled ) && ( ! isset( $this->country ) || ( isset( $this->country ) && isset( $package['destination'] ) && isset( $package['destination']['country'] ) && $package['destination']['country'] == $this->country ) );
	}

	/**
	 * Calculate shipping price
	 *
	 * @return array
	 */
	public function calculate_shipping( $package = array() ) {
		$is_free            = false;
		$free_shipping_from = wc_format_decimal( $this->free_shipping_amount );
		$cart_total_cost    = WC()->cart->get_displayed_subtotal();

		if ( WC()->cart->display_prices_including_tax() ) {
			$cart_total_cost = round( $cart_total_cost - ( WC()->cart->get_discount_total() + WC()->cart->get_discount_tax() ), wc_get_price_decimals() );
		} else {
			$cart_total_cost = round( $cart_total_cost - WC()->cart->get_discount_total(), wc_get_price_decimals() );
		}

		if ( $free_shipping_from > 0 && $cart_total_cost >= $free_shipping_from ) {
			$is_free = true;
		}

		// Check if free shipping coupon can set the cost to zero.
		if ( $this->enable_free_shipping_coupons ) {
			$coupons = WC()->cart->get_coupons();

			if ( $coupons ) {
				foreach ( $coupons as $code => $coupon ) {
					if ( $coupon->is_valid() && $coupon->get_free_shipping() ) {
						$is_free = true;

						break;
					}
				}
			}
		}

		$args = array(
			'id'      => $this->get_rate_id(),
			'label'   => $this->title,
			'cost'    => $is_free ? 0 : $this->shipping_price,
			'package' => $package,
		);

		if ( 'none' === $this->tax_status ) {
			$args['taxes'] = false;
		}

		$this->add_rate( $args );
	}

	/**
	 * Get order shipping country
	 *
	 * @return string Shipping country code
	 */
	function get_shipping_country() {
		$country     = FALSE;

		if( isset( $this->order_id ) && $this->order_id ) {
			$order   = wc_get_order( $this->order_id );
			$country = wc_esm_get_order_shipping_country( $order );
		}
		elseif( WC()->customer ) {
			$country = WC()->customer->get_shipping_country();
		}

		if( ! $country ) {
			$country = WC()->countries->get_base_country();
		}

		return $country;
	}

	/**
	 * Easier debugging
	 *
	 * @param  mixed $data Data to be saved
	 * @return void
	 */
	function debug( $data ) {
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG === TRUE ) {
			$logger = new WC_Logger();
			$logger->add( $this->id, is_array( $data ) || is_object( $data ) ? print_r( $data, TRUE ) : var_export( $data, true ) );
		}
	}

	/**
	 * Validates user submitted phone number.
	 *
	 * @param  array $posted Checkout data
	 *
	 * @return void
	 */
	function validate_customer_phone_number( $posted ) {
		// Chcek if our field was submitted
		if( isset( $_POST['billing_phone'] ) && $phone_number = $_POST['billing_phone'] ) {
			// Be sure shipping method was posted
			if( isset( $posted['shipping_method'] ) && is_array( $posted['shipping_method'] ) ) {
				// Check if it was regular parcel terminal
				if( in_array( $this->id, $posted['shipping_method'] ) ) {
					// Remove spaces
					$phone_number        = str_replace( ' ' , '', $phone_number );
					$have_country_prefix = substr( $phone_number, 0, 1 ) == '+';
					$is_phone_valid      = apply_filters( 'wc_shipping_' . $this->id . '_is_phone_valid', $have_country_prefix, $phone_number, $posted );

					// If phone is not valid, add error
					if( ! $is_phone_valid ) {
						// Add checkout error
						wc_add_notice( __( 'Please add country prefix to the phone number (eg. +372).', 'wc-estonian-shipping-methods' ), 'error' );
					}
				}
			}
		}
	}
}

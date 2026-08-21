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
	 * Flat price of this shipping method
	 *
	 * @var string|float
	 */
	public $shipping_price;

	/**
	 * Cart total from which shipping becomes free
	 *
	 * @var string|float
	 */
	public $free_shipping_amount;

	/**
	 * Whether free shipping coupons apply to this method
	 *
	 * @var boolean
	 */
	public $enable_free_shipping_coupons;

	/**
	 * __construct function.
	 *
	 * @access public
	 * @return void
	 */
	public function __construct( $instance_id = 0 ) {
		$this->instance_id = absint( $instance_id );

		// A zone method: added to a shipping zone, configured there, and
		// enabled or disabled by the zone rather than by a setting of its own.
		// Every setting below belongs to the instance, so one shop can offer
		// the same carrier at different prices in different zones.
		$this->supports = array(
			'shipping-zones',
			'instance-settings',
			'instance-settings-modal',
		);

		// Load the settings
		$this->init_form_fields();
		$this->init_settings();

		// Get the settings
		$this->title                        = $this->get_option( 'title', $this->method_title );
		$this->shipping_price               = $this->get_option( 'shipping_price', 0 );
		$this->free_shipping_amount         = $this->get_option( 'free_shipping_amount', 0 );
		$this->enable_free_shipping_coupons = $this->get_option( 'enable_free_shipping_coupons', 'no' ) == 'yes';
		$this->tax_status                   = $this->get_option( 'tax_status', 0 );

		// Actions
		add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/**
	 * Set settings fields
	 *
	 * @return void
	 */
	function init_form_fields() {
		// Set fields. Instance fields: WooCommerce shows these in the zone's
		// method modal, and get_option() reads them from the instance first.
		// There is no "enabled" among them any more - a zone method is enabled
		// by the zone.
		$this->instance_form_fields = array(
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
			'shipping_classes'         => array(
				'title'                => __( 'Only for shipping classes', 'wc-estonian-shipping-methods' ),
				'type'                 => 'multiselect',
				'class'                => 'wc-enhanced-select',
				'css'                  => 'width: 400px;',
				'default'              => '',
				'options'              => $this->get_shipping_class_options(),
				'description'          => __( 'Offer this method only when everything in the cart is in one of these classes. Leave empty to offer it for everything.', 'wc-estonian-shipping-methods' ),
				'desc_tip'             => true,
			),
			'max_weight'               => array(
				'title'                => sprintf(
					/* translators: %s: the shop's weight unit, e.g. kg. */
					__( 'Maximum cart weight (%s)', 'wc-estonian-shipping-methods' ),
					esc_html( get_option( 'woocommerce_weight_unit', 'kg' ) )
				),
				'type'                 => 'text',
				'default'              => '',
				'placeholder'          => __( 'No limit', 'wc-estonian-shipping-methods' ),
				'description'          => __( 'Hide this method when the cart weighs more than this. A parcel terminal will not take everything a courier will. Leave empty for no limit.', 'wc-estonian-shipping-methods' ),
				'desc_tip'             => true,
			),
			'tax_status'               => array(
				'title'                => __( 'Tax status', 'wc-estonian-shipping-methods' ),
				'type'                 => 'select',
				// Taxable, as WooCommerce's own shipping methods default and as
				// shipping normally is here: a shop that never opened this
				// setting used to charge no VAT on delivery at all. Methods
				// that already exist keep whatever they were doing - the
				// upgrade to 1.8.0 writes their current answer down first.
				'description'          => __( 'Whether VAT is added to the shipping cost. Shipping is usually taxable.', 'wc-estonian-shipping-methods' ),
				'default'              => 'taxable',
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
		$available = ! ( 'no' === $this->enabled )
			&& ( ! isset( $this->country ) || ( isset( $this->country ) && isset( $package['destination'] ) && isset( $package['destination']['country'] ) && $package['destination']['country'] == $this->country ) );

		if ( $available ) {
			$available = $this->is_available_for_contents( $package );
		}

		return apply_filters( 'woocommerce_shipping_' . $this->id . '_is_available', $available, $package, $this );
	}


	/**
	 * Whether this method will carry what is in the cart.
	 *
	 * A parcel terminal will not take a wardrobe, and shops have been asking
	 * for a way to say so since 2018 - by shipping class, or by weight, or
	 * both. Empty settings mean no restriction, which is what every existing
	 * method has.
	 *
	 * @param array $package Shipping package.
	 *
	 * @return boolean
	 */
	public function is_available_for_contents( $package = array() ) {
		$classes = (array) $this->get_option( 'shipping_classes', array() );
		$classes = array_filter( array_map( 'absint', $classes ) );

		$max_weight = (float) $this->get_option( 'max_weight', 0 );
		$weight     = 0;

		foreach ( (array) ( $package['contents'] ?? array() ) as $item ) {
			if ( empty( $item['data'] ) || ! is_a( $item['data'], 'WC_Product' ) ) {
				continue;
			}

			/** @var \WC_Product $product */
			$product  = $item['data'];
			$quantity = isset( $item['quantity'] ) ? (int) $item['quantity'] : 1;

			// Every product has to be one this method carries: a cart with one
			// wardrobe in it cannot go to a parcel terminal, however small the
			// rest of it is.
			if ( ! empty( $classes ) && ! in_array( (int) $product->get_shipping_class_id(), $classes, true ) ) {
				return false;
			}

			if ( $max_weight > 0 && $product->has_weight() ) {
				// In the shop's own weight unit, which is the unit the setting
				// is labelled with and the unit these products are weighed in.
				$weight += (float) $product->get_weight() * $quantity;
			}
		}

		if ( $max_weight > 0 && $weight > $max_weight ) {
			return false;
		}

		return true;
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
	 * The shop's shipping classes, for the setting above.
	 *
	 * @return array class id => name.
	 */
	protected function get_shipping_class_options() {
		$options = array();

		// Straight from the taxonomy, not from WC()->shipping(): these fields
		// are built while WooCommerce is initialising its shipping methods, and
		// asking WC()->shipping() there sends it round the same loop until the
		// memory runs out.
		$classes = get_terms(
			array(
				'taxonomy'   => 'product_shipping_class',
				'hide_empty' => false,
			)
		);

		if ( is_wp_error( $classes ) ) {
			return $options;
		}

		foreach ( (array) $classes as $shipping_class ) {
			$options[ $shipping_class->term_id ] = $shipping_class->name;
		}

		return $options;
	}


	/**
	 * Is this method one of the ones chosen?
	 *
	 * A zone method's rate is "id:instance" - omniva_parcel_machines_ee:1 -
	 * where before it was just the id, so comparing the two with in_array()
	 * stopped matching the moment these became zone methods, and with it went
	 * the terminal dropdown on the classic checkout.
	 *
	 * @param array $rates Chosen shipping method rate ids.
	 *
	 * @return boolean
	 */
	public function is_chosen_method( $rates ) {
		foreach ( (array) $rates as $rate ) {
			$parts = explode( ':', (string) $rate );

			if ( $this->id === $parts[0] ) {
				return true;
			}
		}

		return false;
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
				if( $this->is_chosen_method( $posted['shipping_method'] ) ) {
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

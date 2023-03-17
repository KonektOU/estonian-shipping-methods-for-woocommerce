<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Smartpost shipping method
 *
 * @class     WC_Estonian_Shipping_Method_Smartpost_Courier
 * @extends   WC_Shipping_Method
 * @category  Shipping Methods
 * @package   Estonian_Shipping_Methods_For_WooCommerce
 */
class WC_Estonian_Shipping_Method_Smartpost_Courier extends WC_Estonian_Shipping_Method_Smartpost {

	/**
	 * Class constructor
	 */
	function __construct() {
		// Identify method.
		$this->id           = 'smartpost_courier';
		$this->method_title = __( 'SmartCOURIER', 'wc-estonian-shipping-methods' );

		// Construct parent.
		parent::__construct();

		$this->country = 'EE';

		// Translations.
		$this->i18n_selected_terminal = __( 'Chosen SmartCOURIER time widnow', 'wc-estonian-shipping-methods' );

		// Add/merge form fields.
		$this->add_form_fields();
	}

	/**
	 * Overwrite the form fields, so additional fields would not be added
	 *
	 * @return array Setting fields
	 */
	public function add_form_fields() {
		return $this->form_fields;
	}

	/**
	 * Fetch available time windows for courier
	 *
	 * @return array Time Windows
	 */
	function get_courier_time_windows() {
		return array(
			'1' => __( 'All times', 'wc-estonian-shipping-methods' ),
			'2' => __( 'From 09:00 to 17:00', 'wc-estonian-shipping-methods' ),
			'3' => __( 'From 17:00 to 21:00', 'wc-estonian-shipping-methods' )
		);
	}

	/**
	 * Outputs user selected Smartpost terminal in different locations (admin screen, email, orders)
	 *
	 * @param  mixed $order Order (ID or WC_Order)
	 * @return void
	 */
	function show_selected_terminal( $order ) {
		if( $order->has_shipping_method( $this->id ) ) {
			// Fetch selected terminal ID
			$window_value = $this->get_order_terminal( wc_esm_get_order_id( $order ) );
			$time_windows = $this->get_courier_time_windows();

			$window_name = isset( $time_windows[ $window_value ] ) ? $time_windows[ $window_value ] : reset( $time_windows );

			// Output selected terminal to user customer details
			if( current_filter() == 'woocommerce_order_details_after_customer_details' ) {
				if( version_compare( WC_VERSION, '2.3.0', '<' ) ) {
					$terminal  = '<dt>' . $this->i18n_selected_terminal . ':</dt>';
					$terminal .= '<dd>' . $window_name . '</dd>';
				}
				else {
					$terminal  = '<tr>';
					$terminal .= '<th>' . $this->i18n_selected_terminal . ':</th>';
					$terminal .= '<td data-title="' . $this->i18n_selected_terminal . '">' . $window_name . '</td>';
					$terminal .= '</tr>';
				}
			}
			elseif( current_filter() == 'woocommerce_email_customer_details' ) {
				$terminal  = '<h2>' . $this->i18n_selected_terminal . '</h2>';
				$terminal .= '<p>'. $window_name .'</p>';
			}
			// Output selected terminal to everywhere else
			else {
				$terminal  = '<div class="selected_terminal">';
				$terminal .= '<div><strong>' . $this->i18n_selected_terminal . '</strong></div>';
				$terminal .= $window_name;
				$terminal .= '</div>';
			}

			// Allow manipulating output
			echo apply_filters( 'wc_shipping_'. $this->id .'_selected_terminal', $terminal, $window_value, $window_name, current_filter() );
		}
	}

	/**
	 * Adds dropdown selection of terminals right after shipping in checkout
	 * @return void
	 */
	function review_order_after_shipping() {
		// Get currently selected shipping methods
		$chosen_shipping_methods = WC()->session->get( 'chosen_shipping_methods' );

		// Check if ours is one of the selected methods
		if( ! empty( $chosen_shipping_methods ) && in_array( $this->id, $chosen_shipping_methods ) ) {
			// Get selected window
			$selected_window   = WC()->session->get( $this->field_name );

			// Set data for terminals template
			$template_data = array(
				'windows'    => $this->get_courier_time_windows(),
				'field_name' => $this->field_name,
				'field_id'   => $this->field_name,
				'selected'   => $selected_window ? $selected_window : ''
			);

			// Allow to do some activity before terminals
			do_action( $this->id . '_before_time_windows' );

			// Get time_windows template
			wc_get_template( 'checkout/form-shipping-smartpost-courier.php', $template_data );

			// Allow to do some activity after time_windows
			do_action( $this->id . '_after_time_windows' );
		}
	}

	/**
	 * Validates user submitted terminal
	 *
	 * @param  array $posted Checkout data
	 *
	 * @return void
	 */
	public function validate_user_selected_terminal( $posted ) {
		// Chcek if our field was submitted
		if( isset( $_POST[ $this->field_name ] ) && $_POST[ $this->field_name ] == '' ) {
			// Be sure shipping method was posted
			if( isset( $posted['shipping_method'] ) && is_array( $posted['shipping_method'] ) ) {
				if( in_array( $this->id, $posted['shipping_method'] ) ) {
					// Add checkout error
					wc_add_notice( __( 'Please select a courier timewindow', 'wc-estonian-shipping-methods' ), 'error' );
				}
			}
		}
	}

	/** Fetches time window
	 *
	 * @param string $window_value
	 *
	 * @return string;
	 */
	public function get_terminal_name( $window_value ) {
		// Fetch selected window.
		$time_windows = $this->get_courier_time_windows();

		return isset( $time_windows[ $window_value ] ) ? $time_windows[ $window_value ] : reset( $time_windows );
	}
}

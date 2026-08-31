<?php
/**
 * Get order ID based on WC version
 *
 * @since  1.3.1
 * @param  WC_Order $order Order
 * @return integer         Order ID
 */
function wc_esm_get_order_id( $order ) {
	if( method_exists( $order, 'get_id' ) ) {
		return $order->get_id();
	}
	elseif( is_integer( $order ) ) {
		return $order;
	}
	else {
		return $order->id;
	}
}

/**
 * Get custom shipping full name based on WC version
 *
 * @since  1.3.1
 * @param  WC_Order $order Order
 * @return integer         Order ID
 */
function wc_esm_get_customer_shipping_name( $order ) {
	if( method_exists( $order, 'get_shipping_first_name' ) && method_exists( $order, 'get_shipping_last_name' ) ) {
		return sprintf( '%s %s', $order->get_shipping_first_name(), $order->get_shipping_last_name() );
	}
	else {
		return sprintf( '%s %s', $order->shipping_first_name, $order->shipping_last_name );
	}
}

/**
 * Get order billing phone number based on WC version
 *
 * @since  1.3.1
 * @param  WC_Order $order Order
 * @return integer         Order ID
 */
function wc_esm_get_order_billing_phone( $order ) {
	if( method_exists( $order, 'get_billing_phone' ) ) {
		return $order->get_billing_phone();
	}
	else {
		return $order->billing_phone;
	}
}

/**
 * Get order billing email address based on WC version
 *
 * @since  1.3.1
 * @param  WC_Order $order Order
 * @return integer         Order ID
 */
function wc_esm_get_order_billing_email( $order ) {
	if( method_exists( $order, 'get_billing_email' ) ) {
		return $order->get_billing_email();
	}
	else {
		return $order->billing_email;
	}
}

/**
 * Get order shipping country based on WC version
 *
 * @since  1.3.1
 * @param  WC_Order $order Order
 * @return integer         Order ID
 */
function wc_esm_get_order_shipping_country( $order ) {
	if( method_exists( $order, 'get_shipping_country' ) ) {
		return $order->get_shipping_country();
	}
	else {
		return $order->shipping_country;
	}
}

/**
 * Get HTML element class name from theme.
 *
 * @since 1.6
 *
 * @param string $element HTML element name.
 *
 * @return string HTML class name.
 */
function wc_esm_get_element_class_name( $element ) {
	$class_name = '';

	if ( function_exists( 'wc_wp_theme_get_element_class_name' ) ) {
		$class_name = wc_wp_theme_get_element_class_name( $element );
	}

	return apply_filters( 'wc_estonian_shipping_methods_element_class_name', $class_name, $element );
}

/**
 * Should the terminal list be made searchable?
 *
 * A parcel terminal list runs to hundreds of entries, so it is on by default.
 * It is also the one thing this plugin adds to a checkout that a theme can
 * reasonably want to do differently - dress the select with Choices.js, or with
 * whatever the theme's own forms use - and two enhancers on one element leave
 * a mess. Turning this off gives a theme a plain grouped <select> to work with
 * on both checkouts, which is what every such library wants to be handed.
 *
 *     add_filter( 'wc_estonian_shipping_methods_terminal_search', '__return_false' );
 *
 * @since 1.13.0
 *
 * @return boolean
 */
function wc_esm_terminal_search_enabled() {
	/**
	 * Whether to enhance the terminal select with a search.
	 *
	 * @since 1.13.0
	 *
	 * @param boolean $enabled Default true.
	 */
	return (bool) apply_filters( 'wc_estonian_shipping_methods_terminal_search', true );
}

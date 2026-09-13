<?php
/**
 * The phone a carrier texts the pickup code to.
 *
 * @package Estonian_Shipping_Methods_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What is wrong with the phone number given at checkout, if anything.
 *
 * Both checkouts ask the same two questions of it - is there one, when the
 * shipping method needs one, and does it carry a country prefix - and they
 * must answer them the same way. So the rules live here, free of either
 * checkout, and each checkout only decides how to say no.
 */
class WC_ESM_Checkout_Phone {

	const MISSING   = 'missing';
	const NO_PREFIX = 'no_prefix';

	/**
	 * The problem with a phone number, or an empty string when there is none.
	 *
	 * @param string $phone    The number given.
	 * @param bool   $required Whether the shipping method needs one.
	 *
	 * @return string One of the class constants, or an empty string.
	 */
	public static function problem( $phone, $required ) {
		$phone = str_replace( ' ', '', trim( (string) $phone ) );

		if ( '' === $phone ) {
			return $required ? self::MISSING : '';
		}

		// Carriers text internationally formatted numbers; the classic
		// checkout has always insisted on the prefix, and so does this.
		return '+' === substr( $phone, 0, 1 ) ? '' : self::NO_PREFIX;
	}

	/**
	 * What to tell the customer about a problem.
	 *
	 * @param string $problem One of the class constants.
	 *
	 * @return string
	 */
	public static function message( $problem ) {
		if ( self::MISSING === $problem ) {
			return __( 'Please enter a phone number: the pickup code for your parcel is sent to it.', 'wc-estonian-shipping-methods' );
		}

		if ( self::NO_PREFIX === $problem ) {
			return __( 'Please add country prefix to the phone number (eg. +372).', 'wc-estonian-shipping-methods' );
		}

		return '';
	}

	/**
	 * The first phone number given: a customer who filled one in only for
	 * delivery is still reachable.
	 *
	 * @param string $first  Usually the billing phone.
	 * @param string $second Usually the shipping phone.
	 *
	 * @return string
	 */
	public static function pick( $first, $second ) {
		return '' !== trim( (string) $first ) ? (string) $first : (string) $second;
	}
}

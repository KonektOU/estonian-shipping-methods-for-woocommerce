<?php
/**
 * What every payload builder shares.
 *
 * @package Estonian_Shipping_Methods_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A carrier's request body, built from a snapshot.
 *
 * Payload builders are static and take plain arrays - a snapshot and the
 * carrier's settings - so a request can be built and asserted on without
 * WooCommerce, an order, or a network anywhere near the test. Everything that
 * needs WordPress lives in the provider; everything that decides what the
 * carrier is told lives here.
 */
abstract class WC_ESM_Payload {

	/**
	 * One carrier setting, with a fallback.
	 *
	 * Settings arrive as stored, which means a key the shop never filled in
	 * is either missing or an empty string. Both mean "use the default".
	 *
	 * @param array  $settings Carrier settings.
	 * @param string $key      Setting name.
	 * @param mixed  $default  What to use when the shop set nothing.
	 *
	 * @return mixed
	 */
	public static function setting( $settings, $key, $default = '' ) {
		if ( ! isset( $settings[ $key ] ) || '' === $settings[ $key ] ) {
			return $default;
		}

		return $settings[ $key ];
	}
}

<?php
/**
 * One DPD token, not one per label.
 *
 * @package Estonian_Shipping_Methods_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Where a DPD bearer token is kept between requests.
 *
 * DPD hands out a token in exchange for the contract's credentials, and every
 * later call carries it. Logging in again for each call would turn a bulk
 * print of twenty labels into forty requests, so the token is remembered.
 *
 * It is remembered per host and username, because a shop that changes its
 * credentials - or a machine serving two shops - must not be handed the other
 * one's token. And it is remembered for less time than DPD will honour it, so
 * the common path never has to discover that it expired.
 */
class WC_ESM_Dpd_Token {

	/**
	 * How long a token is kept. Comfortably inside DPD's own expiry, so an
	 * expired token is the exception rather than the routine.
	 *
	 * @var int
	 */
	const TTL = 1800;

	/**
	 * Where one shop's token is kept.
	 *
	 * The credentials are hashed rather than stored: this is a cache key, and
	 * a key that spells out a username in the options table is a small gift
	 * to anyone reading it.
	 *
	 * @param string $host     API host.
	 * @param string $username Contract username.
	 *
	 * @return string
	 */
	public static function cache_key( $host, $username ) {
		return 'wc_esm_dpd_token_' . md5( $host . '|' . $username );
	}

	/**
	 * The token kept under a key, if there is one.
	 *
	 * @param string $key Cache key.
	 *
	 * @return string Empty when nothing is remembered.
	 */
	public static function stored( $key ) {
		$token = get_transient( $key );

		return is_string( $token ) ? $token : '';
	}

	/**
	 * Remember a token.
	 *
	 * @param string $key   Cache key.
	 * @param string $token Token.
	 *
	 * @return void
	 */
	public static function remember( $key, $token ) {
		set_transient( $key, (string) $token, self::TTL );
	}

	/**
	 * Throw a token away.
	 *
	 * Called when DPD answers 401: the token is dead, and keeping it would
	 * make every later call retry the same dead token.
	 *
	 * @param string $key Cache key.
	 *
	 * @return void
	 */
	public static function forget( $key ) {
		delete_transient( $key );
	}
}

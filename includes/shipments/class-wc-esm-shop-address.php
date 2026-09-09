<?php
/**
 * The shop's own address, in the shape a carrier's sender fields want.
 *
 * @package Estonian_Shipping_Methods_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Where the parcels are sent from.
 *
 * A shop has already told WooCommerce where it is, and every carrier that
 * registers a shipment asks for the same eight facts about the sender. This
 * turns the one into the other, so the settings screen arrives filled in
 * rather than empty.
 *
 * The values are defaults, not a live feed: what the screen shows is what gets
 * saved, and what is saved is what the carrier is told. Moving the shop means
 * correcting the carrier settings too.
 */
class WC_ESM_Shop_Address {

	/**
	 * Split a one-line street address into a street and a house number.
	 *
	 * WooCommerce keeps the address as one line; Omniva and DPD want the two
	 * apart. Estonian addresses put the number last ("Pikk 12", "J. Smuuli
	 * tee 43"), and house numbers are not plain integers - 12a and 24/2 are
	 * both addresses - so the last space-separated token that starts with a
	 * digit is taken whole.
	 *
	 * A line with no number in it is all street. Inventing a house number out
	 * of nothing would be worse than leaving the field for a human.
	 *
	 * @param string $line One-line street address.
	 *
	 * @return array street and house, both strings.
	 */
	public static function split_street( $line ) {
		$line = trim( preg_replace( '/\s+/u', ' ', (string) $line ) );

		if ( '' === $line ) {
			return array(
				'street' => '',
				'house'  => '',
			);
		}

		if ( preg_match( '/^(.*\S)[\s,]+(\d+\S*)$/u', $line, $matches ) ) {
			return array(
				'street' => $matches[1],
				'house'  => $matches[2],
			);
		}

		return array(
			'street' => $line,
			'house'  => '',
		);
	}

	/**
	 * The shop's address, keyed the way the carriers' sender fields are.
	 *
	 * @return array
	 */
	public static function sender_defaults() {
		$countries = function_exists( 'WC' ) && WC() ? WC()->countries : null;
		$street    = self::split_street( $countries ? $countries->get_base_address() : '' );

		return array(
			'sender_name'     => (string) get_bloginfo( 'name' ),
			// WooCommerce keeps no telephone number for the shop, so there is
			// nothing to fill this from.
			'sender_phone'    => '',
			'sender_email'    => self::shop_email(),
			'sender_street'   => $street['street'],
			'sender_house'    => $street['house'],
			'sender_postcode' => $countries ? (string) $countries->get_base_postcode() : '',
			'sender_city'     => $countries ? (string) $countries->get_base_city() : '',
			'sender_country'  => $countries ? (string) $countries->get_base_country() : '',
		);
	}

	/**
	 * The address the shop sends its e-mail from, or failing that the admin's.
	 *
	 * @return string
	 */
	protected static function shop_email() {
		$from = (string) get_option( 'woocommerce_email_from_address', '' );

		return '' !== $from ? $from : (string) get_option( 'admin_email', '' );
	}

	/**
	 * The sender block a carrier splices into its own settings fields.
	 *
	 * Omniva and DPD ask for exactly the same eight things, so they ask for
	 * them from here rather than each keeping its own copy.
	 *
	 * @return array Field definitions keyed by setting name.
	 */
	public static function fields() {
		$defaults = self::sender_defaults();

		$fields = array(
			'sender_name'     => array(
				'title'       => __( 'Sender name', 'wc-estonian-shipping-methods' ),
				'description' => __( 'Filled in from the shop address under WooCommerce -> Settings -> General. Correct anything the carrier should see differently.', 'wc-estonian-shipping-methods' ),
			),
			'sender_phone'    => array(
				'title'       => __( 'Sender phone', 'wc-estonian-shipping-methods' ),
				'description' => __( 'WooCommerce keeps no telephone number for the shop, so this one is yours to fill in. The carrier calls it when a pickup goes wrong.', 'wc-estonian-shipping-methods' ),
				'desc_tip'    => true,
			),
			'sender_email'    => array( 'title' => __( 'Sender e-mail', 'wc-estonian-shipping-methods' ) ),
			'sender_street'   => array( 'title' => __( 'Sender street', 'wc-estonian-shipping-methods' ) ),
			'sender_house'    => array( 'title' => __( 'Sender house number', 'wc-estonian-shipping-methods' ) ),
			'sender_postcode' => array( 'title' => __( 'Sender postcode', 'wc-estonian-shipping-methods' ) ),
			'sender_city'     => array( 'title' => __( 'Sender city', 'wc-estonian-shipping-methods' ) ),
			'sender_country'  => array( 'title' => __( 'Sender country', 'wc-estonian-shipping-methods' ) ),
		);

		foreach ( $fields as $key => $field ) {
			$fields[ $key ] = array_merge(
				array(
					'type'    => 'text',
					'group'   => 'sender',
					'default' => $defaults[ $key ],
				),
				$field
			);
		}

		return $fields;
	}
}

<?php
/**
 * One carrier-independent view of an order.
 *
 * @package Estonian_Shipping_Methods_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The facts about an order that a carrier needs, and nothing else.
 *
 * Four carriers want four different payloads out of the same order. If each
 * payload builder read the order itself, each would re-derive the recipient,
 * re-guess the weight and re-decide what a missing order number means, and
 * they would drift apart. So the order is read once, into this shape, and the
 * payload builders take an array.
 *
 * That is also what makes them testable: a snapshot is plain data, so a
 * payload can be built and asserted on without WooCommerce anywhere in sight.
 *
 * The shape is fixed. make() drops anything not in it, so a builder reading
 * $snapshot['address']['postcode'] can rely on the key existing.
 */
class WC_ESM_Order_Snapshot {

	/**
	 * The empty shape, with every key a payload builder may read.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'order_id'     => 0,
			'order_number' => '',
			'method_id'    => '',
			'terminal_id'  => '',
			'terminal'     => array(
				'place_id'    => '',
				'name'        => '',
				'city'        => '',
				'address'     => '',
				'country'     => '',
				'postalcode'  => '',
				'routingcode' => '',
			),
			'recipient'    => array(
				'name'  => '',
				'phone' => '',
				'email' => '',
			),
			'address'      => array(
				'street'    => '',
				'house'     => '',
				'apartment' => '',
				'city'      => '',
				'postcode'  => '',
				'country'   => '',
			),
			'weight'       => 0.0,
			'cod_amount'   => 0.0,
			'currency'     => '',
			'content'      => '',
			'timewindow'   => '',
			'base_country' => '',
			'created_at'   => '',
			'shop_name'    => '',
		);
	}

	/**
	 * A snapshot from whatever facts the caller has.
	 *
	 * Missing keys come from the defaults, unknown keys are dropped, and the
	 * numbers are made numbers: a carrier handed the string "1.25" as a
	 * weight rejects the parcel.
	 *
	 * @param array $order_data Order facts, in the shape defaults() describes.
	 *
	 * @return array
	 */
	public static function make( $order_data ) {
		$defaults = self::defaults();
		$snapshot = array_replace_recursive( $defaults, array_intersect_key( (array) $order_data, $defaults ) );

		$snapshot['order_id']   = (int) $snapshot['order_id'];
		$snapshot['weight']     = (float) $snapshot['weight'];
		$snapshot['cod_amount'] = (float) $snapshot['cod_amount'];

		// A shop with no order-number plugin has no separate number; the id
		// stands in for it rather than the carrier printing an empty label.
		if ( '' === (string) $snapshot['order_number'] ) {
			$snapshot['order_number'] = (string) $snapshot['order_id'];
		}

		return $snapshot;
	}

	/**
	 * Whether the parcel is going to a terminal rather than to a door.
	 *
	 * @param array $snapshot Snapshot.
	 *
	 * @return bool
	 */
	public static function goes_to_terminal( $snapshot ) {
		return '' !== (string) ( isset( $snapshot['terminal_id'] ) ? $snapshot['terminal_id'] : '' );
	}

	/**
	 * Whether the courier has money to collect.
	 *
	 * @param array $snapshot Snapshot.
	 *
	 * @return bool
	 */
	public static function has_cod( $snapshot ) {
		return (float) ( isset( $snapshot['cod_amount'] ) ? $snapshot['cod_amount'] : 0 ) > 0;
	}
}

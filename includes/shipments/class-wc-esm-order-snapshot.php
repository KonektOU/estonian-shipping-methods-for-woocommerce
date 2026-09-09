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
	 * Read an order into the shape.
	 *
	 * The one place that touches WooCommerce, so everything downstream -
	 * every payload builder, every test - deals in plain arrays. It is
	 * deliberately not unit-tested: there is nothing here but reading an
	 * order, and a test of it would only assert that WooCommerce's getters
	 * were called.
	 *
	 * @param WC_Order $order Order.
	 *
	 * @return array
	 */
	public static function from_order( $order ) {
		$method      = null;
		$method_id   = '';
		$terminal_id = '';

		foreach ( $order->get_shipping_methods() as $item ) {
			$method_id = $item->get_method_id();
			$method    = WC_Shipping_Zones::get_shipping_method( $item->get_instance_id() );

			break;
		}

		if ( $method && method_exists( $method, 'get_order_terminal' ) ) {
			$terminal_id = (string) $method->get_order_terminal( $order->get_id() );
		}

		$terminal = array();

		if ( '' !== $terminal_id && $method && method_exists( $method, 'get_terminal_data' ) ) {
			$terminal = (array) $method->get_terminal_data( $terminal_id );
		}

		$created = $order->get_date_created();

		return self::make(
			array(
				'order_id'     => $order->get_id(),
				'order_number' => $order->get_order_number(),
				'method_id'    => $method_id,
				'terminal_id'  => $terminal_id,
				'terminal'     => $terminal,
				'recipient'    => array(
					'name'  => trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() ) ?: trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
					'phone' => $order->get_billing_phone(),
					'email' => $order->get_billing_email(),
				),
				'address'      => array(
					'street'    => $order->get_shipping_address_1(),
					'house'     => $order->get_shipping_address_2(),
					'city'      => $order->get_shipping_city(),
					'postcode'  => $order->get_shipping_postcode(),
					'country'   => $order->get_shipping_country(),
				),
				'weight'       => self::weight_of( $order ),
				'cod_amount'   => self::cod_of( $order ),
				'currency'     => $order->get_currency(),
				'content'      => sprintf(
					/* translators: 1: shop name, 2: order number. */
					__( '%1$s - Order #%2$s', 'wc-estonian-shipping-methods' ),
					get_bloginfo( 'name' ),
					$order->get_order_number()
				),
				'timewindow'   => $terminal_id,
				'base_country' => WC()->countries->get_base_country(),
				'created_at'   => $created ? $created->format( DateTime::RFC3339 ) : '',
				'shop_name'    => get_bloginfo( 'name' ),
			)
		);
	}

	/**
	 * What the order weighs, in the shop's own unit converted to kilograms.
	 *
	 * A shop that fills in no product weights would send a zero, which every
	 * carrier rejects, so an order with no weight at all is called half a
	 * kilo - the same floor the plugin used before.
	 *
	 * @param WC_Order $order Order.
	 *
	 * @return float
	 */
	protected static function weight_of( $order ) {
		$weight = 0.0;

		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();

			if ( $product && $product->get_weight() ) {
				$weight += (float) wc_get_weight( $product->get_weight(), 'kg' ) * (int) $item->get_quantity();
			}
		}

		return $weight > 0 ? round( $weight, 3 ) : 0.5;
	}

	/**
	 * What the courier has to collect, if anything.
	 *
	 * @param WC_Order $order Order.
	 *
	 * @return float
	 */
	protected static function cod_of( $order ) {
		return apply_filters( 'wc_esm_order_cod_amount', 0.0, $order );
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

<?php
/**
 * What Smartposti wants for a parcel.
 *
 * @package Estonian_Shipping_Methods_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Smartposti's order request.
 *
 * The interesting part is the destination, which Smartposti accepts in three
 * different shapes and rejects if the wrong one is sent:
 *
 * - an Estonian, Latvian or Lithuanian parcel machine is named by its place
 *   id and country;
 * - a Finnish one is named by postcode and routing code instead, because
 *   Posti routes on those and ignores the place id;
 * - a courier delivery carries the customer's own address and the time
 *   window they picked, and no place id at all.
 *
 * Everything else about a parcel is the same in all three.
 */
class WC_ESM_Payload_Smartpost extends WC_ESM_Payload {

	/**
	 * The shipping method that means a courier rather than a parcel machine.
	 *
	 * @var string
	 */
	const COURIER_METHOD = 'smartpost_courier';

	/**
	 * The place id that means "to an address, not to a machine".
	 *
	 * @var int
	 */
	const COURIER_PLACE_ID = 1;

	/**
	 * The request body for one order.
	 *
	 * @param array $snapshot Order snapshot.
	 * @param array $settings Carrier settings.
	 *
	 * @return array
	 */
	public static function build( $snapshot, $settings ) {
		$item = array(
			'reference'   => $snapshot['order_number'],
			'content'     => self::content( $snapshot ),
			'weight'      => (float) $snapshot['weight'],
			'destination' => self::destination( $snapshot ),
			'source'      => array(
				'country' => $snapshot['base_country'],
			),
			'recipient'   => self::recipient( $snapshot ),
		);

		// Smartposti wants a size and a sender together or not at all: a size
		// without a sender is rejected, and a sender without a size is
		// ignored. The shop opts into both by choosing a package size.
		$size = self::setting( $settings, 'package_size' );

		if ( '' !== $size ) {
			$item['size']   = $size;
			$item['sender'] = array(
				'name'  => self::setting( $settings, 'sender_name', $snapshot['shop_name'] ),
				'phone' => self::setting( $settings, 'sender_phone' ),
				'email' => self::setting( $settings, 'sender_email' ),
			);
		}

		return array(
			'orders' => array(
				'item' => array( $item ),
			),
		);
	}

	/**
	 * Who the parcel is for, and what they owe.
	 *
	 * Smartposti calls the cash-on-delivery amount "goods": it is the money
	 * it collects at the door or the machine and transfers back to the shop.
	 * An order with nothing to collect says nothing about money at all.
	 *
	 * @param array $snapshot Order snapshot.
	 *
	 * @return array
	 */
	protected static function recipient( $snapshot ) {
		$recipient = array(
			'name'  => $snapshot['recipient']['name'],
			'phone' => $snapshot['recipient']['phone'],
			'email' => $snapshot['recipient']['email'],
		);

		if ( WC_ESM_Order_Snapshot::has_cod( $snapshot ) ) {
			$recipient['goods'] = (float) $snapshot['cod_amount'];
		}

		return $recipient;
	}

	/**
	 * Which of the three destination shapes this parcel needs.
	 *
	 * @param array $snapshot Order snapshot.
	 *
	 * @return array
	 */
	protected static function destination( $snapshot ) {
		if ( self::COURIER_METHOD === $snapshot['method_id'] ) {
			return array(
				// A courier delivery is place_id 1: that is how Smartposti is
				// told this one goes to an address rather than to a machine.
				'place_id'   => self::COURIER_PLACE_ID,
				'timewindow' => $snapshot['timewindow'],
				'street'     => $snapshot['address']['street'],
				'house'      => $snapshot['address']['house'],
				'apartment'  => $snapshot['address']['apartment'],
				'city'       => $snapshot['address']['city'],
				'country'    => $snapshot['address']['country'],
				'postalcode' => $snapshot['address']['postcode'],
			);
		}

		if ( 'FI' === $snapshot['terminal']['country'] ) {
			return array(
				'postalcode'  => $snapshot['terminal']['postalcode'],
				'routingcode' => $snapshot['terminal']['routingcode'],
			);
		}

		return array(
			'place_id' => $snapshot['terminal']['place_id'],
			'country'  => $snapshot['terminal']['country'],
		);
	}

	/**
	 * What the parcel says it holds.
	 *
	 * Named after the shop and the order rather than the goods: a courier's
	 * screen and a customs form both show this, and neither is a place to
	 * list what somebody bought.
	 *
	 * @param array $snapshot Order snapshot.
	 *
	 * @return string
	 */
	protected static function content( $snapshot ) {
		return sprintf(
			/* translators: 1: shop name, 2: order number. */
			__( '%1$s - Order #%2$s', 'wc-estonian-shipping-methods' ),
			$snapshot['shop_name'],
			$snapshot['order_number']
		);
	}
}

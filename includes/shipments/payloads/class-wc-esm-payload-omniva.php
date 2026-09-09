<?php
/**
 * What Omniva's OMX wants for a business-to-client parcel.
 *
 * @package Estonian_Shipping_Methods_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Omniva's business-to-client shipment request.
 *
 * OMX takes an address one of two ways and rejects the wrong one: a parcel
 * machine is named by its offload postcode and country alone, with no street
 * and no postcode of its own, while a courier delivery carries the customer's
 * street, house number, postcode and city and no offload postcode at all.
 *
 * Everything else - who the parcel is for, who it is from, what it weighs -
 * is the same either way.
 */
class WC_ESM_Payload_Omniva extends WC_ESM_Payload {

	/**
	 * The request body for one order.
	 *
	 * @param array $snapshot Order snapshot.
	 * @param array $settings Carrier settings.
	 *
	 * @return array
	 */
	public static function build( $snapshot, $settings ) {
		$to_terminal = WC_ESM_Order_Snapshot::goes_to_terminal( $snapshot );

		$shipment = array(
			'mainService'       => 'PARCEL',
			'deliveryChannel'   => $to_terminal ? 'PARCEL_MACHINE' : 'COURIER',
			'partnerShipmentId' => $snapshot['order_number'],
			'returnAllowed'     => false,
			'receiverAddressee' => array(
				'personName'    => $snapshot['recipient']['name'],
				'contactMobile' => $snapshot['recipient']['phone'],
				'contactEmail'  => $snapshot['recipient']['email'],
				'address'       => self::receiver_address( $snapshot, $to_terminal ),
			),
			'senderAddressee'   => self::sender( $settings ),
			'measurement'       => array(
				// OMX takes the weight as a string, in kilograms.
				'weight' => (string) round( (float) $snapshot['weight'], 3 ),
			),
		);

		// A parcel crossing a border is held at customs without a description
		// of what is in it. One staying at home needs none, and sending one
		// only invites a question nobody asked.
		if ( self::destination_country( $snapshot, $to_terminal ) !== self::setting( $settings, 'sender_country', 'EE' ) ) {
			$shipment['contentDescription'] = $snapshot['content'];
		}

		// Omniva issues the additional service list per customer, so the code
		// for cash on delivery is the shop's own and not something to guess.
		if ( WC_ESM_Order_Snapshot::has_cod( $snapshot ) ) {
			$shipment['addServices'] = array(
				array( 'code' => self::setting( $settings, 'cod_service_code', 'BP' ) ),
			);
		}

		return array(
			'customerCode' => self::setting( $settings, 'customer_code' ),
			// One order is one batch, so the order number identifies it. A
			// resend of the same order is meant to be the same batch.
			'fileId'       => (string) $snapshot['order_number'],
			'shipments'    => array( $shipment ),
		);
	}

	/**
	 * Where the parcel is going, in one of OMX's two address shapes.
	 *
	 * @param array $snapshot    Order snapshot.
	 * @param bool  $to_terminal Whether it goes to a parcel machine.
	 *
	 * @return array
	 */
	protected static function receiver_address( $snapshot, $to_terminal ) {
		if ( $to_terminal ) {
			return array(
				'country'         => $snapshot['terminal']['country'],
				'offloadPostcode' => $snapshot['terminal']['place_id'],
			);
		}

		return array(
			'country'       => $snapshot['address']['country'],
			'deliverypoint' => $snapshot['address']['city'],
			'postcode'      => $snapshot['address']['postcode'],
			'street'        => $snapshot['address']['street'],
			'houseNo'       => $snapshot['address']['house'],
		);
	}

	/**
	 * Which country the parcel ends up in.
	 *
	 * @param array $snapshot    Order snapshot.
	 * @param bool  $to_terminal Whether it goes to a parcel machine.
	 *
	 * @return string
	 */
	protected static function destination_country( $snapshot, $to_terminal ) {
		return $to_terminal ? $snapshot['terminal']['country'] : $snapshot['address']['country'];
	}

	/**
	 * Who the parcel is from.
	 *
	 * @param array $settings Carrier settings.
	 *
	 * @return array
	 */
	protected static function sender( $settings ) {
		return array(
			'personName'    => self::setting( $settings, 'sender_name' ),
			'contactPhone'  => self::setting( $settings, 'sender_phone' ),
			'contactMobile' => self::setting( $settings, 'sender_phone' ),
			'contactEmail'  => self::setting( $settings, 'sender_email' ),
			'address'       => array(
				'street'        => self::setting( $settings, 'sender_street' ),
				'houseNo'       => self::setting( $settings, 'sender_house' ),
				'postcode'      => self::setting( $settings, 'sender_postcode' ),
				'deliverypoint' => self::setting( $settings, 'sender_city' ),
				'country'       => self::setting( $settings, 'sender_country', 'EE' ),
			),
		);
	}
}

<?php
/**
 * A DPD shipment is a pudo id and a weight.
 *
 * @package Estonian_Shipping_Methods_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * DPD's shipment request.
 *
 * A parcel shop delivery is named by the shop's pudo id and nothing else -
 * DPD needs no street for one, and sending an address alongside is how a
 * parcel ends up somewhere other than the shop the customer picked. A courier
 * delivery carries the address instead.
 *
 * The customer is named either way: somebody has to be asked for at the
 * counter.
 */
class WC_ESM_Payload_Dpd extends WC_ESM_Payload {

	/**
	 * The request body for one order.
	 *
	 * @param array $snapshot Order snapshot.
	 * @param array $settings Carrier settings.
	 *
	 * @return array
	 */
	public static function build( $snapshot, $settings ) {
		$body = array(
			'senderAddress'      => self::sender( $settings ),
			'receiverAddress'    => self::receiver( $snapshot ),
			'payerCode'          => self::setting( $settings, 'payer_code' ),
			'service'            => array(
				'serviceName' => self::setting( $settings, 'service_alias' ),
			),
			'parcels'            => array(
				array( 'weight' => (float) $snapshot['weight'] ),
			),
			'contentDescription' => $snapshot['content'],
			'shipmentReferences' => array( (string) $snapshot['order_number'] ),
		);

		// The alias is the shop's own: DPD issues the additional service list
		// per contract, and naming one a contract does not carry has DPD
		// refuse the whole shipment rather than just the service. So a shop
		// that set none sends none, even for an order with money on it.
		$alias = self::setting( $settings, 'cod_service_alias' );

		if ( '' !== $alias && WC_ESM_Order_Snapshot::has_cod( $snapshot ) ) {
			$body['additionalServices'] = array(
				array(
					'serviceName' => $alias,
					'fields'      => array(
						'amount'    => (float) $snapshot['cod_amount'],
						'currency'  => $snapshot['currency'],
						'reference' => (string) $snapshot['order_number'],
					),
				),
			);
		}

		return $body;
	}

	/**
	 * Where the parcel is going.
	 *
	 * @param array $snapshot Order snapshot.
	 *
	 * @return array
	 */
	protected static function receiver( $snapshot ) {
		$receiver = array(
			'name'  => $snapshot['recipient']['name'],
			'phone' => $snapshot['recipient']['phone'],
			'email' => $snapshot['recipient']['email'],
		);

		if ( WC_ESM_Order_Snapshot::goes_to_terminal( $snapshot ) ) {
			$receiver['pudoId']  = $snapshot['terminal_id'];
			$receiver['country'] = $snapshot['terminal']['country'];

			return $receiver;
		}

		return array_merge(
			$receiver,
			array(
				'street'     => $snapshot['address']['street'],
				'streetNo'   => $snapshot['address']['house'],
				'flatNo'     => $snapshot['address']['apartment'],
				'city'       => $snapshot['address']['city'],
				'postalCode' => $snapshot['address']['postcode'],
				'country'    => $snapshot['address']['country'],
			)
		);
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
			'name'       => self::setting( $settings, 'sender_name' ),
			'phone'      => self::setting( $settings, 'sender_phone' ),
			'email'      => self::setting( $settings, 'sender_email' ),
			'street'     => self::setting( $settings, 'sender_street' ),
			'streetNo'   => self::setting( $settings, 'sender_house' ),
			'city'       => self::setting( $settings, 'sender_city' ),
			'postalCode' => self::setting( $settings, 'sender_postcode' ),
			'country'    => self::setting( $settings, 'sender_country', 'EE' ),
		);
	}
}

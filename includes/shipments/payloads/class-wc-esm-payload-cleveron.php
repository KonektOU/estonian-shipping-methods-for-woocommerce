<?php
/**
 * What Cleveron wants for an office order.
 *
 * @package Estonian_Shipping_Methods_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cleveron Office's order request.
 *
 * Cleveron is unlike the other three: the customer picks no terminal, because
 * the shop's own parcel robot is the destination and the shipping zone says
 * which one. A shop with two offices runs two robots, so the robot, the slot
 * size and the message templates are all zone settings rather than carrier
 * settings, and reach here merged in alongside them.
 *
 * There is no barcode and no label: the order number is what Cleveron files
 * the order under, and the customer is let in by a code Cleveron sends them.
 */
class WC_ESM_Payload_Cleveron extends WC_ESM_Payload {

	/**
	 * The request body for one order.
	 *
	 * @param array $snapshot Order snapshot.
	 * @param array $settings Carrier settings, with the zone's own merged in.
	 *
	 * @return array
	 */
	public static function build( $snapshot, $settings ) {
		$templates = array();

		foreach ( array( 'sms_template', 'email_template' ) as $key ) {
			$template = self::setting( $settings, $key );

			if ( '' !== $template ) {
				$templates[] = $template;
			}
		}

		return array(
			'service'          => 'C2C',
			'barcode'          => (string) $snapshot['order_number'],
			'destination'      => array(
				'apm' => $snapshot['terminal_id'],
			),
			'slotSize'         => self::setting( $settings, 'slot_size', 'XS' ),
			'phone'            => $snapshot['recipient']['phone'],
			'email'            => $snapshot['recipient']['email'],
			'changesTimestamp' => $snapshot['created_at'],
			'templates'        => $templates,
			'extras'           => array(
				'description' => sprintf( '%s #%s', $snapshot['shop_name'], $snapshot['order_number'] ),
			),
		);
	}
}

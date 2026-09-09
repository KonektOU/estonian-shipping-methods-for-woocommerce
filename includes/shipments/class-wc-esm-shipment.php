<?php
/**
 * One set of meta keys for every carrier.
 *
 * @package Estonian_Shipping_Methods_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What a carrier left on an order.
 *
 * Four carriers answer four different ways - Smartposti gives barcodes, DPD
 * gives parcel numbers, Cleveron gives neither and only an order reference -
 * but a shop reads one thing: has this order been sent, and what can be
 * printed or tracked. So all four write the same two keys.
 *
 * The keys are written into live shops, so they do not change. Everything
 * reading them tolerates what an older version stored: a bare string where a
 * list is expected reads as a one-item list.
 */
class WC_ESM_Shipment {

	const BARCODES   = '_wc_esm_barcodes';
	const LABEL_REFS = '_wc_esm_label_refs';
	const ERROR      = '_wc_esm_error';

	/**
	 * The parcel barcodes on an order.
	 *
	 * @param WC_Order $order Order.
	 *
	 * @return array
	 */
	public static function barcodes( $order ) {
		return self::list_meta( $order, self::BARCODES );
	}

	/**
	 * The references a label can be fetched with.
	 *
	 * @param WC_Order $order Order.
	 *
	 * @return array
	 */
	public static function label_refs( $order ) {
		return self::list_meta( $order, self::LABEL_REFS );
	}

	/**
	 * Whether this order has been sent to its carrier.
	 *
	 * Either key is enough: Cleveron returns no barcode, and a carrier that
	 * returns no separate label reference still shipped the parcel.
	 *
	 * @param WC_Order $order Order.
	 *
	 * @return bool
	 */
	public static function is_registered( $order ) {
		return (bool) self::barcodes( $order ) || (bool) self::label_refs( $order );
	}

	/**
	 * The last failure, if the carrier refused.
	 *
	 * @param WC_Order $order Order.
	 *
	 * @return string
	 */
	public static function error( $order ) {
		return (string) $order->get_meta( self::ERROR );
	}

	/**
	 * Write what a carrier answered onto the order.
	 *
	 * A success clears any failure an earlier attempt left, so the order
	 * screen stops offering to send it again.
	 *
	 * @param WC_Order               $order  Order.
	 * @param WC_ESM_Shipment_Result $result What the carrier answered.
	 *
	 * @return void
	 */
	public static function record( $order, $result ) {
		if ( $result->is_failure() ) {
			self::record_error( $order, $result->get_message() );

			return;
		}

		$order->update_meta_data( self::BARCODES, self::as_list( $result->get( 'barcodes', array() ) ) );
		$order->update_meta_data( self::LABEL_REFS, self::as_list( $result->get( 'label_refs', array() ) ) );
		$order->delete_meta_data( self::ERROR );
		$order->save();
	}

	/**
	 * Write a failure where the order screen can show it.
	 *
	 * @param WC_Order $order   Order.
	 * @param string   $message What went wrong.
	 *
	 * @return void
	 */
	public static function record_error( $order, $message ) {
		$order->update_meta_data( self::ERROR, (string) $message );
		$order->save();
	}

	/**
	 * Forget a failure.
	 *
	 * @param WC_Order $order Order.
	 *
	 * @return void
	 */
	public static function clear_error( $order ) {
		$order->delete_meta_data( self::ERROR );
		$order->save();
	}

	/**
	 * One meta key as a list of non-empty strings.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $key   Meta key.
	 *
	 * @return array
	 */
	protected static function list_meta( $order, $key ) {
		return self::as_list( $order->get_meta( $key ) );
	}

	/**
	 * Whatever was stored, as a list of non-empty strings.
	 *
	 * A blank is not a barcode: a carrier that answered with an empty string
	 * must not leave an order looking registered.
	 *
	 * @param mixed $value Stored value.
	 *
	 * @return array
	 */
	protected static function as_list( $value ) {
		if ( '' === $value || null === $value ) {
			return array();
		}

		return array_values(
			array_filter(
				array_map( 'strval', (array) $value ),
				static function ( $entry ) {
					return '' !== $entry;
				}
			)
		);
	}
}

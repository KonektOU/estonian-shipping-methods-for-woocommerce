<?php
/**
 * Print a whole screen of orders at once.
 *
 * @package Estonian_Shipping_Methods_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gathers labels for a batch of orders and hands back one document.
 *
 * A shopkeeper ticks a screen of orders and presses print. Those orders may
 * well be spread across carriers, so they are grouped and each carrier asked
 * once for all of its labels - a carrier asked once for twenty labels is one
 * request, and asked per order it is twenty.
 *
 * One carrier failing does not cancel the print. The labels that came back
 * are printed and what went wrong is reported alongside, because a shop with
 * forty parcels to send would rather post thirty-nine of them today.
 */
class WC_ESM_Shipment_Labels {

	/**
	 * Group orders by the carrier that carries them.
	 *
	 * @param array                    $orders   Orders.
	 * @param WC_ESM_Shipment_Registry $registry Registry.
	 *
	 * @return array Provider id => provider, refs, orders.
	 */
	public static function group( $orders, $registry ) {
		$groups = array();

		foreach ( $orders as $order ) {
			$provider = self::provider_for( $order, $registry );

			if ( ! $provider ) {
				// Not a carrier this plugin handles; somebody else's order.
				continue;
			}

			$id = $provider->get_id();

			if ( ! isset( $groups[ $id ] ) ) {
				$groups[ $id ] = array(
					'provider' => $provider,
					'refs'     => array(),
					'orders'   => array(),
				);
			}

			$groups[ $id ]['orders'][] = $order;
			$groups[ $id ]['refs']     = array_merge( $groups[ $id ]['refs'], WC_ESM_Shipment::label_refs( $order ) );
		}

		return $groups;
	}

	/**
	 * Fetch every label for a batch and merge them.
	 *
	 * @param array                    $orders   Orders.
	 * @param WC_ESM_Shipment_Registry $registry Registry.
	 *
	 * @return array pdf, printed, problems.
	 */
	public static function collect( $orders, $registry ) {
		$pdfs     = array();
		$printed  = 0;
		$problems = array();

		foreach ( self::group( $orders, $registry ) as $group ) {
			$provider = $group['provider'];

			if ( ! $provider->supports( 'labels' ) ) {
				// Said once for the whole group: a shop printing ten Cleveron
				// orders does not need the same sentence ten times.
				$problems[] = sprintf(
					/* translators: %s: carrier name. */
					__( '%s does not print labels.', 'wc-estonian-shipping-methods' ),
					$provider->get_title()
				);

				continue;
			}

			$unsent = self::unsent( $group['orders'] );

			if ( $unsent ) {
				$problems[] = sprintf(
					/* translators: 1: carrier name, 2: order numbers. */
					__( '%1$s: orders %2$s have not been sent to the carrier yet, so they have no label.', 'wc-estonian-shipping-methods' ),
					$provider->get_title(),
					implode( ', ', $unsent )
				);
			}

			if ( ! $group['refs'] ) {
				continue;
			}

			$result = $provider->fetch_labels( $group['refs'] );

			if ( $result->is_failure() ) {
				$problems[] = $result->get_message();

				continue;
			}

			foreach ( self::documents( $result ) as $pdf ) {
				$pdfs[] = $pdf;
			}

			$printed += count( $group['refs'] );
		}

		return array(
			'pdf'      => WC_ESM_Pdf_Merger::merge( $pdfs ),
			'printed'  => $printed,
			'problems' => $problems,
		);
	}

	/**
	 * The documents in a carrier's answer.
	 *
	 * Some carriers hand back one PDF holding every label; others hand back
	 * one per parcel. Both are normal, so both are accepted.
	 *
	 * @param WC_ESM_Shipment_Result $result Carrier's answer.
	 *
	 * @return array
	 */
	protected static function documents( $result ) {
		$many = $result->get( 'pdfs', array() );

		if ( $many ) {
			return (array) $many;
		}

		$one = $result->get( 'pdf', '' );

		return '' !== $one ? array( $one ) : array();
	}

	/**
	 * The order numbers in a group that were never sent to the carrier.
	 *
	 * @param array $orders Orders.
	 *
	 * @return array
	 */
	protected static function unsent( $orders ) {
		$unsent = array();

		foreach ( $orders as $order ) {
			if ( ! WC_ESM_Shipment::label_refs( $order ) ) {
				$unsent[] = $order->get_order_number();
			}
		}

		return $unsent;
	}

	/**
	 * The carrier that carries an order.
	 *
	 * @param WC_Order                 $order    Order.
	 * @param WC_ESM_Shipment_Registry $registry Registry.
	 *
	 * @return WC_ESM_Shipment_Provider|null
	 */
	protected static function provider_for( $order, $registry ) {
		foreach ( $order->get_shipping_methods() as $item ) {
			$provider = $registry->provider_for_method( $item->get_method_id() );

			if ( $provider ) {
				return $provider;
			}
		}

		return null;
	}

	/**
	 * Send a document to the browser as a download.
	 *
	 * @param string $pdf      Document bytes.
	 * @param string $filename What to call it.
	 *
	 * @return void
	 */
	public static function stream( $pdf, $filename = 'labels.pdf' ) {
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
		header( 'Content-Length: ' . strlen( $pdf ) );

		echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- PDF bytes.

		exit;
	}
}

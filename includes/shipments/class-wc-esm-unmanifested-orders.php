<?php
/**
 * What is waiting to go on a manifest.
 *
 * @package Estonian_Shipping_Methods_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The shipments a carrier has taken but not yet been given a manifest for.
 *
 * The query cannot ask for a carrier - an order says which shipping method it
 * used, not which integration handles it - so it asks for everything with a
 * shipment and no manifest stamp, and the carrier is decided per order.
 *
 * It walks every page rather than taking the first, because a shop with more
 * outstanding than one page holds would otherwise be shown a smaller, wrong
 * number and close a manifest over part of its day. A ceiling stops a runaway
 * query from hanging the screen, and the answer says when it was hit, so the
 * number is never quietly wrong.
 */
class WC_ESM_Unmanifested_Orders {

	/**
	 * The stamp put on an order once a manifest covers it.
	 *
	 * @var string
	 */
	const MANIFESTED_META = '_wc_esm_manifested';

	/**
	 * How many orders one query asks for.
	 *
	 * @var int
	 */
	const PAGE_SIZE = 200;

	/**
	 * How many orders one screen load will walk before giving up.
	 *
	 * @var int
	 */
	const MAX_ORDERS = 5000;

	/**
	 * What one carrier has outstanding.
	 *
	 * @param string                        $provider_id Provider id.
	 * @param WC_ESM_Shipment_Registry|null $registry    Registry; the shared one when omitted.
	 *
	 * @return array refs and truncated.
	 */
	public static function for_provider( $provider_id, $registry = null ) {
		$found = self::orders( $provider_id, $registry );
		$refs  = array();

		foreach ( $found['orders'] as $order ) {
			$refs = array_merge( $refs, WC_ESM_Shipment::label_refs( $order ) );
		}

		return array(
			// A manifest lists shipments, so the same reference on two orders
			// belongs on it once.
			'refs'      => array_values( array_unique( $refs ) ),
			'truncated' => $found['truncated'],
		);
	}

	/**
	 * Stamp the orders a manifest was closed over, so tomorrow's count does
	 * not include them again.
	 *
	 * @param string                        $provider_id Provider id.
	 * @param WC_ESM_Shipment_Registry|null $registry    Registry; the shared one when omitted.
	 *
	 * @return void
	 */
	public static function mark_manifested( $provider_id, $registry = null ) {
		foreach ( self::orders( $provider_id, $registry )['orders'] as $order ) {
			$order->update_meta_data( self::MANIFESTED_META, gmdate( 'c' ) );
			$order->save();
		}
	}

	/**
	 * The orders themselves.
	 *
	 * @param string                        $provider_id Provider id.
	 * @param WC_ESM_Shipment_Registry|null $registry    Registry; the shared one when omitted.
	 *
	 * @return array orders and truncated.
	 */
	protected static function orders( $provider_id, $registry = null ) {
		$registry = $registry ? $registry : WC_ESM_Shipment_Registry::instance();
		$provider = $registry->get_provider( $provider_id );

		if ( ! $provider ) {
			// A shop can switch a carrier off with orders still open.
			return array(
				'orders'    => array(),
				'truncated' => false,
			);
		}

		$found     = array();
		$truncated = false;
		$page      = 1;

		do {
			$orders = wc_get_orders( self::query( $page ) );

			foreach ( $orders as $order ) {
				if ( $registry->provider_for_order( $order ) === $provider ) {
					$found[] = $order;
				}
			}

			if ( count( $found ) >= self::MAX_ORDERS ) {
				$truncated = true;

				break;
			}

			$page++;
		} while ( count( $orders ) === self::PAGE_SIZE );

		return array(
			'orders'    => $found,
			'truncated' => $truncated,
		);
	}

	/**
	 * One page of the query.
	 *
	 * @param int $page Page number.
	 *
	 * @return array
	 */
	protected static function query( $page ) {
		return array(
			'limit'      => self::PAGE_SIZE,
			'page'       => $page,
			'status'     => array( 'processing', 'completed', 'on-hold' ),
			'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'     => WC_ESM_Shipment::LABEL_REFS,
					'compare' => 'EXISTS',
				),
				array(
					'key'     => self::MANIFESTED_META,
					'compare' => 'NOT EXISTS',
				),
			),
		);
	}
}

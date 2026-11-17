<?php
/**
 * Ask the carriers where the parcels are, sparingly.
 *
 * @package Estonian_Shipping_Methods_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * An hourly job that brings delivery states up to date.
 *
 * Each run loads only orders whose next check has come round - sent, not
 * yet at the end of their journey, placed within WC_ESM_Delivery::FOLLOW_FOR.
 * Every
 * carrier is asked about its due parcels in one go, in as few requests as
 * its API allows. An order seen for the first time is not asked about at
 * all: it was sent moments ago and has not moved yet, so its first check is
 * simply put in the diary.
 */
class WC_ESM_Delivery_Poller {

	const HOOK = 'wc_esm_poll_deliveries';

	/**
	 * Orders asked about per run. Only due ones are loaded, so this is a cap
	 * on a backlog, not on how many parcels a shop can follow; whatever does
	 * not fit is due again next hour.
	 */
	const ORDERS_PER_RUN = 500;

	/**
	 * Hook up.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( 'admin_init', array( __CLASS__, 'schedule' ) );
	}

	/**
	 * Put the hourly run in Action Scheduler, once.
	 *
	 * @return void
	 */
	public static function schedule() {
		if ( ! function_exists( 'as_has_scheduled_action' ) || as_has_scheduled_action( self::HOOK, array(), WC_ESM_Shipment_Registration::GROUP ) ) {
			return;
		}

		as_schedule_recurring_action( time() + 5 * MINUTE_IN_SECONDS, HOUR_IN_SECONDS, self::HOOK, array(), WC_ESM_Shipment_Registration::GROUP );
	}

	/**
	 * Take the hourly run out, when the plugin goes.
	 *
	 * @return void
	 */
	public static function unschedule() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, array(), WC_ESM_Shipment_Registration::GROUP );
		}
	}

	/**
	 * One run.
	 *
	 * @param int|null                      $now      Now.
	 * @param WC_ESM_Shipment_Registry|null $registry Registry.
	 *
	 * @return void
	 */
	public static function run( $now = null, $registry = null ) {
		$now      = null === $now ? time() : (int) $now;
		$registry = $registry ? $registry : WC_ESM_Shipment_Registry::instance();

		self::poll( self::due_orders( $now ), $registry, $now );
	}

	/**
	 * Bring these orders up to date.
	 *
	 * @param array                    $orders   Orders whose check is due.
	 * @param WC_ESM_Shipment_Registry $registry Registry.
	 * @param int                      $now      Now.
	 *
	 * @return int How many carriers were asked.
	 */
	public static function poll( $orders, $registry, $now ) {
		$asked = 0;

		foreach ( self::plan( $orders, $registry ) as $group ) {
			foreach ( $group['first_seen'] as $order ) {
				// Just sent: diary the first check, ask nothing.
				WC_ESM_Delivery::apply( $order, '', false, $now, self::age( $order, $now ) );
			}

			if ( ! $group['due'] ) {
				continue;
			}

			$asked++;
			$result = $group['provider']->track_parcels( array_keys( $group['by_barcode'] ) );
			$states = $result->is_success() ? (array) $result->get( 'states', array() ) : array();
			$unsure = $result->is_success() ? array_flip( (array) $result->get( 'tentative', array() ) ) : array();

			foreach ( $group['due'] as $order ) {
				$found     = array();
				$tentative = false;

				foreach ( WC_ESM_Shipment::barcodes( $order ) as $barcode ) {
					if ( isset( $states[ $barcode ] ) ) {
						$found[]   = $states[ $barcode ];
						$tentative = $tentative || isset( $unsure[ $barcode ] );
					}
				}

				// Written even when the carrier said nothing or refused: the
				// next check moves on either way, so a carrier that is down
				// is not asked every hour.
				WC_ESM_Delivery::apply( $order, WC_ESM_Delivery::combine( $found ), $tentative, $now, self::age( $order, $now ) );
			}
		}

		return $asked;
	}

	/**
	 * Group due orders by carrier.
	 *
	 * @param array                    $orders   Orders.
	 * @param WC_ESM_Shipment_Registry $registry Registry.
	 *
	 * @return array Per carrier: provider, due (orders to ask about),
	 *               by_barcode (barcode => true), first_seen (orders to diary).
	 */
	public static function plan( $orders, $registry ) {
		$groups = array();

		foreach ( $orders as $order ) {
			$provider = $registry->provider_for_order( $order );
			$barcodes = WC_ESM_Shipment::barcodes( $order );

			if ( ! $provider || ! $provider->supports( 'delivery' ) || ! $barcodes || WC_ESM_Delivery::is_finished( $order ) ) {
				continue;
			}

			$id = $provider->get_id();

			if ( ! isset( $groups[ $id ] ) ) {
				$groups[ $id ] = array(
					'provider'   => $provider,
					'due'        => array(),
					'by_barcode' => array(),
					'first_seen' => array(),
				);
			}

			if ( '' === (string) $order->get_meta( WC_ESM_Delivery::NEXT_CHECK ) && '' === WC_ESM_Delivery::state( $order ) ) {
				$groups[ $id ]['first_seen'][] = $order;
				continue;
			}

			$groups[ $id ]['due'][] = $order;

			foreach ( $barcodes as $barcode ) {
				$groups[ $id ]['by_barcode'][ $barcode ] = true;
			}
		}

		return $groups;
	}

	/**
	 * How old an order is.
	 *
	 * @param WC_Order $order Order.
	 * @param int      $now   Now.
	 *
	 * @return int Seconds.
	 */
	protected static function age( $order, $now ) {
		$created = method_exists( $order, 'get_date_created' ) ? $order->get_date_created() : null;

		return $created ? max( 0, $now - $created->getTimestamp() ) : 0;
	}

	/**
	 * Orders whose check has come round: sent, not finished, recent enough,
	 * and either never checked or due.
	 *
	 * @param int $now Now.
	 *
	 * @return array
	 */
	protected static function due_orders( $now ) {
		return wc_get_orders(
			// No type: naming shop_order alone lets a translation plugin
			// narrow the query to one language, and refunds carry no
			// barcode, so the meta query keeps them out anyway.
			array(
				'limit'        => self::ORDERS_PER_RUN,
				'orderby'      => 'date',
				'order'        => 'ASC',
				'date_created' => '>' . ( $now - WC_ESM_Delivery::FOLLOW_FOR ),
				'meta_query'   => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => WC_ESM_Shipment::BARCODES,
						'compare' => 'EXISTS',
					),
					array(
						'key'     => WC_ESM_Delivery::FINISHED,
						'compare' => 'NOT EXISTS',
					),
					array(
						'relation' => 'OR',
						array(
							'key'     => WC_ESM_Delivery::NEXT_CHECK,
							'compare' => 'NOT EXISTS',
						),
						array(
							'key'     => WC_ESM_Delivery::NEXT_CHECK,
							'value'   => $now,
							'compare' => '<=',
							'type'    => 'NUMERIC',
						),
					),
				),
			)
		);
	}
}

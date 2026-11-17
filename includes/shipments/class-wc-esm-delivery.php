<?php
/**
 * Where a parcel is after the carrier took it.
 *
 * @package Estonian_Shipping_Methods_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The delivery state of an order's parcels, and when to ask about it next.
 *
 * Every carrier describes a parcel's journey in its own words - Omniva in
 * event codes, DPD in a handful of sentences, Smartposti in timestamps - and
 * each provider translates its own into the five states here. What to do
 * with a state is decided once, in this class: how long until the carrier
 * is asked again, and when to stop asking for good.
 *
 * Asking is the expensive part, so it is rationed. A parcel standing in a
 * locker waiting for its owner is asked about less often than one moving
 * between terminals, an old parcel less often than a new one, and a parcel
 * that has reached the end of its journey - delivered, or back with the
 * sender - is never asked about again.
 */
class WC_ESM_Delivery {

	const IN_TRANSIT          = 'in_transit';
	const AWAITING_COLLECTION = 'awaiting_collection';
	const DELIVERED           = 'delivered';
	const RETURNING           = 'returning';
	const RETURNED            = 'returned';

	const STATE      = '_wc_esm_delivery';
	const SINCE      = '_wc_esm_delivery_since';
	const NEXT_CHECK = '_wc_esm_delivery_next';
	const FINISHED   = '_wc_esm_delivery_final';

	const HOUR = 3600;
	const DAY  = 86400;

	/**
	 * How long a parcel is followed at all. Past this, whatever it is doing,
	 * the shop has long since found out some other way.
	 */
	const FOLLOW_FOR = 30 * self::DAY;

	/**
	 * Every state, least far along first.
	 *
	 * @return array
	 */
	public static function states() {
		return array( self::IN_TRANSIT, self::AWAITING_COLLECTION, self::DELIVERED, self::RETURNING, self::RETURNED );
	}

	/**
	 * The order's delivery state, or '' when nothing is known yet.
	 *
	 * @param WC_Order $order Order.
	 *
	 * @return string
	 */
	public static function state( $order ) {
		$state = (string) $order->get_meta( self::STATE );

		return in_array( $state, self::states(), true ) ? $state : '';
	}

	/**
	 * Whether the order has reached the end of its journey.
	 *
	 * @param WC_Order $order Order.
	 *
	 * @return bool
	 */
	public static function is_finished( $order ) {
		return '' !== (string) $order->get_meta( self::FINISHED );
	}

	/**
	 * One state for an order whose parcels are in several.
	 *
	 * The order is only as far along as its slowest parcel: two delivered and
	 * one in a locker is an order still waiting to be collected. A parcel on
	 * its way back says more about the order than the others do, so it wins.
	 *
	 * @param array $states States of the order's parcels; '' for unknown.
	 *
	 * @return string
	 */
	public static function combine( $states ) {
		$states = array_values( array_filter( (array) $states ) );

		if ( ! $states ) {
			return '';
		}

		if ( in_array( self::RETURNING, $states, true ) ) {
			return self::RETURNING;
		}

		if ( in_array( self::RETURNED, $states, true ) ) {
			return count( array_unique( $states ) ) === 1 ? self::RETURNED : self::RETURNING;
		}

		$order = array_flip( self::states() );

		usort(
			$states,
			static function ( $a, $b ) use ( $order ) {
				return $order[ $a ] - $order[ $b ];
			}
		);

		return $states[0];
	}

	/**
	 * How long to wait before asking about a parcel in this state again.
	 *
	 * @param string $state State, '' when the carrier has not moved it yet.
	 * @param int    $age   Seconds since the order was placed.
	 *
	 * @return int Seconds.
	 */
	public static function interval( $state, $age ) {
		$intervals = array(
			''                        => 4 * self::HOUR,
			self::IN_TRANSIT          => 3 * self::HOUR,
			self::AWAITING_COLLECTION => 6 * self::HOUR,
			self::RETURNING           => 12 * self::HOUR,
			// Only a delivery the carrier cannot vouch for yet is asked
			// about again; see apply().
			self::DELIVERED           => self::DAY,
		);

		$seconds = isset( $intervals[ $state ] ) ? $intervals[ $state ] : 6 * self::HOUR;

		// A parcel two weeks out is not going to change by the hour.
		if ( $age > 14 * self::DAY ) {
			$seconds = max( $seconds, 12 * self::HOUR );
		}

		return (int) apply_filters( 'wc_esm_delivery_interval', $seconds, $state, $age );
	}

	/**
	 * Write what the carrier said onto the order, and decide what comes next.
	 *
	 * Delivered and returned end the journey and nothing is asked again.
	 * A delivery the carrier cannot tell apart from the start of a return -
	 * Smartposti records a parcel leaving its locker the same way whether the
	 * customer took it or a courier did, to send it back - is believed only
	 * once it has stayed delivered for a day.
	 *
	 * @param WC_Order $order     Order.
	 * @param string   $state     What the carrier said, '' for nothing new.
	 * @param bool     $tentative Whether a delivery still needs confirming.
	 * @param int      $now       Now.
	 * @param int      $age       Seconds since the order was placed.
	 *
	 * @return string The state written.
	 */
	public static function apply( $order, $state, $tentative, $now, $age ) {
		$previous = self::state( $order );
		$since    = (int) $order->get_meta( self::SINCE );

		if ( '' === $state || ! in_array( $state, self::states(), true ) ) {
			$state = $previous;
		}

		// A parcel delivered after it was on its way back was delivered to
		// the sender.
		if ( self::DELIVERED === $state && in_array( $previous, array( self::RETURNING, self::RETURNED ), true ) ) {
			$state = self::RETURNED;
		}

		if ( $state !== $previous ) {
			$since = $now;
			$order->update_meta_data( self::STATE, $state );
			$order->update_meta_data( self::SINCE, $now );
		}

		$finished = self::RETURNED === $state
			|| ( self::DELIVERED === $state && ( ! $tentative || $now - $since >= self::DAY ) );

		if ( $finished ) {
			$order->update_meta_data( self::FINISHED, $now );
			$order->delete_meta_data( self::NEXT_CHECK );
		} else {
			$order->update_meta_data( self::NEXT_CHECK, $now + self::interval( $state, $age ) );
		}

		$order->save();

		return $state;
	}
}

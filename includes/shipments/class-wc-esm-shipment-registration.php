<?php
/**
 * Sending an order to its carrier, once.
 *
 * @package Estonian_Shipping_Methods_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers shipments with carriers.
 *
 * Two ways in: a shopkeeper pressing a button, and an order reaching the
 * status a carrier was configured for. The button is synchronous, because a
 * person who pressed it wants the answer on screen. The status change is not:
 * it queues the work, because a shop may well pick a status the customer
 * triggers, and a carrier API with a thirty-second timeout has no business on
 * a checkout.
 *
 * Whichever way it arrives, send() is the single door, and it refuses an
 * order that already has a shipment. That refusal is not a nicety - a repeat
 * books and bills a second real parcel, and a browser's back button is enough
 * to cause one.
 */
class WC_ESM_Shipment_Registration {

	const HOOK  = 'wc_esm_register_shipment';
	const GROUP = 'wc-estonian-shipping';

	/**
	 * Hook up.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_status_changed' ), 10, 3 );
		add_action( self::HOOK, array( __CLASS__, 'register_order' ) );
	}

	/**
	 * Whether a carrier's chosen status is the one the order just reached.
	 *
	 * An empty setting means never, and that default is what keeps a shop
	 * that has configured nothing from sending real parcels.
	 *
	 * @param string $chosen  Status the carrier was configured for.
	 * @param string $reached Status the order reached.
	 *
	 * @return bool
	 */
	public static function status_matches( $chosen, $reached ) {
		$chosen = (string) $chosen;

		if ( '' === $chosen ) {
			return false;
		}

		// WooCommerce says "wc-completed" in some places and "completed" in
		// others; the setting stores it without the prefix.
		$reached = preg_replace( '/^wc-/', '', (string) $reached );

		return $chosen === $reached;
	}

	/**
	 * Queue an order for its carrier when it reaches the chosen status.
	 *
	 * @param int    $order_id Order id.
	 * @param string $from     Old status.
	 * @param string $to       New status.
	 *
	 * @return void
	 */
	public static function on_status_changed( $order_id, $from, $to ) {
		$order = wc_get_order( $order_id );

		if ( ! $order || WC_ESM_Shipment::is_registered( $order ) ) {
			return;
		}

		$provider = self::provider_for( $order );

		if ( ! $provider || ! self::status_matches( WC_ESM_Shipment_Settings::registration_status( $provider->get_id() ), $to ) ) {
			return;
		}

		self::enqueue( $order_id );
	}

	/**
	 * Put an order in the queue, unless it is already there.
	 *
	 * @param int $order_id Order id.
	 *
	 * @return bool Whether it was queued now.
	 */
	public static function enqueue( $order_id ) {
		$args = array( 'order_id' => (int) $order_id );

		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			// Action Scheduler ships with WooCommerce, so this is close to
			// impossible - but falling back to sending now is honest
			// degradation, where doing nothing would lose the shipment
			// silently.
			self::register_order( $order_id );

			return true;
		}

		if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( self::HOOK, $args, self::GROUP ) ) {
			return false;
		}

		as_schedule_single_action( time(), self::HOOK, $args, self::GROUP );

		return true;
	}

	/**
	 * Send one order to its carrier.
	 *
	 * @param int $order_id Order id.
	 *
	 * @return void
	 */
	public static function register_order( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return;
		}

		$provider = self::provider_for( $order );

		if ( ! $provider ) {
			return;
		}

		self::send( $order, WC_ESM_Order_Snapshot::from_order( $order ), $provider );
	}

	/**
	 * The carrier that carries an order's shipping method.
	 *
	 * @param WC_Order $order Order.
	 *
	 * @return WC_ESM_Shipment_Provider|null
	 */
	public static function provider_for( $order ) {
		$registry = WC_ESM_Shipment_Registry::instance();

		foreach ( $order->get_shipping_methods() as $item ) {
			$provider = $registry->provider_for_method( $item->get_method_id() );

			if ( $provider ) {
				return $provider;
			}
		}

		return null;
	}

	/**
	 * Ask a carrier to take an order, and write down what it said.
	 *
	 * The one door every path goes through, so the already-sent guard cannot
	 * be walked around.
	 *
	 * @param WC_Order                 $order    Order.
	 * @param array                    $snapshot Order snapshot.
	 * @param WC_ESM_Shipment_Provider $provider Carrier.
	 *
	 * @return WC_ESM_Shipment_Result
	 */
	public static function send( $order, $snapshot, $provider ) {
		if ( WC_ESM_Shipment::is_registered( $order ) ) {
			return WC_ESM_Shipment_Result::failure(
				__( 'This order has already been sent to the carrier.', 'wc-estonian-shipping-methods' ),
				'already_registered'
			);
		}

		try {
			$result = $provider->register( $snapshot );
		} catch ( \Throwable $e ) {
			// A carrier integration must not be able to white-screen somebody
			// else's order status change.
			$result = WC_ESM_Shipment_Result::failure( $e->getMessage() );
		}

		WC_ESM_Shipment::record( $order, $result );
		$order->add_order_note( self::note( $provider, $result ) );

		return $result;
	}

	/**
	 * What to write on the order's timeline.
	 *
	 * @param WC_ESM_Shipment_Provider $provider Carrier.
	 * @param WC_ESM_Shipment_Result   $result   What it answered.
	 *
	 * @return string
	 */
	protected static function note( $provider, $result ) {
		if ( $result->is_failure() ) {
			return sprintf(
				/* translators: 1: carrier name, 2: what went wrong. */
				__( '%1$s: the shipment was not registered. %2$s', 'wc-estonian-shipping-methods' ),
				$provider->get_title(),
				$result->get_message()
			);
		}

		$barcodes = $result->get( 'barcodes', array() );

		if ( $barcodes ) {
			return sprintf(
				/* translators: 1: carrier name, 2: barcodes. */
				__( '%1$s: shipment registered. Barcode %2$s.', 'wc-estonian-shipping-methods' ),
				$provider->get_title(),
				implode( ', ', $barcodes )
			);
		}

		return sprintf(
			/* translators: %s: carrier name. */
			__( '%s: shipment registered.', 'wc-estonian-shipping-methods' ),
			$provider->get_title()
		);
	}
}

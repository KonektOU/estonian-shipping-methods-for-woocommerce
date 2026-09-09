<?php
/**
 * Orders Cleveron already has must not be sent to it twice.
 *
 * @package Estonian_Shipping_Methods_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Carries orders sent by the old Cleveron code into the shared shipment keys.
 *
 * The version this replaces kept Cleveron's own order id under a key of its
 * own. Everything now reads the shared keys, so without this an order Cleveron
 * already has looks unsent - and the order screen would offer to send it,
 * which creates a second real order in Cleveron for a parcel that already
 * exists.
 *
 * It runs behind Action Scheduler rather than as a loop on an admin page. The
 * version option is only written after the whole upgrade finishes, so a
 * timeout in an uncapped loop would mean the loop starts again from the top on
 * every admin page a shop with history loads.
 *
 * It also keeps a completion marker of its own, separate from the version, and
 * re-queues itself if it stopped short. A migration that silently gives up
 * leaves exactly the state it exists to prevent.
 */
class WC_ESM_Cleveron_Migration {

	const OLD_META    = 'cleveron_office_order_id';
	const STATE       = 'wc_esm_cleveron_migration';
	const HOOK        = 'wc_esm_migrate_cleveron_orders';
	const GROUP       = 'wc-estonian-shipping';
	const BATCH       = 50;

	/**
	 * Hook the queue worker up, and pick the work up again if it stalled.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run_batch' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_resume' ) );
	}

	/**
	 * Start the migration, or pick it up where it stopped.
	 *
	 * Called on every admin page, and cheap on all but the first: a shop that
	 * has finished carries a marker and does no work, and a shop that never
	 * had a Cleveron order finishes on its first empty batch.
	 *
	 * @return void
	 */
	public static function maybe_resume() {
		if ( 'done' === get_option( self::STATE, '' ) ) {
			return;
		}

		if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( self::HOOK, array(), self::GROUP ) ) {
			return;
		}

		self::queue();
	}

	/**
	 * Put the next batch in the queue.
	 *
	 * @return void
	 */
	protected static function queue() {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			self::run_batch();

			return;
		}

		as_schedule_single_action( time(), self::HOOK, array(), self::GROUP );
	}

	/**
	 * Migrate one batch, then queue the next or mark the work finished.
	 *
	 * @return void
	 */
	public static function run_batch() {
		update_option( self::STATE, 'running' );

		$orders = wc_get_orders(
			array(
				'limit'      => self::BATCH,
				'return'     => 'objects',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => self::OLD_META,
						'compare' => 'EXISTS',
					),
					array(
						'key'     => WC_ESM_Shipment::LABEL_REFS,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		$migrated = 0;

		foreach ( $orders as $order ) {
			if ( self::migrate_order( $order ) ) {
				$migrated++;
			}
		}

		if ( $migrated > 0 ) {
			self::log( sprintf( 'Migrated %d Cleveron orders onto the shared shipment keys.', $migrated ) );
			self::queue();

			return;
		}

		// Nothing left. The marker is what stops this being asked again on
		// every admin page for the rest of the shop's life.
		update_option( self::STATE, 'done' );
		self::log( 'Cleveron order migration finished.' );
	}

	/**
	 * Carry one order across.
	 *
	 * The old key is left where it is: it costs nothing, it is what the order
	 * screen showed for years, and leaving it means this can run again after
	 * a restore without anything being lost.
	 *
	 * @param WC_Order $order Order.
	 *
	 * @return bool Whether anything was written.
	 */
	public static function migrate_order( $order ) {
		$external = (string) $order->get_meta( self::OLD_META );

		if ( '' === $external || WC_ESM_Shipment::is_registered( $order ) ) {
			return false;
		}

		// Cleveron issues no barcode, so none is invented: the label
		// reference is the whole record of the parcel.
		$order->update_meta_data( WC_ESM_Shipment::LABEL_REFS, array( $external ) );
		$order->save();

		return true;
	}

	/**
	 * Write a line where a shop can find it.
	 *
	 * @param string $message What happened.
	 *
	 * @return void
	 */
	protected static function log( $message ) {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		wc_get_logger()->info( $message, array( 'source' => 'wc-estonian-shipping-methods' ) );
	}
}

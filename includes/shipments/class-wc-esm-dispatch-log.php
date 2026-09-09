<?php
/**
 * What has been booked and closed.
 *
 * @package Estonian_Shipping_Methods_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The record of couriers booked and manifests closed.
 *
 * A convenience rather than an audit trail: the carrier's own portal is where
 * the real record lives, and this exists so a shopkeeper can find yesterday's
 * manifest without logging in there. It keeps a screen's worth and lets the
 * rest go.
 *
 * It also answers what a row can still offer, which is a question about the
 * booking and the carrier rather than about the screen drawing it - a carrier
 * may have been switched off since, or may no longer do the thing.
 */
class WC_ESM_Dispatch_Log {

	const OPTION = 'wc_esm_dispatch_log';

	/**
	 * How many entries to keep. Twenty is a screen's worth.
	 *
	 * @var int
	 */
	const KEEP = 20;

	/**
	 * Everything the log holds, oldest first.
	 *
	 * @return array
	 */
	public static function entries() {
		return array_values( (array) get_option( self::OPTION, array() ) );
	}

	/**
	 * Write one line.
	 *
	 * @param string $provider_id Provider id.
	 * @param string $type        pickup or manifest.
	 * @param string $reference   What the carrier called it.
	 *
	 * @return void
	 */
	public static function record( $provider_id, $type, $reference ) {
		$entries   = self::entries();
		$entries[] = array(
			'provider'   => $provider_id,
			'type'       => $type,
			'reference'  => (string) $reference,
			'created_at' => gmdate( 'Y-m-d H:i' ),
		);

		self::store( array_slice( $entries, -self::KEEP ) );
	}

	/**
	 * One booking, by carrier and reference.
	 *
	 * The log is keyed by nothing, so two entries with the same carrier and
	 * reference are indistinguishable. The first is returned: the guard that
	 * uses this only needs to know a match exists and whether it has already
	 * been cancelled.
	 *
	 * @param string $provider_id Provider id.
	 * @param string $reference   Reference.
	 *
	 * @return array|null
	 */
	public static function find( $provider_id, $reference ) {
		foreach ( self::entries() as $entry ) {
			if ( self::matches( $entry, $provider_id, $reference ) ) {
				return $entry;
			}
		}

		return null;
	}

	/**
	 * Mark a booking cancelled.
	 *
	 * Every entry that matches, not the first: two rows with the same
	 * reference are indistinguishable, and marking only the oldest leaves the
	 * second row still offering to cancel something already gone.
	 *
	 * @param string $provider_id Provider id.
	 * @param string $reference   Reference.
	 *
	 * @return void
	 */
	public static function mark_cancelled( $provider_id, $reference ) {
		$entries = self::entries();

		foreach ( $entries as $index => $entry ) {
			if ( self::matches( $entry, $provider_id, $reference ) ) {
				$entries[ $index ]['cancelled'] = true;
			}
		}

		self::store( $entries );
	}

	/**
	 * What a row can still offer: a manifest download, a pickup cancel, or
	 * nothing at all.
	 *
	 * No button for something that would fail - the same rule the dispatch
	 * screen follows everywhere else.
	 *
	 * @param array                         $entry    Log entry.
	 * @param WC_ESM_Shipment_Registry|null $registry Registry; the shared one when omitted.
	 *
	 * @return array
	 */
	public static function row_actions( $entry, $registry = null ) {
		$registry = $registry ? $registry : WC_ESM_Shipment_Registry::instance();
		$provider = $registry->get_provider( isset( $entry['provider'] ) ? $entry['provider'] : '' );

		if ( ! $provider || ! empty( $entry['cancelled'] ) ) {
			return array();
		}

		// Booking a courier and calling one off are separate capabilities
		// because carriers differ: DPD books but its API offers no way to
		// cancel, so it declares the one and not the other.
		$offered = array(
			'manifest' => array( 'manifest', 'manifest_download' ),
			'pickup'   => array( 'pickup_cancel', 'pickup_cancel' ),
		);

		$type = isset( $entry['type'] ) ? $entry['type'] : '';

		// Nothing to fetch again without a reference to fetch it by.
		if ( ! isset( $offered[ $type ] ) || '' === (string) ( isset( $entry['reference'] ) ? $entry['reference'] : '' ) ) {
			return array();
		}

		list( $feature, $action ) = $offered[ $type ];

		return $provider->supports( $feature ) ? array( $action ) : array();
	}

	/**
	 * Whether an entry is the one being looked for.
	 *
	 * @param array  $entry       Entry.
	 * @param string $provider_id Provider id.
	 * @param string $reference   Reference.
	 *
	 * @return bool
	 */
	protected static function matches( $entry, $provider_id, $reference ) {
		return isset( $entry['provider'], $entry['reference'] )
			&& $entry['provider'] === $provider_id
			&& $entry['reference'] === $reference;
	}

	/**
	 * Put the log back.
	 *
	 * @param array $entries Entries.
	 *
	 * @return void
	 */
	protected static function store( $entries ) {
		update_option( self::OPTION, array_values( $entries ) );
	}
}

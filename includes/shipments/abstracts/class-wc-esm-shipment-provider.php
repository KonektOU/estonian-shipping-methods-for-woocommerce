<?php
/**
 * What every carrier does, and what only some of them do.
 *
 * @package Estonian_Shipping_Methods_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One carrier's integration.
 *
 * Only the identity is abstract. Everything a carrier might do is a capability
 * it opts into by naming it in $features, and the default answer to a
 * capability nobody declared is an unsupported result rather than a fatal - so
 * a carrier is written by implementing what it offers and saying nothing about
 * the rest.
 *
 * That matters because the capabilities are genuinely uneven: Cleveron prints
 * no labels and tracks nothing, only DPD closes manifests, only Omniva takes
 * returns, and only Smartposti reports what cash on delivery it collected.
 * Callers ask supports() before offering a button, and this class answers the
 * ones that ask anyway.
 */
abstract class WC_ESM_Shipment_Provider {

	/**
	 * Capabilities this carrier opts into.
	 *
	 * One of: labels, tracking, pickup, manifest, cod, cod_report, return.
	 *
	 * @var array
	 */
	protected $features = array();

	/**
	 * The carrier's stored settings, pushed in by the settings screen.
	 *
	 * @var array
	 */
	protected $settings = array();

	/**
	 * The carrier's id, used in option keys, meta and section ids.
	 *
	 * It is written into the database the first time a shop saves settings or
	 * sends a parcel, so it does not change once shipped.
	 *
	 * @return string
	 */
	abstract public function get_id();

	/**
	 * The carrier's name, as a shopkeeper knows it.
	 *
	 * @return string
	 */
	abstract public function get_title();

	/**
	 * Whether this carrier opted into a capability.
	 *
	 * @param string $feature Capability name.
	 *
	 * @return bool
	 */
	public function supports( $feature ) {
		return in_array( $feature, $this->features, true );
	}

	/**
	 * Every capability this carrier opted into.
	 *
	 * @return array
	 */
	public function get_features() {
		return $this->features;
	}

	/**
	 * Whether one of the plugin's shipping methods is carried by this carrier.
	 *
	 * A carrier that says nothing carries nothing: claiming every method in
	 * the shop would send parcels to the wrong carrier.
	 *
	 * @param string $method_id Shipping method id.
	 *
	 * @return bool
	 */
	public function carries( $method_id ) {
		return false;
	}

	/**
	 * Push the stored settings in.
	 *
	 * @param array $settings Stored settings.
	 *
	 * @return void
	 */
	public function set_settings( $settings ) {
		$this->settings = (array) $settings;
	}

	/**
	 * The stored settings.
	 *
	 * @return array
	 */
	public function get_settings() {
		return $this->settings;
	}

	/**
	 * One stored setting.
	 *
	 * @param string $key     Setting name.
	 * @param mixed  $default What to answer when the shop never filled it in.
	 *
	 * @return mixed
	 */
	public function get_setting( $key, $default = '' ) {
		return isset( $this->settings[ $key ] ) ? $this->settings[ $key ] : $default;
	}

	/**
	 * The fields this carrier wants on its settings screen.
	 *
	 * @return array
	 */
	public function get_settings_fields() {
		return array();
	}

	/**
	 * Send an order to the carrier.
	 *
	 * @param array $snapshot Carrier-independent view of the order.
	 *
	 * @return WC_ESM_Shipment_Result
	 */
	public function register( $snapshot ) {
		return WC_ESM_Shipment_Result::unsupported( 'registration' );
	}

	/**
	 * Fetch the labels for shipments already registered.
	 *
	 * @param array $refs Label references.
	 *
	 * @return WC_ESM_Shipment_Result
	 */
	public function fetch_labels( $refs ) {
		return WC_ESM_Shipment_Result::unsupported( 'labels' );
	}

	/**
	 * Where a customer can follow a parcel.
	 *
	 * An empty string means there is nowhere: callers building a link fall
	 * back to plain text rather than rendering an anchor with no href.
	 *
	 * @param string $barcode Parcel barcode.
	 *
	 * @return string
	 */
	public function get_tracking_url( $barcode ) {
		return '';
	}

	/**
	 * Book a courier.
	 *
	 * @param array $args date, time_from, time_to, comment.
	 *
	 * @return WC_ESM_Shipment_Result
	 */
	public function request_pickup( $args ) {
		return WC_ESM_Shipment_Result::unsupported( 'pickup' );
	}

	/**
	 * Call a booked courier off.
	 *
	 * @param string $reference Pickup reference.
	 *
	 * @return WC_ESM_Shipment_Result
	 */
	public function cancel_pickup( $reference ) {
		return WC_ESM_Shipment_Result::unsupported( 'pickup' );
	}

	/**
	 * Close a manifest over the shipments handed to the courier.
	 *
	 * @param array $refs Shipment references.
	 *
	 * @return WC_ESM_Shipment_Result
	 */
	public function close_manifest( $refs ) {
		return WC_ESM_Shipment_Result::unsupported( 'manifest' );
	}

	/**
	 * Fetch a manifest that was already closed.
	 *
	 * @param string $reference Manifest reference.
	 *
	 * @return WC_ESM_Shipment_Result
	 */
	public function fetch_manifest( $reference ) {
		return WC_ESM_Shipment_Result::unsupported( 'manifest' );
	}

	/**
	 * Send a return shipment back to the shop.
	 *
	 * @param array $snapshot Carrier-independent view of the order.
	 *
	 * @return WC_ESM_Shipment_Result
	 */
	public function create_return( $snapshot ) {
		return WC_ESM_Shipment_Result::unsupported( 'return' );
	}

	/**
	 * The carrier this integration talks to.
	 *
	 * Every provider that reaches a network says what its base URL and its
	 * headers are, and nothing else about how a call is made. A provider that
	 * talks to nothing does not implement this.
	 *
	 * @return WC_ESM_Carrier_Client
	 */
	protected function client() {
		return new WC_ESM_Carrier_Client( '' );
	}

	/**
	 * A refusal, in words a shopkeeper can act on.
	 *
	 * The status alone tells them nothing about which field the carrier
	 * disliked, so the carrier's own words are used wherever it gave any.
	 * Where it gave none, the status at least says whether to try again.
	 *
	 * @param WC_ESM_Api_Response $response What came back.
	 * @param string              $said     What the carrier said, if anything.
	 *
	 * @return WC_ESM_Shipment_Result
	 */
	protected function refusal( $response, $said = '' ) {
		if ( 0 === $response->code() ) {
			return WC_ESM_Shipment_Result::failure(
				sprintf(
					/* translators: 1: carrier name, 2: why the request did not arrive. */
					__( '%1$s could not be reached: %2$s', 'wc-estonian-shipping-methods' ),
					$this->get_title(),
					$response->raw()
				)
			);
		}

		if ( '' !== $said ) {
			return WC_ESM_Shipment_Result::failure(
				sprintf(
					/* translators: 1: carrier name, 2: HTTP status code, 3: what the carrier said. */
					__( '%1$s refused the request (HTTP %2$d): %3$s', 'wc-estonian-shipping-methods' ),
					$this->get_title(),
					$response->code(),
					$said
				)
			);
		}

		return WC_ESM_Shipment_Result::failure(
			sprintf(
				/* translators: 1: carrier name, 2: HTTP status code. */
				__( '%1$s refused the request (HTTP %2$d).', 'wc-estonian-shipping-methods' ),
				$this->get_title(),
				$response->code()
			)
		);
	}

	/**
	 * What the carrier says it collected in cash on delivery.
	 *
	 * @param string $from Start date, Y-m-d.
	 * @param string $to   End date, Y-m-d.
	 *
	 * @return WC_ESM_Shipment_Result
	 */
	public function fetch_cod_report( $from, $to ) {
		return WC_ESM_Shipment_Result::unsupported( 'cod_report' );
	}
}

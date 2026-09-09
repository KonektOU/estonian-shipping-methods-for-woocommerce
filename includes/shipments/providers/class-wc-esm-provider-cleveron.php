<?php
/**
 * Port Cleveron Office onto the shared carrier abstraction.
 *
 * @package Estonian_Shipping_Methods_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cleveron Office, over its integration API.
 *
 * Cleveron declares no capabilities at all, and that is the point of them
 * being optional: it prints no labels, tracks nothing, books no couriers and
 * closes no manifests. The order screen asks supports() before offering a
 * button, so a Cleveron order simply shows fewer of them.
 *
 * What is per-zone stays with the shipping method: which parcel robot, what
 * slot size, which message templates. A shop with two offices runs two
 * robots, so those cannot move up to the carrier. Only the credentials are
 * the carrier's, and they are here.
 *
 * There is no update path. The version this replaced re-sent an
 * already-registered order as a PUT; that is gone, because a review found the
 * browser's back button registering - and billing - a second real parcel.
 * Registration refuses an order that already has a shipment, and that guard
 * wins over the convenience of updating one.
 */
class WC_ESM_Provider_Cleveron extends WC_ESM_Shipment_Provider {

	/**
	 * Declared features: none.
	 *
	 * @var array
	 */
	protected $features = array();

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function get_id() {
		return 'cleveron';
	}

	/**
	 * Title.
	 *
	 * @return string
	 */
	public function get_title() {
		return __( 'Cleveron Office', 'wc-estonian-shipping-methods' );
	}

	/**
	 * Which of the plugin's methods this carries.
	 *
	 * @param string $method_id Shipping method id.
	 *
	 * @return bool
	 */
	public function carries( $method_id ) {
		return 'cleveron_office' === $method_id;
	}

	/**
	 * Settings fields: the credentials, and nothing that belongs to a zone.
	 *
	 * @return array
	 */
	public function get_settings_fields() {
		return array(
			'api_url'   => array(
				'title'       => __( 'API address', 'wc-estonian-shipping-methods' ),
				'type'        => 'text',
				'default'     => '',
				'description' => __( 'The Cleveron host your contract points at, without a path.', 'wc-estonian-shipping-methods' ),
				'desc_tip'    => true,
			),
			'api_key'   => array(
				'title'   => __( 'API key', 'wc-estonian-shipping-methods' ),
				'type'    => 'password',
				'default' => '',
			),
			'api_token' => array(
				'title'   => __( 'User token', 'wc-estonian-shipping-methods' ),
				'type'    => 'password',
				'default' => '',
			),
		);
	}

	/**
	 * Send an order to Cleveron.
	 *
	 * @param array $snapshot Order snapshot.
	 *
	 * @return WC_ESM_Shipment_Result
	 */
	public function register( $snapshot ) {
		$response = $this->client()->post(
			'orders',
			WC_ESM_Payload_Cleveron::build( $snapshot, array_merge( $this->get_settings(), $this->zone_options( $snapshot ) ) )
		);

		if ( ! $response->is( 201 ) ) {
			return $this->refusal( $response, (string) $response->get( 'message', '' ) );
		}

		$id = (string) $response->get( 'id', '' );

		if ( '' === $id ) {
			return WC_ESM_Shipment_Result::failure(
				__( 'Cleveron created the order but returned no id for it.', 'wc-estonian-shipping-methods' )
			);
		}

		// There is no barcode: Cleveron gives the customer a code rather than
		// a parcel number, and there is nothing to print or follow.
		return WC_ESM_Shipment_Result::success(
			array(
				'barcodes'   => array(),
				'label_refs' => array( $id ),
			)
		);
	}

	/**
	 * The shipping zone's own options for the order being sent.
	 *
	 * Which robot, which slot, which templates - all of it belongs to the
	 * zone the order was shipped by, not to the carrier. Kept behind one
	 * method so the payload stays a function of two arrays.
	 *
	 * @param array $snapshot Order snapshot.
	 *
	 * @return array
	 */
	protected function zone_options( $snapshot ) {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $snapshot['order_id'] ) : null;

		if ( ! $order ) {
			return array();
		}

		foreach ( $order->get_shipping_methods() as $item ) {
			if ( 'cleveron_office' !== $item->get_method_id() ) {
				continue;
			}

			$method = WC_Shipping_Zones::get_shipping_method( $item->get_instance_id() );

			if ( $method ) {
				return (array) $method->instance_settings;
			}
		}

		return array();
	}

	/**
	 * Cleveron's integration API, on the host this shop was given.
	 *
	 * @return WC_ESM_Carrier_Client
	 */
	protected function client() {
		return new WC_ESM_Carrier_Client(
			trailingslashit( $this->get_setting( 'api_url' ) ) . 'integration/v2/',
			array(
				'Cleveron-Api-Key'    => $this->get_setting( 'api_key' ),
				'Cleveron-User-Token' => $this->get_setting( 'api_token' ),
			)
		);
	}
}

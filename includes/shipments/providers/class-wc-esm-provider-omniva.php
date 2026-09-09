<?php
/**
 * Omniva over OMX, without the SOAP.
 *
 * @package Estonian_Shipping_Methods_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Omniva, over the OMX REST API.
 *
 * Omniva's older ePlis interface is SOAP, and a shop that cannot load PHP's
 * SOAP extension cannot use it at all. OMX is the same carrier over JSON, so
 * that is what this speaks.
 *
 * Authentication is two things at once: HTTP basic credentials from the
 * contract, and an integration agent id Omniva issues to whoever wrote the
 * integration. A request missing either is refused.
 */
class WC_ESM_Provider_Omniva extends WC_ESM_Shipment_Provider {

	/**
	 * Where a customer follows a parcel.
	 *
	 * @var string
	 */
	const TRACKING_URL = 'https://www.omniva.ee/era/jalgimine?barcode=%s';

	/**
	 * Declared features.
	 *
	 * @var array
	 */
	protected $features = array( 'labels', 'tracking', 'pickup', 'cod', 'return' );

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function get_id() {
		return 'omniva';
	}

	/**
	 * Title.
	 *
	 * @return string
	 */
	public function get_title() {
		return __( 'Omniva', 'wc-estonian-shipping-methods' );
	}

	/**
	 * Which of the plugin's methods this carries.
	 *
	 * @param string $method_id Shipping method id.
	 *
	 * @return bool
	 */
	public function carries( $method_id ) {
		return in_array(
			$method_id,
			array(
				'omniva_parcel_machines_ee',
				'omniva_parcel_machines_lv',
				'omniva_parcel_machines_lt',
				'omniva_post_offices_ee',
			),
			true
		);
	}

	/**
	 * Settings fields.
	 *
	 * @return array
	 */
	public function get_settings_fields() {
		return array_merge(
			array(
				'tenant'        => array(
					'title'       => __( 'API host', 'wc-estonian-shipping-methods' ),
					'type'        => 'text',
					'default'     => 'omx.omniva.eu',
					'description' => __( 'Omniva issues this per customer. Leave the default unless they gave you another.', 'wc-estonian-shipping-methods' ),
					'desc_tip'    => true,
				),
				'username'      => array(
					'title'   => __( 'Username', 'wc-estonian-shipping-methods' ),
					'type'    => 'text',
					'default' => '',
				),
				'password'      => array(
					'title'   => __( 'Password', 'wc-estonian-shipping-methods' ),
					'type'    => 'password',
					'default' => '',
				),
				'agent_id'      => array(
					'title'       => __( 'Integration agent id', 'wc-estonian-shipping-methods' ),
					'type'        => 'text',
					'default'     => '',
					'description' => __( 'The Developer_XXXXXX_YYYYYY value Omniva issued. Every request carries it.', 'wc-estonian-shipping-methods' ),
					'desc_tip'    => true,
				),
				'customer_code' => array(
					'title'       => __( 'Customer code', 'wc-estonian-shipping-methods' ),
					'type'        => 'text',
					'default'     => '',
					'description' => __( 'The AXA partner code from your Omniva contract.', 'wc-estonian-shipping-methods' ),
					'desc_tip'    => true,
				),
			),
			WC_ESM_Shop_Address::fields(),
			array(
				'cod_service_code' => array(
				'group'       => 'shipments',
					'title'       => __( 'Cash on delivery service code', 'wc-estonian-shipping-methods' ),
					'type'        => 'text',
					'default'     => 'BP',
					'description' => __( 'Omniva issues the additional service list per customer. Confirm this code with them before charging cash on delivery.', 'wc-estonian-shipping-methods' ),
					'desc_tip'    => true,
				),
			)
		);
	}

	/**
	 * Send an order to Omniva.
	 *
	 * @param array $snapshot Order snapshot.
	 *
	 * @return WC_ESM_Shipment_Result
	 */
	public function register( $snapshot ) {
		$response = $this->client()->post(
			'shipments/business-to-client',
			WC_ESM_Payload_Omniva::build( $snapshot, $this->get_settings() )
		);

		if ( ! $response->ok() ) {
			return $this->refusal( $response, $this->what_it_said( $response ) );
		}

		$barcodes = array_values( (array) $response->get( 'barcodes', array() ) );

		if ( ! $barcodes ) {
			return WC_ESM_Shipment_Result::failure(
				__( 'Omniva accepted the parcel but returned no barcode for it.', 'wc-estonian-shipping-methods' )
			);
		}

		return WC_ESM_Shipment_Result::success(
			array(
				'barcodes'   => $barcodes,
				'label_refs' => $barcodes,
			)
		);
	}

	/**
	 * Fetch labels for parcels already registered.
	 *
	 * @param array $refs Barcodes.
	 *
	 * @return WC_ESM_Shipment_Result
	 */
	public function fetch_labels( $refs ) {
		$refs = array_values( array_filter( (array) $refs ) );

		if ( ! $refs ) {
			return WC_ESM_Shipment_Result::failure( __( 'There is nothing to print.', 'wc-estonian-shipping-methods' ) );
		}

		$response = $this->client()->post(
			'shipments/package-labels',
			array(
				'customerCode'      => $this->get_setting( 'customer_code' ),
				'sendAddressCardTo' => 'RESPONSE',
				'barcodes'          => $refs,
			)
		);

		if ( ! $response->ok() ) {
			return $this->refusal( $response, $this->what_it_said( $response ) );
		}

		$labels = (array) $response->get( 'labels', array() );

		if ( ! $labels ) {
			return WC_ESM_Shipment_Result::failure( __( 'Omniva returned no labels.', 'wc-estonian-shipping-methods' ) );
		}

		// Omniva hands each label back base64-encoded and keyed by barcode.
		// Decoding here keeps that detail out of the merger, which only ever
		// wants PDF bytes.
		return WC_ESM_Shipment_Result::success(
			array( 'pdfs' => array_values( array_map( 'base64_decode', $labels ) ) )
		);
	}

	/**
	 * Book a courier.
	 *
	 * @param array $args date, time_from, time_to, comment.
	 *
	 * @return WC_ESM_Shipment_Result
	 */
	public function request_pickup( $args ) {
		$response = $this->client()->post(
			'courierorders/create-pickup-order',
			array(
				'customerCode'      => $this->get_setting( 'customer_code' ),
				'contactPersonName' => $this->get_setting( 'sender_name' ),
				'contactPhone'      => $this->get_setting( 'sender_phone' ),
				'pickupAddress'     => array(
					'postcode'      => $this->get_setting( 'sender_postcode' ),
					'deliverypoint' => $this->get_setting( 'sender_city' ),
					'country'       => $this->get_setting( 'sender_country', 'EE' ),
					'street'        => trim( $this->get_setting( 'sender_street' ) . ' ' . $this->get_setting( 'sender_house' ) ),
				),
				'startTime'         => $this->moment( $args, 'time_from', '08:00' ),
				'endTime'           => $this->moment( $args, 'time_to', '17:00' ),
				'pickupComment'     => isset( $args['comment'] ) ? $args['comment'] : '',
				'isTwoManPickup'    => false,
				'isHeavyPackage'    => false,
				'packageCount'      => 1,
			)
		);

		if ( ! $response->ok() ) {
			return $this->refusal( $response, $this->what_it_said( $response ) );
		}

		return WC_ESM_Shipment_Result::success(
			array( 'reference' => (string) $response->get( 'orderNumber', '' ) )
		);
	}

	/**
	 * Call a booked courier off.
	 *
	 * @param string $reference Pickup reference.
	 *
	 * @return WC_ESM_Shipment_Result
	 */
	public function cancel_pickup( $reference ) {
		$response = $this->client()->post(
			'courierorders/cancel-pickup-order',
			array(
				'customerCode' => $this->get_setting( 'customer_code' ),
				'orderNumber'  => (string) $reference,
			)
		);

		if ( ! $response->ok() ) {
			return $this->refusal( $response, $this->what_it_said( $response ) );
		}

		return WC_ESM_Shipment_Result::success();
	}

	/**
	 * Where a customer follows a parcel.
	 *
	 * @param string $barcode Barcode.
	 *
	 * @return string
	 */
	public function get_tracking_url( $barcode ) {
		$barcode = (string) $barcode;

		return '' === $barcode ? '' : sprintf( self::TRACKING_URL, rawurlencode( $barcode ) );
	}

	/**
	 * One date and time, in the shape OMX wants it.
	 *
	 * @param array  $args    Pickup arguments.
	 * @param string $key     Which time.
	 * @param string $default Fallback time of day.
	 *
	 * @return string
	 */
	protected function moment( $args, $key, $default ) {
		$date = isset( $args['date'] ) && '' !== $args['date'] ? $args['date'] : gmdate( 'Y-m-d' );
		$time = isset( $args[ $key ] ) && '' !== $args[ $key ] ? $args[ $key ] : $default;

		return sprintf( '%sT%s:00.000', $date, $time );
	}

	/**
	 * What Omniva said was wrong, if it said anything.
	 *
	 * @param WC_ESM_Api_Response $response What came back.
	 *
	 * @return string
	 */
	protected function what_it_said( $response ) {
		$said = array();

		foreach ( (array) $response->get( 'errors', array() ) as $error ) {
			if ( isset( $error['msg'] ) ) {
				$said[] = (string) $error['msg'];
			}
		}

		return implode( '; ', $said );
	}

	/**
	 * OMX, on the host and the contract this shop was given.
	 *
	 * Authentication is two things at once: the contract's own credentials,
	 * and an agent id Omniva issues to whoever wrote the integration. A
	 * request missing either is refused.
	 *
	 * @return WC_ESM_Carrier_Client
	 */
	protected function client() {
		return new WC_ESM_Carrier_Client(
			sprintf( 'https://%s/api/v01/omx/', $this->get_setting( 'tenant', 'omx.omniva.eu' ) ),
			array(
				'Authorization'          => 'Basic ' . base64_encode( $this->get_setting( 'username' ) . ':' . $this->get_setting( 'password' ) ),
				'X-Integration-Agent-Id' => $this->get_setting( 'agent_id' ),
			)
		);
	}
}

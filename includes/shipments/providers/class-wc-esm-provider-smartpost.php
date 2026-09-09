<?php
/**
 * Smartposti takes an order and gives back a barcode.
 *
 * @package Estonian_Shipping_Methods_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Smartposti, over Posti's business client API.
 *
 * The carrier renamed itself from Smartpost to Smartposti, and the name on
 * the screen followed. The id did not: it is written into every shipping zone
 * row and every order ever placed, and renaming it would strand all of them.
 */
class WC_ESM_Provider_Smartpost extends WC_ESM_Shipment_Provider {

	/**
	 * Where the API lives.
	 *
	 * @var string
	 */
	const API_URL = 'https://gateway.posti.fi/smartpost/api/ext/v1/';

	/**
	 * Where a customer follows a parcel.
	 *
	 * @var string
	 */
	const TRACKING_URL = 'https://itella.ee/eraklient/saadetise-jalgimine/?trackingCode=%s';

	/**
	 * Declared features.
	 *
	 * cod_report is deliberately absent. Smartposti carries cash on delivery,
	 * but the published API documentation available here describes no
	 * endpoint for reading back what was collected, and a capability that
	 * cannot work must not be declared - every screen gates its buttons on
	 * supports(), so declaring it would put a button there that fails when
	 * pressed. Add it here once the endpoint is confirmed.
	 *
	 * @var array
	 */
	protected $features = array( 'labels', 'tracking', 'cod' );

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function get_id() {
		return 'smartpost';
	}

	/**
	 * Title.
	 *
	 * @return string
	 */
	public function get_title() {
		return __( 'Smartposti', 'wc-estonian-shipping-methods' );
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
				'smartpost_estonia',
				'smartpost_finland',
				'smartpost_latvia',
				'smartpost_lithuania',
				'smartpost_courier',
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
		return array(
			'api_key'              => array(
				'title'       => __( 'API key', 'wc-estonian-shipping-methods' ),
				'type'        => 'password',
				'default'     => '',
				'description' => __( 'The business client key Smartposti issued. Every request carries it.', 'wc-estonian-shipping-methods' ),
				'desc_tip'    => true,
			),
			'label_format'         => array(
				'group'       => 'shipments',
				'title'   => __( 'Label format', 'wc-estonian-shipping-methods' ),
				'type'    => 'select',
				'default' => 'A4-4',
				'options' => array(
					'A4-4' => __( 'A4, four to a page', 'wc-estonian-shipping-methods' ),
					'A4-8' => __( 'A4, eight to a page', 'wc-estonian-shipping-methods' ),
					'A5'   => 'A5',
					'A6'   => 'A6',
					'A7'   => 'A7',
				),
			),
			'package_size'         => array(
				'group'       => 'shipments',
				'title'       => __( 'Package size', 'wc-estonian-shipping-methods' ),
				'type'        => 'select',
				'default'     => '',
				'description' => __( 'Sent with the sender address, which Smartposti wants together with a size or not at all. Leave unset to send neither.', 'wc-estonian-shipping-methods' ),
				'desc_tip'    => true,
				'options'     => array(
					''   => __( 'Do not send one', 'wc-estonian-shipping-methods' ),
					'XS' => 'XS',
					'S'  => 'S',
					'M'  => 'M',
					'L'  => 'L',
					'XL' => 'XL',
				),
			),
			'customer_return_days' => array(
				'group'       => 'shipments',
				'title'       => __( 'Days a customer has to return a parcel', 'wc-estonian-shipping-methods' ),
				'type'        => 'text',
				'default'     => '',
				'description' => __( 'Leave empty for the carrier default.', 'wc-estonian-shipping-methods' ),
				'desc_tip'    => true,
			),
		);
	}

	/**
	 * Send an order to Smartposti.
	 *
	 * @param array $snapshot Order snapshot.
	 *
	 * @return WC_ESM_Shipment_Result
	 */
	public function register( $snapshot ) {
		$response = $this->request( 'orders', wp_json_encode( WC_ESM_Payload_Smartpost::build( $snapshot, $this->get_settings() ) ) );

		if ( 200 !== (int) $response['code'] ) {
			return WC_ESM_Shipment_Result::failure(
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'Smartposti refused the parcel (HTTP %d).', 'wc-estonian-shipping-methods' ),
					(int) $response['code']
				)
			);
		}

		$body = json_decode( $response['body'], true );

		if ( ! is_array( $body ) || empty( $body['orders']['item'][0]['barcode'] ) ) {
			// A parcel with no barcode can be neither printed nor tracked, so
			// calling this sent would strand the order: the shop would think
			// it had gone and the button to send it would be gone too.
			return WC_ESM_Shipment_Result::failure(
				__( 'Smartposti accepted the parcel but returned no barcode for it.', 'wc-estonian-shipping-methods' )
			);
		}

		$barcode = (string) $body['orders']['item'][0]['barcode'];

		return WC_ESM_Shipment_Result::success(
			array(
				'barcodes'   => array( $barcode ),
				'label_refs' => array( $barcode ),
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

		$endpoint = sprintf(
			'labels?format=%s&barcode=%s',
			rawurlencode( $this->get_setting( 'label_format', 'A4-4' ) ),
			implode( '&barcode=', array_map( 'rawurlencode', $refs ) )
		);

		$response = $this->request( $endpoint, null, 'GET' );

		if ( 200 !== (int) $response['code'] ) {
			return WC_ESM_Shipment_Result::failure(
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'Smartposti would not give the labels (HTTP %d).', 'wc-estonian-shipping-methods' ),
					(int) $response['code']
				)
			);
		}

		return WC_ESM_Shipment_Result::success( array( 'pdf' => $response['body'] ) );
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
	 * One call to Smartposti.
	 *
	 * The only place this class touches the network, so a test subclasses it
	 * and the rest of the carrier is exercised for real.
	 *
	 * @param string      $endpoint Endpoint, with any query string.
	 * @param string|null $body     Request body, for a POST.
	 * @param string      $method   HTTP method.
	 *
	 * @return array code and body.
	 */
	protected function request( $endpoint, $body = null, $method = 'POST' ) {
		$args = array(
			'timeout' => 30,
			'headers' => array(
				'Authorization' => $this->get_setting( 'api_key' ),
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			),
		);

		if ( 'POST' === $method ) {
			$args['body'] = $body;
			$response     = wp_remote_post( self::API_URL . $endpoint, $args );
		} else {
			$response = wp_remote_get( self::API_URL . $endpoint, $args );
		}

		if ( is_wp_error( $response ) ) {
			return array(
				'code' => 0,
				'body' => $response->get_error_message(),
			);
		}

		return array(
			'code' => wp_remote_retrieve_response_code( $response ),
			'body' => wp_remote_retrieve_body( $response ),
		);
	}
}

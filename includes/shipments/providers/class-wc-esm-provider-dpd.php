<?php
/**
 * DPD takes an order and gives back a label.
 *
 * @package Estonian_Shipping_Methods_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * DPD, over the portal API the Baltic countries share.
 *
 * The three Baltic portals are three separate systems on the same software,
 * so which one a shop talks to follows from its country rather than from a
 * URL anyone has to type.
 *
 * Every call carries a bearer token that DPD hands out in exchange for the
 * contract's credentials and later stops accepting. Rather than logging in
 * for each call, the token is kept and refreshed when it is refused - see
 * authed(), which is the only place either of those happens.
 */
class WC_ESM_Provider_Dpd extends WC_ESM_Shipment_Provider {

	/**
	 * Where a customer follows a parcel.
	 *
	 * @var string
	 */
	const TRACKING_URL = 'https://tracking.dpd.de/parcelstatus?query=%s&locale=et_EE';

	/**
	 * What the tokens this plugin asks for are called in DPD's own list of
	 * them, so a shopkeeper looking at that list can tell where they came
	 * from.
	 *
	 * @var string
	 */
	const TOKEN_NAME = 'Estonian Shipping Methods for WooCommerce';

	/**
	 * Declared features.
	 *
	 * @var array
	 */
	protected $features = array( 'labels', 'tracking', 'pickup', 'manifest', 'cod' );

	/**
	 * Which portal answers for a country.
	 *
	 * An unknown country falls back to the Estonian one: this is an Estonian
	 * shipping plugin, and a shop that never chose is far likelier to be here
	 * than anywhere else.
	 *
	 * @param string $country Two-letter country code.
	 *
	 * @return string
	 */
	public static function host_for( $country ) {
		$hosts = array(
			'EE' => 'telli.dpd.ee',
			'LV' => 'eserviss.dpd.lv',
			'LT' => 'esiunta.dpd.lt',
		);

		$country = strtoupper( (string) $country );

		return isset( $hosts[ $country ] ) ? $hosts[ $country ] : $hosts['EE'];
	}

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function get_id() {
		return 'dpd';
	}

	/**
	 * Title.
	 *
	 * @return string
	 */
	public function get_title() {
		return __( 'DPD', 'wc-estonian-shipping-methods' );
	}

	/**
	 * Which of the plugin's methods this carries.
	 *
	 * @param string $method_id Shipping method id.
	 *
	 * @return bool
	 */
	public function carries( $method_id ) {
		return in_array( $method_id, array( 'dpd_shops_ee', 'dpd_shops_lv', 'dpd_shops_lt' ), true );
	}

	/**
	 * Settings fields.
	 *
	 * @return array
	 */
	public function get_settings_fields() {
		return array_merge(
			array(
				'country'           => array(
					'title'       => __( 'DPD portal', 'wc-estonian-shipping-methods' ),
					'type'        => 'select',
					'default'     => 'EE',
					'description' => __( 'Which of the Baltic DPD portals your contract is with. They are separate systems.', 'wc-estonian-shipping-methods' ),
					'desc_tip'    => true,
					'options'     => array(
						'EE' => __( 'Estonia', 'wc-estonian-shipping-methods' ),
						'LV' => __( 'Latvia', 'wc-estonian-shipping-methods' ),
						'LT' => __( 'Lithuania', 'wc-estonian-shipping-methods' ),
					),
				),
				'username'          => array(
					'title'   => __( 'Username', 'wc-estonian-shipping-methods' ),
					'type'    => 'text',
					'default' => '',
				),
				'password'          => array(
					'title'   => __( 'Password', 'wc-estonian-shipping-methods' ),
					'type'    => 'password',
					'default' => '',
				),
				'payer_code'        => array(
					'title'       => __( 'Payer code', 'wc-estonian-shipping-methods' ),
					'type'        => 'text',
					'default'     => '',
					'description' => __( 'The contract DPD bills. Your account manager has it.', 'wc-estonian-shipping-methods' ),
					'desc_tip'    => true,
				),
				'service_alias'     => array(
					'group'       => 'shipments',
					'title'       => __( 'Service alias', 'wc-estonian-shipping-methods' ),
					'type'        => 'text',
					'default'     => '',
					'description' => __( 'The short alias DPD lists against the service on your contract - PS for a parcel shop, CLASSIC for a business delivery. Not the name it shows beside it.', 'wc-estonian-shipping-methods' ),
					'desc_tip'    => true,
				),
				'cod_service_alias' => array(
					'group'       => 'shipments',
					'title'       => __( 'Cash on delivery service alias', 'wc-estonian-shipping-methods' ),
					'type'        => 'text',
					'default'     => '',
					'description' => __( 'Leave empty unless your contract carries one. A name DPD does not recognise has it refuse the whole shipment, not just the service.', 'wc-estonian-shipping-methods' ),
					'desc_tip'    => true,
				),
				'label_format'      => array(
					'group'       => 'shipments',
					'title'   => __( 'Label paper', 'wc-estonian-shipping-methods' ),
					'type'    => 'select',
					'default' => 'A4',
					'options' => array(
						'A4' => 'A4',
						'A6' => 'A6',
					),
				),
			),
			WC_ESM_Shop_Address::fields()
		);
	}

	/**
	 * Send an order to DPD.
	 *
	 * @param array $snapshot Order snapshot.
	 *
	 * @return WC_ESM_Shipment_Result
	 */
	public function register( $snapshot ) {
		$response = $this->authed( 'shipments', 'POST', array( WC_ESM_Payload_Dpd::build( $snapshot, $this->get_settings() ) ) );

		if ( ! $response->ok() ) {
			return $this->refusal( $response, $this->what_it_said( $response ) );
		}

		// A posted list is answered with a list, one entry per shipment, so
		// ours is the first of them.
		$created  = $response->json();
		$created  = isset( $created[0] ) && is_array( $created[0] ) ? $created[0] : array();
		$id       = isset( $created['id'] ) ? (string) $created['id'] : '';
		$barcodes = array_values( array_filter( (array) ( isset( $created['parcelNumbers'] ) ? $created['parcelNumbers'] : array() ) ) );

		if ( '' === $id ) {
			return WC_ESM_Shipment_Result::failure(
				__( 'DPD accepted the parcel but returned no shipment id for it.', 'wc-estonian-shipping-methods' )
			);
		}

		// The label is fetched by shipment id, while a customer follows the
		// parcel by its parcel number, so both are kept.
		return WC_ESM_Shipment_Result::success(
			array(
				'barcodes'   => $barcodes,
				'label_refs' => array( $id ),
			)
		);
	}

	/**
	 * Fetch labels for shipments already registered.
	 *
	 * @param array $refs Shipment ids.
	 *
	 * @return WC_ESM_Shipment_Result
	 */
	public function fetch_labels( $refs ) {
		$refs = array_values( array_filter( (array) $refs ) );

		if ( ! $refs ) {
			return WC_ESM_Shipment_Result::failure( __( 'There is nothing to print.', 'wc-estonian-shipping-methods' ) );
		}

		$response = $this->authed(
			'shipments/labels',
			'POST',
			array(
				'shipmentIds'   => $refs,
				'labelFormat'   => 'application/pdf',
				'paperSize'     => $this->get_setting( 'label_format', 'A4' ),
				'downloadLabel' => true,
			)
		);

		if ( ! $response->ok() ) {
			return $this->refusal( $response, $this->what_it_said( $response ) );
		}

		// The documents come back under "pages"; the documentation calls the
		// same thing "labels", so both are accepted.
		$pdfs = self::binary( $response->get( 'pages', $response->get( 'labels', array() ) ) );

		if ( ! $pdfs ) {
			return WC_ESM_Shipment_Result::failure( __( 'DPD returned no label.', 'wc-estonian-shipping-methods' ) );
		}

		return WC_ESM_Shipment_Result::success( array( 'pdfs' => $pdfs ) );
	}

	/**
	 * Close a manifest over the shipments handed to the courier.
	 *
	 * @param array $refs Shipment ids.
	 *
	 * @return WC_ESM_Shipment_Result
	 */
	public function close_manifest( $refs ) {
		$refs = array_values( array_filter( (array) $refs ) );

		if ( ! $refs ) {
			return WC_ESM_Shipment_Result::failure( __( 'There is nothing to put on a manifest.', 'wc-estonian-shipping-methods' ) );
		}

		$response = $this->authed( 'shipments/manifests', 'POST', array( 'shipmentIds' => $refs ) );

		if ( ! $response->ok() ) {
			return $this->refusal( $response, $this->what_it_said( $response ) );
		}

		$pdf = self::binary( $response->get( 'binaryData', '' ) );

		if ( ! $pdf ) {
			return WC_ESM_Shipment_Result::failure(
				__( 'DPD closed the manifest but returned no document for it.', 'wc-estonian-shipping-methods' )
			);
		}

		// DPD answers a closed manifest with the document itself and no
		// reference of its own, so this is the only moment it can be had.
		return WC_ESM_Shipment_Result::success(
			array(
				'pdf'       => $pdf[0],
				'reference' => '',
			)
		);
	}

	/**
	 * Fetch a manifest that was already closed.
	 *
	 * @param string $reference Manifest reference.
	 *
	 * @return WC_ESM_Shipment_Result
	 */
	public function fetch_manifest( $reference ) {
		$reference = (string) $reference;

		if ( '' === $reference ) {
			return WC_ESM_Shipment_Result::failure( __( 'There is no manifest to fetch.', 'wc-estonian-shipping-methods' ) );
		}

		$response = $this->authed( 'shipments/manifests/' . rawurlencode( $reference ), 'GET' );

		if ( ! $response->ok() ) {
			return $this->refusal( $response, $this->what_it_said( $response ) );
		}

		$pdf = self::binary( $response->get( 'binaryData', '' ) );

		return $pdf
			? WC_ESM_Shipment_Result::success( array( 'pdf' => $pdf[0] ) )
			: WC_ESM_Shipment_Result::failure( __( 'DPD returned no manifest document.', 'wc-estonian-shipping-methods' ) );
	}

	/**
	 * Book a courier.
	 *
	 * @param array $args date, time_from, time_to, comment.
	 *
	 * @return WC_ESM_Shipment_Result
	 */
	public function request_pickup( $args ) {
		$response = $this->authed(
			'pickups',
			'POST',
			array(
				'payerCode'        => $this->get_setting( 'payer_code' ),
				'pickupDate'       => isset( $args['date'] ) && '' !== $args['date'] ? $args['date'] : gmdate( 'Y-m-d' ),
				'pickupTimeFrom'   => isset( $args['time_from'] ) && '' !== $args['time_from'] ? $args['time_from'] : '09:00',
				'pickupTimeTo'     => isset( $args['time_to'] ) && '' !== $args['time_to'] ? $args['time_to'] : '17:00',
				'messageToCourier' => isset( $args['comment'] ) ? $args['comment'] : '',
				'address'          => array(
					'name'       => $this->get_setting( 'sender_name' ),
					'phone'      => $this->get_setting( 'sender_phone' ),
					'email'      => $this->get_setting( 'sender_email' ),
					'street'     => $this->get_setting( 'sender_street' ),
					'streetNo'   => $this->get_setting( 'sender_house' ),
					'city'       => $this->get_setting( 'sender_city' ),
					'postalCode' => $this->get_setting( 'sender_postcode' ),
					'country'    => $this->get_setting( 'sender_country', 'EE' ),
				),
			)
		);

		if ( ! $response->ok() ) {
			return $this->refusal( $response, $this->what_it_said( $response ) );
		}

		return WC_ESM_Shipment_Result::success(
			array( 'reference' => (string) $response->get( 'id', '' ) )
		);
	}

	/**
	 * Where a customer follows a parcel.
	 *
	 * @param string $barcode Parcel number.
	 *
	 * @return string
	 */
	public function get_tracking_url( $barcode ) {
		$barcode = (string) $barcode;

		return '' === $barcode ? '' : sprintf( self::TRACKING_URL, rawurlencode( $barcode ) );
	}

	/**
	 * One call, with a token on it, retried once if the token was refused.
	 *
	 * A token DPD has stopped accepting looks exactly like a wrong password,
	 * so the difference is made by trying: the token is thrown away, a new
	 * one fetched, and the work repeated. Once. A second refusal is a
	 * credentials problem, and retrying that forever would hammer DPD with a
	 * password it has already rejected.
	 *
	 * @param string $endpoint Endpoint under the portal's API base.
	 * @param string $method   HTTP method.
	 * @param array  $body     Request body.
	 *
	 * @return array
	 */
	protected function authed( $endpoint, $method = 'POST', $body = array() ) {
		$key   = WC_ESM_Dpd_Token::cache_key( self::host_for( $this->get_setting( 'country', 'EE' ) ), $this->get_setting( 'username' ) );
		$token = WC_ESM_Dpd_Token::stored( $key );

		if ( '' === $token ) {
			$token = $this->log_in( $key );

			if ( '' === $token ) {
				return new WC_ESM_Api_Response( 401, '' );
			}
		}

		$response = $this->call( $endpoint, $method, $body, $token );

		if ( ! $response->is( 401 ) ) {
			return $response;
		}

		WC_ESM_Dpd_Token::forget( $key );
		$token = $this->log_in( $key );

		if ( '' === $token ) {
			return $response;
		}

		return $this->call( $endpoint, $method, $body, $token );
	}

	/**
	 * One call with a token on it.
	 *
	 * @param string $endpoint Endpoint.
	 * @param string $method   HTTP method.
	 * @param array  $body     Request body.
	 * @param string $token    Bearer token.
	 *
	 * @return WC_ESM_Api_Response
	 */
	protected function call( $endpoint, $method, $body, $token ) {
		return $this->client()->request(
			$method,
			$endpoint,
			'GET' === $method ? null : $body,
			array( 'Authorization' => 'Bearer ' . $token )
		);
	}

	/**
	 * Trade the contract's credentials for a token, and remember it.
	 *
	 * @param string $key Cache key to remember it under.
	 *
	 * @return string Empty when DPD refused.
	 */
	protected function log_in( $key ) {
		$response = $this->client()->post(
			'auth/tokens',
			array(
				'name' => self::TOKEN_NAME,
				'ttl'  => WC_ESM_Dpd_Token::TTL,
			),
			array(
				// The contract's credentials prove who is asking; the body
				// only names the token being asked for.
				'Authorization' => 'Basic ' . base64_encode( $this->get_setting( 'username' ) . ':' . $this->get_setting( 'password' ) ),
			)
		);

		if ( ! $response->ok() || '' === (string) $response->get( 'token', '' ) ) {
			return '';
		}

		$token = (string) $response->get( 'token' );
		WC_ESM_Dpd_Token::remember( $key, $token );

		return $token;
	}

	/**
	 * What DPD said was wrong.
	 *
	 * Its validation errors answer in the shape RFC 7807 describes - a title
	 * and a detail keyed by field - while its other errors use a message.
	 * The field names are the half a shopkeeper can act on, so they win.
	 *
	 * @param WC_ESM_Api_Response $response What came back.
	 *
	 * @return string
	 */
	protected function what_it_said( $response ) {
		$detail = $response->get( 'detail', array() );

		if ( is_array( $detail ) && $detail ) {
			$said = array();

			foreach ( $detail as $field => $problem ) {
				$said[] = is_string( $field ) ? $field . ': ' . $problem : (string) $problem;
			}

			return implode( '; ', $said );
		}

		foreach ( array( 'message', 'title' ) as $key ) {
			$value = (string) $response->get( $key, '' );

			if ( '' !== $value ) {
				return $value;
			}
		}

		return '';
	}

	/**
	 * DPD's base64 payloads as bytes.
	 *
	 * Labels arrive as a list of objects each carrying one, a manifest as a
	 * single string; both are base64.
	 *
	 * @param mixed $data What the carrier sent.
	 *
	 * @return array Decoded documents.
	 */
	protected static function binary( $data ) {
		if ( is_string( $data ) ) {
			return '' === $data ? array() : array( self::decode( $data ) );
		}

		$documents = array();

		foreach ( (array) $data as $entry ) {
			if ( ! empty( $entry['binaryData'] ) ) {
				$documents[] = self::decode( $entry['binaryData'] );
			}
		}

		return $documents;
	}

	/**
	 * One base64 payload as bytes.
	 *
	 * DPD sends these as data URIs - "data:application/pdf;base64," and then
	 * the payload - and decoding the prefix along with the rest produces a
	 * file no reader will open. The documentation describes bare base64, so
	 * both are handled.
	 *
	 * @param string $data What the carrier sent.
	 *
	 * @return string
	 */
	protected static function decode( $data ) {
		$data = (string) $data;
		$comma = strpos( $data, ',' );

		if ( 0 === strpos( $data, 'data:' ) && false !== $comma ) {
			$data = substr( $data, $comma + 1 );
		}

		return (string) base64_decode( $data );
	}

	/**
	 * The portal this shop's contract is with.
	 *
	 * @return WC_ESM_Carrier_Client
	 */
	protected function client() {
		return new WC_ESM_Carrier_Client(
			sprintf( 'https://%s/api/v1/', self::host_for( $this->get_setting( 'country', 'EE' ) ) )
		);
	}
}

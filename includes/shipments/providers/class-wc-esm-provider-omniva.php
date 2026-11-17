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
	 * Where reading Omniva's event feed got to: the last event id seen.
	 */
	const FEED_CURSOR = 'wc_esm_omniva_tracking_cursor';

	/**
	 * News from the feed about parcels not yet asked about.
	 */
	const FEED_BUFFER = 'wc_esm_omniva_tracking_buffer';

	/**
	 * Events asked for per page of the feed.
	 */
	const FEED_PAGE = 500;

	/**
	 * Pages read per run. Omniva allows five tracking requests in five
	 * minutes; one is left over for anything else that needs it.
	 */
	const FEED_PAGES_PER_RUN = 4;

	/**
	 * Omniva's event codes, as delivery states.
	 *
	 * From Omniva's "Possible events for event request". Codes that say
	 * nothing about where the parcel is - data registered, a notification
	 * sent, contact details updated - are absent and leave the state alone.
	 */
	const EVENT_STATES = array(
		'TRT_SHIPMENT_HANDOVER_TO_PM'                    => WC_ESM_Delivery::IN_TRANSIT,
		'TRT_SHIPMENT_PICKED_UP_BY_COURIER'              => WC_ESM_Delivery::IN_TRANSIT,
		'TRT_SHIPMENT_HANDOVER_TO_PO'                    => WC_ESM_Delivery::IN_TRANSIT,
		'TRT_SHIPMENT_DEPARTED_FROM_PM'                  => WC_ESM_Delivery::IN_TRANSIT,
		'TRT_SHIPMENT_ARRIVED_TO_PO'                     => WC_ESM_Delivery::IN_TRANSIT,
		'TRT_SHIPMENT_COURIER_DELIVERY_STARTED'          => WC_ESM_Delivery::IN_TRANSIT,
		'TRT_SHIPMENT_REGISTERED_IN_OMNIVA'              => WC_ESM_Delivery::IN_TRANSIT,
		'TRT_SHIPMENT_REDIRECTION'                       => WC_ESM_Delivery::IN_TRANSIT,
		'TRT_SHIPMENT_FAILED_DELIVERY_ATTEMPT'           => WC_ESM_Delivery::IN_TRANSIT,
		'TRT_SHIPMENT_MOVEMENT_RESTORED'                 => WC_ESM_Delivery::IN_TRANSIT,
		'TRT_SHIPMENT_EMA'                               => WC_ESM_Delivery::IN_TRANSIT,
		'TRT_SHIPMENT_EMB'                               => WC_ESM_Delivery::IN_TRANSIT,
		'TRT_SHIPMENT_EMC'                               => WC_ESM_Delivery::IN_TRANSIT,
		'TRT_SHIPMENT_EMD'                               => WC_ESM_Delivery::IN_TRANSIT,
		'TRT_SHIPMENT_EMF'                               => WC_ESM_Delivery::IN_TRANSIT,
		'TRT_SHIPMENT_EMG'                               => WC_ESM_Delivery::IN_TRANSIT,
		'TRT_SHIPMENT_EMJ'                               => WC_ESM_Delivery::IN_TRANSIT,
		'TRT_SHIPMENT_EDD'                               => WC_ESM_Delivery::IN_TRANSIT,
		'TRT_SHIPMENT_EDG'                               => WC_ESM_Delivery::IN_TRANSIT,
		'TRT_SHIPMENT_STORED_IN_PM'                      => WC_ESM_Delivery::AWAITING_COLLECTION,
		'TRT_SHIPMENT_ARRIVED_TO_FINAL_PO'               => WC_ESM_Delivery::AWAITING_COLLECTION,
		'TRT_SHIPMENT_EDH'                               => WC_ESM_Delivery::AWAITING_COLLECTION,
		'TRT_SHIPMENT_DELIVERED'                         => WC_ESM_Delivery::DELIVERED,
		'TRT_SHIPMENT_EMI'                               => WC_ESM_Delivery::DELIVERED,
		'TRT_SHIPMENT_RETURN'                            => WC_ESM_Delivery::RETURNING,
		'TRT_SHIPMENT_RETURNED_BY_CUSTOMER'              => WC_ESM_Delivery::RETURNING,
	);

	/**
	 * Declared features.
	 *
	 * @var array
	 */
	protected $features = array( 'labels', 'tracking', 'delivery', 'cod', 'return' );

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
					'default'     => 'COD',
					'description' => __( 'Omniva issues the additional service list per customer. Confirm this code with them before charging cash on delivery.', 'wc-estonian-shipping-methods' ),
					'desc_tip'    => true,
				),
				'cod_bank_account' => array(
					'group'       => 'shipments',
					'title'       => __( 'Cash on delivery bank account', 'wc-estonian-shipping-methods' ),
					'type'        => 'text',
					'default'     => '',
					'description' => __( 'The IBAN Omniva pays the collected money into. Cash on delivery does not work without it.', 'wc-estonian-shipping-methods' ),
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
	 * Where these parcels are, from Omniva's event feed.
	 *
	 * Omniva allows five tracking requests in five minutes, which asking
	 * parcel by parcel would spend on five parcels. The feed returns every
	 * event on the contract since the last one read, so a few requests cover
	 * every parcel at once. Reading it moves past those events for good, so
	 * news about parcels that were not due this time is kept in a buffer and
	 * answered from when their turn comes.
	 *
	 * @param array $barcodes Barcodes due to be asked about.
	 *
	 * @return WC_ESM_Shipment_Result
	 */
	public function track_parcels( $barcodes ) {
		$buffer = get_option( self::FEED_BUFFER, array() );
		$buffer = is_array( $buffer ) ? $buffer : array();
		$read   = $this->read_feed( $buffer );

		if ( $read instanceof WC_ESM_Shipment_Result ) {
			return $read;
		}

		$buffer = $read;
		$states = array();

		foreach ( array_map( 'strval', (array) $barcodes ) as $barcode ) {
			if ( isset( $buffer[ $barcode ] ) ) {
				$states[ $barcode ] = $buffer[ $barcode ]['state'];
				unset( $buffer[ $barcode ] ); // Written to the order now.
			}
		}

		// Nobody will ask about a parcel past the following window.
		$horizon = time() - WC_ESM_Delivery::FOLLOW_FOR;
		$buffer  = array_filter(
			$buffer,
			static function ( $entry ) use ( $horizon ) {
				return $entry['at'] >= $horizon;
			}
		);

		update_option( self::FEED_BUFFER, $buffer, false );

		return WC_ESM_Shipment_Result::success( array( 'states' => $states ) );
	}

	/**
	 * Read the feed on from where it was left, into the buffer.
	 *
	 * @param array $buffer Latest state per barcode not yet handed over.
	 *
	 * @return array|WC_ESM_Shipment_Result The buffer, or the refusal when
	 *                                      not even the first page came back.
	 */
	protected function read_feed( $buffer ) {
		$cursor = (int) get_option( self::FEED_CURSOR, 0 );

		for ( $page = 0; $page < self::FEED_PAGES_PER_RUN; $page++ ) {
			$query = array(
				'customerCode' => $this->get_setting( 'customer_code' ),
				'size'         => self::FEED_PAGE,
				'sort'         => 'eventId,asc',
			);

			if ( $cursor > 0 ) {
				$query['fromTrackEventId'] = $cursor;
			} else {
				// First read: as far back as anything is still followed.
				$query['fromTimestamp'] = gmdate( 'Y-m-d\\TH:i:s.000\\Z', time() - WC_ESM_Delivery::FOLLOW_FOR );
			}

			$response = $this->client()->get( 'shipments?' . http_build_query( $query ) );

			if ( ! $response->ok() ) {
				if ( 0 === $page ) {
					return $this->refusal( $response, $this->what_it_said( $response ) );
				}

				break; // Keep what was read; the rest waits for the next run.
			}

			$events = (array) $response->get( 'content', array() );

			foreach ( $events as $event ) {
				$id      = isset( $event['eventId'] ) ? (int) $event['eventId'] : 0;
				$barcode = isset( $event['shipmentBarcode'] ) ? (string) $event['shipmentBarcode'] : '';
				$code    = isset( $event['eventCode'] ) ? (string) $event['eventCode'] : '';
				$cursor  = max( $cursor, $id );

				if ( '' === $barcode || ! isset( self::EVENT_STATES[ $code ] ) ) {
					continue;
				}

				$state    = self::EVENT_STATES[ $code ];
				$previous = isset( $buffer[ $barcode ] ) ? $buffer[ $barcode ] : null;

				if ( $previous && $previous['id'] > $id ) {
					continue; // An older event than the one already kept.
				}

				// Delivered after a return began is back with the sender.
				if ( WC_ESM_Delivery::DELIVERED === $state && $previous && in_array( $previous['state'], array( WC_ESM_Delivery::RETURNING, WC_ESM_Delivery::RETURNED ), true ) ) {
					$state = WC_ESM_Delivery::RETURNED;
				}

				$buffer[ $barcode ] = array(
					'id'    => $id,
					'state' => $state,
					'at'    => time(),
				);
			}

			update_option( self::FEED_CURSOR, $cursor, false );

			// The cursor moved, so the same query now asks for what follows.
			if ( count( $events ) < self::FEED_PAGE || true === $response->get( 'last', false ) ) {
				break;
			}
		}

		return $buffer;
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

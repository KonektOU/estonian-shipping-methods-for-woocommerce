<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Omniva shipping method
 *
 * @class     WC_Estonian_Shipping_Method_DPD_Shops
 * @extends   WC_Estonian_Shipping_Method_Terminals
 * @category  Shipping Methods
 * @package   Estonian_Shipping_Methods_For_WooCommerce
 */
abstract class WC_Estonian_Shipping_Method_DPD_Shops extends WC_Estonian_Shipping_Method_Terminals {

	/**
	 * URL where to fetch the locations from
	 *
	 * @var string
	 */
	public $api_url = 'https://eserviss.dpd.lv/api/v1/';

	/**
	 * API token for authentication
	 *
	 * @var string
	 */
	protected $api_token = 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJjdXN0b21lcl9pZCI6Njc3NDUsImFkbWluX2lkIjpudWxsLCJzaWduYXR1cmVfaWQiOiJlNmNlOGRiNS05ZWE4LTRkNjgtYTEwZS1iYTZhMWM0ZjAyZGYiLCJzaWduYXR1cmVfbmFtZSI6IkVzdG9uaWFuIFNoaXBwaW5nIE1ldGhvZHMgZm9yIFdvb0NvbW1lcmNlIiwiaXNzIjoiYW1iZXItbHYiLCJleHAiOjEwMTY3OTA1OTU2NH0.NgQ620Rr_zTc37jD7Xj97SUdxnSxkXVSmQBTzaDKlTY';

	/**
	 * Class constructor
	 */
	public function __construct() {
		$this->terminals_template = 'dpd';

		// Checkout phone numbe validation.
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate_customer_phone_number' ), 10, 1 );

		// Construct parent.
		parent::__construct();
	}

	/**
	 * Fetch the terminals from remote URL.
	 *
	 * @param false|string $filter_country Country to be filtered.
	 * @param integer      $filter_type    Additional filter. Not used for this method.
	 *
	 * @return array Terminals.
	 */
	public function get_terminals( $filter_country = false, $filter_type = 0 ) {
		// Fetch terminals from cache.
		$cached_terminals = $this->get_terminals_cache();

		if ( null !== $cached_terminals ) {
			return $cached_terminals;
		}

		$filter_country = $filter_country ? $filter_country : $this->get_shipping_country();
		$locations      = array();

		// Fetch terminals.
		$request_args      = array(
			'headers' => array(
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
				'Authorization' => sprintf( 'Bearer %s', apply_filters( 'wc_shipping_dpd_shops_bearer_token', $this->api_token ) ),
			),
		);
		$terminals_request = $this->request_remote_url( $this->get_terminals_url(), 'GET', null, $request_args );

		if ( true === $terminals_request['success'] ) {
			$terminals = json_decode( $terminals_request['data'] );

			foreach ( $terminals as $data ) {
				$locations[] = (object) array(
					'place_id' => $data->id,
					'zipcode'  => $data->address->postalCode,
					'name'     => $data->name,
					'address'  => sprintf( '%s, %s', $data->address->street, $data->address->city ),
					'city'     => $data->address->city,
				);
			}
		}

		// Save terminals to cache.
		$this->save_terminals_cache( $locations );

		return $locations;
	}

	/**
	 * Translates place ID to place name
	 *
	 * @param  integer $place_id Place ID
	 * @return string            Place name
	 */
	public function get_terminal_name( $place_id ) {
		$terminals = $this->get_terminals();

		foreach(  $terminals as $terminal ) {
			if ( $terminal->place_id == $place_id ) {
				return $this->get_formatted_terminal_name( $terminal );

				break;
			}
		}
	}

	/**
	 * Get URL where to fetch terminals from
	 *
	 * @return string Terminals remote URL
	 */
	public function get_terminals_url() {
		$terminals_url = untrailingslashit( $this->api_url ) . '/lockers';
		$terminals_url = add_query_arg( 'countryCode', $this->country, $terminals_url );

		return apply_filters( 'wc_shipping_dpd_shops_terminals_url', $terminals_url, $this->country, $this->api_url );
	}
}
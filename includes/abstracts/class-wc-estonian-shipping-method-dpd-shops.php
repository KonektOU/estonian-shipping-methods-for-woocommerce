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
	public $terminals_url = 'ftp://ftp:@ftp.dpdbaltics.com/PickupParcelShopData.json';

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
		$terminals_request = $this->request_remote_url( $this->terminals_url );

		if ( true === $terminals_request['success'] ) {
			$terminals = json_decode( $terminals_request['data'] );

			foreach ( $terminals as $data ) {
				if ( $filter_country !== $data->countryCode ) {
					continue;
				}

				$locations[] = (object) array(
					'place_id' => $data->parcelShopId,
					'zipcode'  => $data->zipCode,
					'name'     => $data->companyName,
					'address'  => sprintf( '%s, %s', $data->street, $data->city ),
					'city'     => $data->city,
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
	function get_terminal_name( $place_id ) {
		$terminals = $this->get_terminals();

		foreach( $terminals as $terminal ) {
			if( $terminal->place_id == $place_id ) {
				return $this->get_formatted_terminal_name( $terminal );

				break;
			}
		}
	}

	/**
	 * Get selected terminal ID from order meta
	 * @param  integer $order_id Order ID
	 * @return integer           Selected terminal ID
	 */
	function get_order_terminal( $order_id ) {
		return get_post_meta( $order_id, $this->field_name, true );
	}
}
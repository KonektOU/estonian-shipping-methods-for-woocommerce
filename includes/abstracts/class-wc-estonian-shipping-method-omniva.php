<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Omniva shipping method
 *
 * @class     WC_Estonian_Shipping_Method_Omniva
 * @extends   WC_Shipping_Method
 * @category  Shipping Methods
 * @package   Estonian_Shipping_Methods_For_WooCommerce
 */
abstract class WC_Estonian_Shipping_Method_Omniva extends WC_Estonian_Shipping_Method_Terminals {

	/**
	 * URL where to fetch the locations from
	 *
	 * @var string
	 */
	public $terminals_url = 'https://www.omniva.ee/locations.json';

	/**
	 * Which variable in the locations will contain address value
	 *
	 * @var string
	 */
	public $variable_address = 'A5_NAME';

	/**
	 * Which variable in the locations will contain address value
	 *
	 * @var string
	 */
	public $variable_city = 'A2_NAME';

	/**
	 * Class constructor
	 */
	public function __construct() {
		$this->terminals_template = 'omniva';

		// Construct parent
		parent::__construct();
	}

	/**
	 * Fetches locations and stores them to cache.
	 *
	 * @return array Terminals
	 */
	public function get_terminals( $filter_country = false, $filter_type = 0 ) {
		// Fetch terminals from cache
		$terminals_cache = $this->get_terminals_cache();

		if( $terminals_cache !== null ) {
			return $terminals_cache;
		}

		$locations          = array();
		$terminals_request  = $this->request_remote_url( $this->terminals_url );

		if( true === $terminals_request['success'] ) {
			$terminals_json  = json_decode( $terminals_request['data'] );
			$filter_country  = $filter_country ? $filter_country : $this->get_shipping_country();

			foreach( $terminals_json as $key => $location ) {
				if( $location->A0_NAME == $filter_country && $location->TYPE == $filter_type ) {
					$locations[] = (object) array(
						'place_id'   => $location->ZIP,
						'zipcode'    => $location->ZIP,
						'name'       => $location->NAME,
						'address'    => $location->{ $this->variable_address },
						'city'       => $location->{ $this->variable_city },
					);
				}
			}
		}

		// Save cache
		$this->save_terminals_cache( $locations );

		return $locations;
	}
}
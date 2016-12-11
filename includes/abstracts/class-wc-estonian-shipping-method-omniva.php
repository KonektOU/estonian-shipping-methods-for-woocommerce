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

	public $terminals_url = 'https://www.omniva.ee/locations.json';

	/**
	 * Class constructor
	 */
	public function __construct() {
		$this->terminals_template = 'omniva';

		// Construct parent
		parent::__construct();
	}

	public function get_terminals( $filter_country = false, $filter_type = 0 ) {
		// Fetch terminals from cache
		$terminals_cache = $this->get_terminals_cache();

		if( $terminals_cache !== null ) {
			return $terminals_cache;
		}

		$terminals_json  = file_get_contents( $this->terminals_url );
		$terminals_json  = json_decode( $terminals_json );

		$filter_country  = $filter_country ? $filter_country : $this->get_shipping_country();
		$locations       = array();

		foreach( $terminals_json as $key => $location ) {
			if( $location->A0_NAME == $filter_country && $location->TYPE == $filter_type ) {
				$cityKey = 'A2_NAME';
				if($filter_country == 'LT') {
					$cityKey = 'A1_NAME';
				}
				$locations[] = (object) array(
					'place_id'   => $location->ZIP,
					'zipcode'    => $location->ZIP,
					'name'       => $location->NAME,
					'address'    => $location->A5_NAME,
					'city'       => $location->A2_NAME,
				);
			}
		}

		// Save cache
		$this->save_terminals_cache( $locations );

		return $locations;
	}
}

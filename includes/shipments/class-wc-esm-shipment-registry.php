<?php
/**
 * One place that knows every carrier.
 *
 * @package Estonian_Shipping_Methods_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The carriers this shop can send with.
 *
 * Everything that needs a carrier goes through here rather than naming classes:
 * the settings screen builds a section per carrier, the dispatch screen asks
 * which of them book couriers, and registration asks which one carries the
 * shipping method an order was placed with. A carrier that is not registered
 * is simply absent from all of it.
 *
 * Lookups answer null rather than throwing, because the inputs come from the
 * database: a stored setting or an order can name a carrier that has since
 * been switched off, and that is an ordinary state, not an error.
 */
class WC_ESM_Shipment_Registry {

	/**
	 * The shared registry.
	 *
	 * @var self|null
	 */
	protected static $instance = null;

	/**
	 * Providers, keyed by id, in registration order.
	 *
	 * @var array
	 */
	protected $providers = array();

	/**
	 * The shared registry, which is the one the plugin fills during boot.
	 *
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Add a carrier.
	 *
	 * Registering an id twice replaces the first: a carrier is one
	 * integration, and the later registration is the one that meant to win.
	 *
	 * @param WC_ESM_Shipment_Provider $provider Provider.
	 *
	 * @return void
	 */
	public function register( $provider ) {
		$this->providers[ $provider->get_id() ] = $provider;
	}

	/**
	 * Every carrier, in the order they were registered.
	 *
	 * @return array Providers keyed by id.
	 */
	public function get_providers() {
		return $this->providers;
	}

	/**
	 * One carrier by id.
	 *
	 * @param string $id Provider id.
	 *
	 * @return WC_ESM_Shipment_Provider|null
	 */
	public function get_provider( $id ) {
		return isset( $this->providers[ $id ] ) ? $this->providers[ $id ] : null;
	}

	/**
	 * The carriers that opted into a capability.
	 *
	 * @param string $feature Capability name.
	 *
	 * @return array Providers keyed by id.
	 */
	public function providers_supporting( $feature ) {
		return array_filter(
			$this->providers,
			static function ( $provider ) use ( $feature ) {
				return $provider->supports( $feature );
			}
		);
	}

	/**
	 * The carrier that carries an order.
	 *
	 * An order is shipped by one line, and that line names a method. Three
	 * screens and the registration all needed this and each kept its own copy
	 * of the loop; it belongs here, beside provider_for_method().
	 *
	 * @param WC_Order $order Order.
	 *
	 * @return WC_ESM_Shipment_Provider|null
	 */
	public function provider_for_order( $order ) {
		foreach ( $order->get_shipping_methods() as $item ) {
			$provider = $this->provider_for_method( $item->get_method_id() );

			if ( $provider ) {
				return $provider;
			}
		}

		return null;
	}

	/**
	 * The carrier that carries a shipping method.
	 *
	 * @param string $method_id Shipping method id.
	 *
	 * @return WC_ESM_Shipment_Provider|null
	 */
	public function provider_for_method( $method_id ) {
		foreach ( $this->providers as $provider ) {
			if ( $provider->carries( $method_id ) ) {
				return $provider;
			}
		}

		return null;
	}
}

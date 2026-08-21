<?php
/**
 * Upgrades between versions.
 *
 * Only what cannot be done any other way: a plugin that changes what a setting
 * means for shops that never touched it has to write down what those shops were
 * doing before, or it changes their prices behind their backs.
 *
 * @package Estonian_Shipping_Methods_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

class WC_Estonian_Shipping_Upgrade {

	/**
	 * Where the installed version is remembered.
	 */
	const VERSION_OPTION = 'wc_estonian_shipping_methods_version';

	/**
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_upgrade' ) );
	}

	/**
	 * @return void
	 */
	public static function maybe_upgrade() {
		$installed = get_option( self::VERSION_OPTION, '' );

		if ( WC_ESTONIAN_SHIPPING_METHODS_VERSION === $installed ) {
			return;
		}

		// An install that predates this option is an upgrade, not a new site:
		// its shipping methods were built when tax status defaulted to none.
		if ( '' === $installed && self::has_existing_methods() ) {
			self::keep_current_tax_status();
		}

		update_option( self::VERSION_OPTION, WC_ESTONIAN_SHIPPING_METHODS_VERSION );
	}

	/**
	 * Are any of this plugin's methods already set up in a shipping zone?
	 *
	 * @return boolean
	 */
	private static function has_existing_methods() {

		return ! empty( self::get_methods() );
	}

	/**
	 * Write down the tax status these methods have been using.
	 *
	 * Before 1.8.0 the setting defaulted to "none", so a method whose settings
	 * have no tax status saved has been shipping untaxed. That is now the
	 * setting's job to say out loud, because the default has changed to
	 * taxable and the shop's prices must not move on an upgrade.
	 *
	 * @return void
	 */
	private static function keep_current_tax_status() {
		foreach ( self::get_methods() as $method ) {
			// The method knows where its own settings live; these methods keep
			// theirs per method rather than per zone instance, so the key is
			// not something to build by hand.
			$option_key = $method->get_option_key();
			$settings   = get_option( $option_key, array() );

			if ( ! is_array( $settings ) ) {
				$settings = array();
			}

			if ( isset( $settings['tax_status'] ) ) {
				continue;
			}

			$settings['tax_status'] = 'none';

			update_option( $option_key, $settings );
		}
	}

	/**
	 * This plugin's shipping methods, as they sit in shipping zones.
	 *
	 * Asked of WooCommerce rather than read out of the zone table: that table
	 * holds the key a method was registered under, which for this plugin is the
	 * class name, and the settings are somewhere else again. The built method
	 * knows both.
	 *
	 * @return array WC_Estonian_Shipping_Method[] keyed by option key.
	 */
	private static function get_methods() {
		$methods = array();

		if ( ! class_exists( 'WC_Shipping_Zones' ) ) {
			return $methods;
		}

		$zone_ids = array( 0 );

		foreach ( WC_Shipping_Zones::get_zones() as $zone_data ) {
			$zone_ids[] = (int) $zone_data['id'];
		}

		foreach ( $zone_ids as $zone_id ) {
			$zone = WC_Shipping_Zones::get_zone( $zone_id );

			if ( ! $zone ) {
				continue;
			}

			foreach ( $zone->get_shipping_methods() as $method ) {
				if ( $method instanceof WC_Estonian_Shipping_Method ) {
					$methods[ $method->get_option_key() ] = $method;
				}
			}
		}

		return $methods;
	}
}

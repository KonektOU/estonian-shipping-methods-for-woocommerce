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
		if ( version_compare( $installed, '1.8.0', '<' ) && self::has_existing_methods() ) {
			self::keep_current_tax_status();
		}

		if ( version_compare( $installed, '1.9.0', '<' ) ) {
			self::migrate_to_shipping_zones();
		}

		update_option( self::VERSION_OPTION, WC_ESTONIAN_SHIPPING_METHODS_VERSION );
	}


	/**
	 * Put the methods a shop had switched on into shipping zones.
	 *
	 * Until 1.9.0 these methods were configured in one place and offered
	 * everywhere, with an "enabled" checkbox of their own. Now they are zone
	 * methods like any other, which means a shop that updates would otherwise
	 * wake up with no shipping at all.
	 *
	 * So each method that was switched on is put into the zone for the country
	 * it delivers to - the shop's existing zone for that country where there is
	 * one, a new zone where there is not - carrying its own settings across:
	 * price, free shipping threshold, title, tax status and whatever else it
	 * had. The old settings are left where they are, untouched, so nothing is
	 * lost if this has to be undone.
	 *
	 * @return void
	 */
	private static function migrate_to_shipping_zones() {
		if ( ! class_exists( 'WC_Shipping_Zones' ) || ! WC()->shipping() ) {
			return;
		}

		self::rename_zone_rows_written_under_class_names();

		$already_in_zones = self::get_methods();
		$in_zones_by_id   = array();

		foreach ( $already_in_zones as $method ) {
			$in_zones_by_id[ $method->id ] = true;
		}

		foreach ( WC()->shipping()->get_shipping_methods() as $method ) {
			if ( ! $method instanceof WC_Estonian_Shipping_Method ) {
				continue;
			}

			// Already somewhere in a zone: leave it alone.
			if ( isset( $in_zones_by_id[ $method->id ] ) ) {
				continue;
			}

			$settings = get_option( 'woocommerce_' . $method->id . '_settings', array() );

			if ( ! is_array( $settings ) || empty( $settings ) ) {
				continue;
			}

			// Only what the shop was actually offering.
			if ( ! isset( $settings['enabled'] ) || 'yes' !== $settings['enabled'] ) {
				continue;
			}

			$zone        = self::get_zone_for_country( $method->country );
			$instance_id = $zone ? $zone->add_shipping_method( $method->id ) : 0;

			if ( ! $instance_id ) {
				continue;
			}

			// The settings this method was using, now the instance's own.
			unset( $settings['enabled'] );

			update_option( 'woocommerce_' . $method->id . '_' . $instance_id . '_settings', $settings );
		}
	}


	/**
	 * Zone rows written before methods were registered under their own id.
	 *
	 * A shop that added one of these to a zone by hand has a row naming the
	 * class, because that is the key the method was registered under. The
	 * method cannot be found under that name any more, so the row is renamed
	 * and its settings are moved with it.
	 *
	 * @return void
	 */
	private static function rename_zone_rows_written_under_class_names() {
		global $wpdb;

		$rows = $wpdb->get_results( "SELECT instance_id, method_id FROM {$wpdb->prefix}woocommerce_shipping_zone_methods" );

		foreach ( (array) $rows as $row ) {
			$class_name = (string) $row->method_id;

			if ( ! class_exists( $class_name ) || ! is_subclass_of( $class_name, 'WC_Estonian_Shipping_Method' ) ) {
				continue;
			}

			$method      = new $class_name();
			$instance_id = (int) $row->instance_id;

			$wpdb->update(
				$wpdb->prefix . 'woocommerce_shipping_zone_methods',
				array( 'method_id' => $method->id ),
				array( 'instance_id' => $instance_id )
			);

			$new_key = 'woocommerce_' . $method->id . '_' . $instance_id . '_settings';

			if ( get_option( $new_key, array() ) ) {
				continue;
			}

			// Whatever this instance was using: its own settings if it somehow
			// had any, otherwise the global ones - which is what a method added
			// to a zone before this version was actually running on.
			$settings = get_option( 'woocommerce_' . $class_name . '_' . $instance_id . '_settings', array() );

			if ( empty( $settings ) ) {
				$settings = get_option( 'woocommerce_' . $method->id . '_settings', array() );
			}

			if ( is_array( $settings ) && ! empty( $settings ) ) {
				unset( $settings['enabled'] );

				update_option( $new_key, $settings );
			}
		}
	}


	/**
	 * The shop's zone for a country, or a new one for it.
	 *
	 * A shop that already ships to Estonia gets its Estonian methods in the
	 * zone it already has, rather than a second zone for the same place.
	 *
	 * @param string $country Two letter country code.
	 *
	 * @return \WC_Shipping_Zone|null
	 */
	private static function get_zone_for_country( $country ) {
		$country = strtoupper( (string) $country );

		if ( '' === $country ) {
			return null;
		}

		foreach ( WC_Shipping_Zones::get_zones() as $zone_data ) {
			$zone = WC_Shipping_Zones::get_zone( (int) $zone_data['id'] );

			if ( ! $zone ) {
				continue;
			}

			foreach ( $zone->get_zone_locations() as $location ) {
				if ( 'country' === $location->type && $country === strtoupper( $location->code ) ) {
					return $zone;
				}
			}
		}

		$countries = WC()->countries ? WC()->countries->get_countries() : array();

		$zone = new WC_Shipping_Zone();
		$zone->set_zone_name( isset( $countries[ $country ] ) ? $countries[ $country ] : $country );
		$zone->add_location( $country, 'country' );
		$zone->save();

		return $zone;
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

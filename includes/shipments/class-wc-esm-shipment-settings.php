<?php
/**
 * The carrier settings screen.
 *
 * @package Estonian_Shipping_Methods_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce -> Settings -> Shipping -> Estonian shipping (integrations).
 *
 * Settings are stored the way WooCommerce stores them: one option per field,
 * named after the field's id. That is not a design choice - it is what
 * WC_Settings_Shipping does with a section that is not a shipping method, and
 * it does it without firing woocommerce_update_options_shipping_<section>. A
 * plugin that saves its own merged row on that action writes something nothing
 * ever reads, and every carrier reads back as unconfigured however carefully
 * the shopkeeper filled the screen in.
 *
 * So the screen is left to WooCommerce and this only reads. A shop configured
 * before that was understood is still read, from the merged row, so nobody has
 * to type an API key in twice.
 */
class WC_ESM_Shipment_Settings {

	/**
	 * Hook the screen up.
	 *
	 * Saving is WooCommerce's own: it writes every field on the open screen
	 * to its own option, which is why there is nothing here for it.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'woocommerce_get_sections_shipping', array( __CLASS__, 'add_section' ) );
		add_filter( 'woocommerce_get_settings_shipping', array( __CLASS__, 'get_settings' ), 10, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_warn_about_the_pdf_library' ) );
		add_action( 'woocommerce_admin_field_wc_esm_group_tabs', array( __CLASS__, 'render_group_tabs' ) );
	}

	/**
	 * The groups a carrier's fields are divided into, in the order they are
	 * offered: how to reach the carrier, who the parcels come from, what the
	 * parcels are, and what happens by itself.
	 *
	 * @return array Group labels keyed by group id.
	 */
	public static function groups() {
		return array(
			'connection' => __( 'Connection', 'wc-estonian-shipping-methods' ),
			'sender'     => __( 'Sender', 'wc-estonian-shipping-methods' ),
			'shipments'  => __( 'Shipments', 'wc-estonian-shipping-methods' ),
			'automation' => __( 'Automation', 'wc-estonian-shipping-methods' ),
		);
	}

	/**
	 * The groups one carrier actually has fields for.
	 *
	 * A carrier whose API asks nothing about the sender is not offered an
	 * empty Sender tab.
	 *
	 * @param WC_ESM_Shipment_Provider $provider Provider.
	 *
	 * @return array
	 */
	public static function groups_for( $provider ) {
		$present = array();

		foreach ( self::fields_for( $provider ) as $field ) {
			$present[ $field['group'] ] = true;
		}

		return array_intersect_key( self::groups(), $present );
	}

	/**
	 * Which group to open.
	 *
	 * A group arrives from a URL, so it is whatever somebody typed. Anything
	 * this carrier does not offer opens the first one it does.
	 *
	 * @param string                   $requested Group asked for.
	 * @param WC_ESM_Shipment_Provider $provider  Provider.
	 *
	 * @return string
	 */
	public static function active_group( $requested, $provider ) {
		$groups    = self::groups_for( $provider );
		$requested = sanitize_key( (string) $requested );

		return isset( $groups[ $requested ] ) ? $requested : (string) key( $groups );
	}

	/**
	 * A carrier's settings screen, on one of its groups.
	 *
	 * @param string $section Section id.
	 * @param string $group   Group id.
	 *
	 * @return string
	 */
	public static function group_url( $section, $group ) {
		return admin_url(
			sprintf(
				'admin.php?page=wc-settings&tab=shipping&section=%s&group=%s',
				rawurlencode( $section ),
				rawurlencode( $group )
			)
		);
	}

	/**
	 * The group tab bar, as a WooCommerce settings field.
	 *
	 * A carrier with one group gets no tabs: a single tab is a heading that
	 * looks clickable.
	 *
	 * @param array $field Field definition carrying section, groups and active.
	 *
	 * @return void
	 */
	public static function render_group_tabs( $field ) {
		if ( count( $field['groups'] ) < 2 ) {
			return;
		}

		echo '<nav class="nav-tab-wrapper wp-clearfix">';

		foreach ( $field['groups'] as $group => $label ) {
			printf(
				'<a href="%s" class="nav-tab%s">%s</a>',
				esc_url( self::group_url( $field['section'], $group ) ),
				$group === $field['active'] ? ' nav-tab-active' : '',
				esc_html( $label )
			);
		}

		echo '</nav>';
	}

	/**
	 * The section id a carrier's settings screen lives at.
	 *
	 * @param string $provider_id Provider id.
	 *
	 * @return string
	 */
	public static function section_id( $provider_id ) {
		return 'wc_esm_' . $provider_id;
	}

	/**
	 * The carrier that owns a section, if any.
	 *
	 * @param string                        $section  Section id.
	 * @param WC_ESM_Shipment_Registry|null $registry Registry, defaulting to the singleton.
	 *
	 * @return WC_ESM_Shipment_Provider|null
	 */
	public static function provider_for_section( $section, $registry = null ) {
		$registry = $registry ? $registry : WC_ESM_Shipment_Registry::instance();

		foreach ( $registry->get_providers() as $provider ) {
			if ( self::section_id( $provider->get_id() ) === $section ) {
				return $provider;
			}
		}

		return null;
	}

	/**
	 * The option one setting lives in, which is the id of its field on the
	 * screen - that is what WooCommerce saves it under.
	 *
	 * @param string $provider_id Provider id.
	 * @param string $key         Setting name.
	 *
	 * @return string
	 */
	public static function option_key( $provider_id, $key ) {
		return sprintf( 'wc_esm_%s_%s', $provider_id, $key );
	}

	/**
	 * One carrier's stored settings.
	 *
	 * @param string                        $provider_id Provider id.
	 * @param WC_ESM_Shipment_Registry|null $registry    Registry, defaulting to the singleton.
	 *
	 * @return array
	 */
	public static function settings_for( $provider_id, $registry = null ) {
		$registry = $registry ? $registry : WC_ESM_Shipment_Registry::instance();
		$provider = $registry->get_provider( $provider_id );

		if ( ! $provider ) {
			return array();
		}

		$settings = self::legacy_settings_for( $provider_id );

		foreach ( array_keys( self::fields_for( $provider ) ) as $key ) {
			$stored = get_option( self::option_key( $provider_id, $key ), null );

			if ( null !== $stored && false !== $stored ) {
				$settings[ $key ] = $stored;
			}
		}

		return $settings;
	}

	/**
	 * What a shop configured before the storage was understood still holds.
	 *
	 * One merged row per carrier was written by an earlier version of this
	 * plugin on an action WooCommerce does not fire for these screens. Any
	 * shop that has one keeps it until the screen is saved again.
	 *
	 * @param string $provider_id Provider id.
	 *
	 * @return array
	 */
	protected static function legacy_settings_for( $provider_id ) {
		$stored = get_option( 'wc_esm_settings_' . $provider_id, array() );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Which order status triggers registration for a carrier.
	 *
	 * @param string                        $provider_id Provider id.
	 * @param WC_ESM_Shipment_Registry|null $registry    Registry, defaulting to the singleton.
	 *
	 * @return string Status slug without the wc- prefix, or an empty string for never.
	 */
	public static function registration_status( $provider_id, $registry = null ) {
		$settings = self::settings_for( $provider_id, $registry );

		return isset( $settings['registration_status'] ) ? (string) $settings['registration_status'] : '';
	}

	/**
	 * Push the stored settings into every provider.
	 *
	 * @param WC_ESM_Shipment_Registry $registry Registry.
	 *
	 * @return void
	 */
	public static function hydrate( $registry ) {
		foreach ( $registry->get_providers() as $provider ) {
			$provider->set_settings( self::settings_for( $provider->get_id(), $registry ) );
		}
	}

	/**
	 * A carrier's fields, plus the ones every carrier gets.
	 *
	 * @param WC_ESM_Shipment_Provider $provider Provider.
	 *
	 * @return array
	 */
	public static function fields_for( $provider ) {
		$fields = array_merge(
			$provider->get_settings_fields(),
			array(
				'registration_status' => array(
					'title'       => __( 'Send to the carrier when the order becomes', 'wc-estonian-shipping-methods' ),
					'type'        => 'select',
					'group'       => 'automation',
					'default'     => '',
					'description' => __( 'The shipment is registered once, the first time an order reaches this status. Leave unset to send nothing automatically.', 'wc-estonian-shipping-methods' ),
					'desc_tip'    => true,
					'options'     => array_merge(
						array( '' => __( 'Never - I will send them by hand', 'wc-estonian-shipping-methods' ) ),
						self::order_status_options()
					),
				),
				'tracking_template'   => array(
					'title'       => __( 'Tracking text', 'wc-estonian-shipping-methods' ),
					'type'        => 'textarea',
					'group'       => 'automation',
					'default'     => __( 'Your parcel is on its way. Track it here: {tracking_link}', 'wc-estonian-shipping-methods' ),
					'description' => __( 'Shown in order e-mails and under My account, once the parcel has a barcode. Placeholders: {tracking_code}, {tracking_url}, {tracking_link}, {carrier}.', 'wc-estonian-shipping-methods' ),
				),
				'tracking_emails'     => array(
					'title'       => __( 'Show the tracking text in', 'wc-estonian-shipping-methods' ),
					'type'        => 'multiselect',
					'group'       => 'automation',
					'class'       => 'wc-enhanced-select',
					'css'         => 'width: 400px;',
					'default'     => 'customer_completed_order',
					'description' => __( 'Which customer e-mails carry it. An e-mail sent before the parcel has a barcode carries nothing.', 'wc-estonian-shipping-methods' ),
					'desc_tip'    => true,
					'options'     => self::customer_email_options(),
				),
			)
		);

		$groups = self::groups();

		foreach ( $fields as $key => $field ) {
			if ( empty( $field['group'] ) || ! isset( $groups[ $field['group'] ] ) ) {
				$fields[ $key ]['group'] = 'connection';
			}
		}

		return $fields;
	}

	/**
	 * WooCommerce's customer-facing e-mails.
	 *
	 * The admin's own e-mails are left out: nobody wants a tracking sentence in
	 * the new-order notification they read before the parcel exists.
	 *
	 * @return array
	 */
	protected static function customer_email_options() {
		$options = array();

		if ( ! function_exists( 'WC' ) || ! WC()->mailer() ) {
			return $options;
		}

		foreach ( WC()->mailer()->get_emails() as $email ) {
			if ( 'customer' !== $email->get_recipient() && 0 !== strpos( $email->id, 'customer_' ) ) {
				continue;
			}

			$options[ $email->id ] = $email->get_title();
		}

		return $options;
	}

	/**
	 * Order statuses, without the wc- prefix.
	 *
	 * @return array
	 */
	protected static function order_status_options() {
		$options = array();

		foreach ( wc_get_order_statuses() as $slug => $label ) {
			$options[ substr( $slug, 3 ) ] = $label;
		}

		return $options;
	}

	/**
	 * Add one section per registered carrier to the Shipping tab.
	 *
	 * @param array                         $sections Sections.
	 * @param WC_ESM_Shipment_Registry|null $registry Registry, defaulting to the singleton.
	 *
	 * @return array
	 */
	public static function add_section( $sections, $registry = null ) {
		$registry = $registry ? $registry : WC_ESM_Shipment_Registry::instance();

		foreach ( $registry->get_providers() as $provider ) {
			$sections[ self::section_id( $provider->get_id() ) ] = $provider->get_title();
		}

		return $sections;
	}

	/**
	 * The settings for one carrier's screen, in WooCommerce's own shape.
	 *
	 * @param array                         $settings Settings.
	 * @param string                        $section  Current section.
	 * @param WC_ESM_Shipment_Registry|null $registry Registry, defaulting to the singleton.
	 *
	 * @return array
	 */
	public static function get_settings( $settings, $section, $registry = null ) {
		$provider = self::provider_for_section( $section, $registry );

		if ( ! $provider ) {
			return $settings;
		}

		$id     = $provider->get_id();
		$saved  = self::settings_for( $id, $registry );
		$groups = self::groups_for( $provider );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- choosing a group is a view, not an action; active_group() takes only what it recognises.
		$active = self::active_group( isset( $_GET['group'] ) ? $_GET['group'] : '', $provider );
		$fields = array(
			array(
				'type'    => 'wc_esm_group_tabs',
				'id'      => 'wc_esm_groups_' . $id,
				'section' => $section,
				'groups'  => $groups,
				'active'  => $active,
			),
			array(
				'type' => 'title',
				'name' => $groups[ $active ],
				'id'   => 'wc_esm_section_' . $id,
			),
		);

		foreach ( self::fields_for( $provider ) as $key => $field ) {
			if ( $field['group'] !== $active ) {
				continue;
			}

			$default = isset( $field['default'] ) ? $field['default'] : '';

			// A shop that saved this screen once has an empty string stored
			// for every field it left alone; those must not shadow a default,
			// or the shop's own address would never reach the sender fields
			// of an existing install.
			$field['id']    = sprintf( 'wc_esm_%s_%s', $id, $key );
			$field['value'] = isset( $saved[ $key ] ) && '' !== $saved[ $key ] ? $saved[ $key ] : $default;

			if ( 'multiselect' === $field['type'] ) {
				$field['value'] = is_array( $field['value'] )
					? array_values( array_filter( $field['value'] ) )
					: array_filter( explode( ',', (string) $field['value'] ) );
			}

			$fields[] = $field;
		}

		$fields[] = array(
			'type' => 'sectionend',
			'id'   => 'wc_esm_section_' . $id,
		);

		return $fields;
	}

	/**
	 * Say so when the PDF library is missing, on this screen only.
	 *
	 * @return void
	 */
	public static function maybe_warn_about_the_pdf_library() {
		// The merger arrives with the label work; until then there is nothing
		// to warn about.
		if ( ! class_exists( 'WC_ESM_Pdf_Merger' ) || WC_ESM_Pdf_Merger::is_available() ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || 'woocommerce_page_wc-settings' !== $screen->id ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html( WC_ESM_Pdf_Merger::missing_notice() )
		);
	}
}

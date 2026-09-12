<?php
/**
 * Carrier settings.
 *
 * @package Estonian_Shipping_Methods_For_WooCommerce
 */

require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/class-wc-esm-shipment-settings.php';
require_once __DIR__ . '/test-shipment-provider.php';
require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/class-wc-esm-shipment-registry.php';

/**
 * Tests for WC_ESM_Shipment_Settings.
 */
class Test_Shipment_Settings extends WC_ESM_Test_Case {

	/**
	 * Stub the options store.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		// WooCommerce writes one option per field, named after the field id.
		$GLOBALS['wc_esm_options'] = array(
			'wc_esm_testcarrier_api_key'             => 'stored-key',
			'wc_esm_testcarrier_registration_status' => 'completed',
		);

		unset( $_GET['group'] );

		Brain\Monkey\Functions\when( 'sanitize_key' )->alias(
			static function ( $key ) {
				return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
			}
		);

		Brain\Monkey\Functions\when( 'admin_url' )->alias(
			static function ( $path ) {
				return 'https://example.com/wp-admin/' . $path;
			}
		);

		Brain\Monkey\Functions\when( 'get_option' )->alias(
			static function ( $key, $default = false ) {
				return isset( $GLOBALS['wc_esm_options'][ $key ] ) ? $GLOBALS['wc_esm_options'][ $key ] : $default;
			}
		);

		// fields_for() builds the status dropdown out of this.
		Brain\Monkey\Functions\when( 'wc_get_order_statuses' )->justReturn(
			array(
				'wc-processing' => 'Processing',
				'wc-completed'  => 'Completed',
			)
		);
	}

	/**
	 * A registry with one carrier in it.
	 *
	 * @return WC_ESM_Shipment_Registry
	 */
	protected function registry() {
		$registry = new WC_ESM_Shipment_Registry();
		$registry->register( new WC_ESM_Test_Provider() );

		return $registry;
	}

	/**
	 * Each carrier gets its own option row.
	 *
	 * @return void
	 */
	public function test_each_field_has_its_own_option() {
		$this->assertSame( 'wc_esm_testcarrier_api_key', WC_ESM_Shipment_Settings::option_key( 'testcarrier', 'api_key' ) );
		$this->assertSame( 'wc_esm_dpd_username', WC_ESM_Shipment_Settings::option_key( 'dpd', 'username' ) );
	}

	/**
	 * Stored settings are read back, and an unconfigured carrier reads as empty.
	 *
	 * @return void
	 */
	public function test_stored_settings_are_read_back() {
		$this->assertSame( 'stored-key', WC_ESM_Shipment_Settings::settings_for( 'testcarrier', $this->registry() )['api_key'] );
	}

	/**
	 * A carrier nobody has configured reads as empty rather than as a row of
	 * false values.
	 *
	 * @return void
	 */
	public function test_an_unconfigured_carrier_reads_as_empty() {
		$GLOBALS['wc_esm_options'] = array();

		$this->assertSame( array(), WC_ESM_Shipment_Settings::settings_for( 'testcarrier', $this->registry() ) );
	}

	/**
	 * A carrier nobody registered has no fields to read, so it is empty too.
	 *
	 * @return void
	 */
	public function test_an_unregistered_carrier_reads_as_empty() {
		$this->assertSame( array(), WC_ESM_Shipment_Settings::settings_for( 'collectnet', $this->registry() ) );
	}

	/**
	 * WooCommerce stores a multiselect as an array, and it reads back as one.
	 *
	 * @return void
	 */
	public function test_a_multiselect_is_stored_as_an_array() {
		$GLOBALS['wc_esm_options']['wc_esm_testcarrier_tracking_emails'] = array( 'customer_completed_order' );

		$this->assertSame(
			array( 'customer_completed_order' ),
			WC_ESM_Shipment_Settings::settings_for( 'testcarrier', $this->registry() )['tracking_emails']
		);
	}

	/**
	 * A shop configured before the storage changed is still read, so an API
	 * key typed in once does not have to be typed in again.
	 *
	 * @return void
	 */
	public function test_settings_from_the_older_storage_are_still_read() {
		$GLOBALS['wc_esm_options'] = array(
			'wc_esm_settings_testcarrier' => array( 'api_key' => 'from-the-old-row' ),
		);

		$this->assertSame( 'from-the-old-row', WC_ESM_Shipment_Settings::settings_for( 'testcarrier', $this->registry() )['api_key'] );
	}

	/**
	 * What a field holds now wins over what the older row held.
	 *
	 * @return void
	 */
	public function test_a_field_wins_over_the_older_row() {
		$GLOBALS['wc_esm_options']['wc_esm_settings_testcarrier'] = array( 'api_key' => 'from-the-old-row' );

		$this->assertSame( 'stored-key', WC_ESM_Shipment_Settings::settings_for( 'testcarrier', $this->registry() )['api_key'] );
	}

	/**
	 * Hydrating pushes each carrier's own row into it and nobody else's.
	 *
	 * @return void
	 */
	public function test_hydrating_fills_the_providers() {
		$registry = $this->registry();
		WC_ESM_Shipment_Settings::hydrate( $registry );

		$this->assertSame( 'stored-key', $registry->get_provider( 'testcarrier' )->get_setting( 'api_key' ) );
	}

	/**
	 * The trigger status is read from the carrier's own settings.
	 *
	 * @return void
	 */
	public function test_the_registration_status_is_a_setting() {
		$this->assertSame( 'completed', WC_ESM_Shipment_Settings::registration_status( 'testcarrier', $this->registry() ) );
	}

	/**
	 * A carrier that never chose one sends nothing automatically.
	 *
	 * @return void
	 */
	public function test_a_carrier_without_a_status_sends_nothing_automatically() {
		$this->assertSame( '', WC_ESM_Shipment_Settings::registration_status( 'dpd', $this->registry() ) );
	}

	/**
	 * The fields every carrier gets are appended to the carrier's own.
	 *
	 * @return void
	 */
	public function test_the_shared_fields_are_appended_to_every_carrier() {
		$fields = WC_ESM_Shipment_Settings::fields_for( new WC_ESM_Test_Provider() );

		$this->assertArrayHasKey( 'api_key', $fields );
		$this->assertArrayHasKey( 'registration_status', $fields );
		$this->assertArrayHasKey( 'tracking_template', $fields );
		$this->assertArrayHasKey( 'tracking_emails', $fields );
	}

	/**
	 * "Never" leads the trigger options, so a carrier nobody configured
	 * cannot send anything by accident.
	 *
	 * @return void
	 */
	public function test_never_leads_the_trigger_options() {
		$options = WC_ESM_Shipment_Settings::fields_for( new WC_ESM_Test_Provider() )['registration_status']['options'];

		$this->assertSame( '', array_key_first( $options ) );
		$this->assertArrayHasKey( 'completed', $options );
		$this->assertArrayNotHasKey( 'wc-completed', $options );
	}

	/**
	 * Each carrier gets a section of its own, named after the carrier.
	 *
	 * @return void
	 */
	public function test_each_carrier_gets_its_own_section() {
		$sections = WC_ESM_Shipment_Settings::add_section( array(), $this->registry() );

		$this->assertArrayHasKey( 'wc_esm_testcarrier', $sections );
		$this->assertSame( 'Test Carrier', $sections['wc_esm_testcarrier'] );
	}

	/**
	 * Sections another plugin added are left alone.
	 *
	 * @return void
	 */
	public function test_other_sections_survive() {
		$sections = WC_ESM_Shipment_Settings::add_section( array( 'options' => 'Options' ), $this->registry() );

		$this->assertSame( 'Options', $sections['options'] );
	}

	/**
	 * A section id resolves back to the carrier that owns it.
	 *
	 * @return void
	 */
	public function test_a_section_resolves_to_its_carrier() {
		$registry = $this->registry();

		$this->assertSame( 'testcarrier', WC_ESM_Shipment_Settings::provider_for_section( 'wc_esm_testcarrier', $registry )->get_id() );
		$this->assertNull( WC_ESM_Shipment_Settings::provider_for_section( 'shipping_options', $registry ) );
		$this->assertNull( WC_ESM_Shipment_Settings::provider_for_section( '', $registry ) );
	}

	/**
	 * A carrier's screen carries that carrier's fields, opened and closed the
	 * way WooCommerce expects.
	 *
	 * @return void
	 */
	public function test_a_section_carries_only_its_own_carriers_fields() {
		$fields = WC_ESM_Shipment_Settings::get_settings( array(), 'wc_esm_testcarrier', $this->registry() );
		$ids    = array_filter( wp_list_pluck( $fields, 'id' ) );

		$this->assertContains( 'wc_esm_testcarrier_api_key', $ids );
		$this->assertNotContains( 'wc_esm_testcarrier_registration_status', $ids );
		$this->assertSame( 'wc_esm_group_tabs', $fields[0]['type'] );
		$this->assertSame( 'title', $fields[1]['type'] );
		$this->assertSame( 'sectionend', $fields[ count( $fields ) - 1 ]['type'] );
	}

	/**
	 * A stored value is what the screen shows; a field never saved shows its
	 * default.
	 *
	 * @return void
	 */
	public function test_the_screen_shows_stored_values_and_defaults() {
		$fields = WC_ESM_Shipment_Settings::get_settings( array(), 'wc_esm_testcarrier', $this->registry() );
		$values = array();

		foreach ( $fields as $field ) {
			if ( isset( $field['id'] ) ) {
				$values[ $field['id'] ] = isset( $field['value'] ) ? $field['value'] : null;
			}
		}

		$this->assertSame( 'stored-key', $values['wc_esm_testcarrier_api_key'] );
	}

	/**
	 * A multiselect is stored as one comma-separated string and handed to
	 * WooCommerce as a list.
	 *
	 * @return void
	 */
	public function test_a_multiselect_reaches_the_screen_as_a_list() {
		$GLOBALS['wc_esm_options']['wc_esm_testcarrier_tracking_emails'] = 'customer_completed_order,customer_on_hold_order';
		$_GET['group'] = 'automation';

		foreach ( WC_ESM_Shipment_Settings::get_settings( array(), 'wc_esm_testcarrier', $this->registry() ) as $field ) {
			if ( isset( $field['id'] ) && 'wc_esm_testcarrier_tracking_emails' === $field['id'] ) {
				$this->assertSame( array( 'customer_completed_order', 'customer_on_hold_order' ), $field['value'] );

				return;
			}
		}

		$this->fail( 'The tracking e-mails field was not on the screen.' );
	}

	/**
	 * A section this class does not own is handed back untouched.
	 *
	 * @return void
	 */
	public function test_a_foreign_section_is_untouched() {
		$this->assertSame(
			array( 'existing' ),
			WC_ESM_Shipment_Settings::get_settings( array( 'existing' ), 'shipping_options', $this->registry() )
		);
	}

	/**
	 * A field that names no group is a connection detail - which is what
	 * every field was before the groups existed.
	 *
	 * @return void
	 */
	public function test_a_field_naming_no_group_is_a_connection_detail() {
		$this->assertSame( 'connection', WC_ESM_Shipment_Settings::fields_for( new WC_ESM_Test_Provider() )['api_key']['group'] );
	}

	/**
	 * The three fields every carrier gets are about what happens after the
	 * order, not about reaching the carrier.
	 *
	 * @return void
	 */
	public function test_the_shared_fields_are_automation() {
		$fields = WC_ESM_Shipment_Settings::fields_for( new WC_ESM_Test_Provider() );

		$this->assertSame( 'automation', $fields['registration_status']['group'] );
		$this->assertSame( 'automation', $fields['tracking_template']['group'] );
		$this->assertSame( 'automation', $fields['tracking_emails']['group'] );
	}

	/**
	 * A carrier is offered only the groups it has fields for: the test
	 * carrier has no sender and nothing to say about parcels.
	 *
	 * @return void
	 */
	public function test_a_carrier_only_gets_the_groups_it_has_fields_for() {
		$this->assertSame( array( 'connection', 'automation' ), array_keys( WC_ESM_Shipment_Settings::groups_for( new WC_ESM_Test_Provider() ) ) );
	}

	/**
	 * A group the carrier does not offer - a stale link, a hand-edited URL -
	 * opens the first one it does.
	 *
	 * @return void
	 */
	public function test_an_unknown_group_falls_back_to_the_first() {
		$provider = new WC_ESM_Test_Provider();

		$this->assertSame( 'connection', WC_ESM_Shipment_Settings::active_group( 'sender', $provider ) );
		$this->assertSame( 'connection', WC_ESM_Shipment_Settings::active_group( '', $provider ) );
		$this->assertSame( 'connection', WC_ESM_Shipment_Settings::active_group( '<script>', $provider ) );
	}

	/**
	 * A group the carrier does offer is the one that opens.
	 *
	 * @return void
	 */
	public function test_a_known_group_is_kept() {
		$this->assertSame( 'automation', WC_ESM_Shipment_Settings::active_group( 'automation', new WC_ESM_Test_Provider() ) );
	}

	/**
	 * Asking for a group narrows the screen to it.
	 *
	 * @return void
	 */
	public function test_a_screen_carries_only_the_open_groups_fields() {
		$_GET['group'] = 'automation';

		$ids = array_filter( wp_list_pluck( WC_ESM_Shipment_Settings::get_settings( array(), 'wc_esm_testcarrier', $this->registry() ), 'id' ) );

		$this->assertContains( 'wc_esm_testcarrier_registration_status', $ids );
		$this->assertNotContains( 'wc_esm_testcarrier_api_key', $ids );
	}

	/**
	 * A shop that saved the screen once has empty strings stored for every
	 * field it left alone. Those must not shadow a default, or the shop
	 * address would never reach the sender fields of an existing install.
	 *
	 * @return void
	 */
	public function test_an_empty_saved_value_falls_back_to_the_default() {
		$GLOBALS['wc_esm_options']['wc_esm_testcarrier_tracking_template'] = '';
		$_GET['group'] = 'automation';

		foreach ( WC_ESM_Shipment_Settings::get_settings( array(), 'wc_esm_testcarrier', $this->registry() ) as $field ) {
			if ( isset( $field['id'] ) && 'wc_esm_testcarrier_tracking_template' === $field['id'] ) {
				$this->assertSame( $field['default'], $field['value'] );

				return;
			}
		}

		$this->fail( 'The tracking template field was not on the screen.' );
	}
}

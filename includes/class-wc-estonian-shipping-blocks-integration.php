<?php
/**
 * Registers this plugin's scripts with the block checkout.
 *
 * WooCommerce asks integrations which script handles belong to the checkout,
 * on the front end and in the editor, and takes care of the rest.
 *
 * The scripts are written against the globals WooCommerce already loads -
 * wc.blocksCheckout, wp.element, wp.data - rather than built from sources, so
 * this plugin still has nothing to compile and no node_modules to ship.
 *
 * @package Estonian_Shipping_Methods_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface;

class WC_Estonian_Shipping_Blocks_Integration implements IntegrationInterface {

	/**
	 * @return string
	 */
	public function get_name() {

		return 'wc-estonian-shipping-terminals';
	}

	/**
	 * @return void
	 */
	public function initialize() {
		$version = defined( 'WC_ESTONIAN_SHIPPING_METHODS_VERSION' ) ? WC_ESTONIAN_SHIPPING_METHODS_VERSION : false;

		wp_register_script(
			'wc-estonian-shipping-terminals-block',
			WC_ESTONIAN_SHIPPING_METHODS_PLUGIN_URL . '/assets/js/checkout-block.js',
			// wp-components carries the searchable combobox this uses; it is
			// WordPress's own, so there is nothing here to build or ship.
			array( 'wp-element', 'wp-i18n', 'wp-data', 'wp-components', 'wc-blocks-checkout' ),
			$version,
			true
		);

		wp_register_script(
			'wc-estonian-shipping-terminals-block-editor',
			WC_ESTONIAN_SHIPPING_METHODS_PLUGIN_URL . '/assets/js/checkout-block-editor.js',
			array( 'wp-element', 'wp-i18n', 'wp-blocks' ),
			$version,
			true
		);

		// The combobox is styled by wp-components' own stylesheet, which the
		// front end does not load by itself.
		wp_enqueue_style( 'wp-components' );

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations(
				'wc-estonian-shipping-terminals-block',
				'wc-estonian-shipping-methods',
				WC_ESTONIAN_SHIPPING_METHODS_PATH . '/languages'
			);
		}
	}

	/**
	 * @return array
	 */
	public function get_script_handles() {

		return array( 'wc-estonian-shipping-terminals-block' );
	}

	/**
	 * @return array
	 */
	public function get_editor_script_handles() {

		return array( 'wc-estonian-shipping-terminals-block-editor' );
	}

	/**
	 * @return array
	 */
	public function get_script_data() {

		return array();
	}
}

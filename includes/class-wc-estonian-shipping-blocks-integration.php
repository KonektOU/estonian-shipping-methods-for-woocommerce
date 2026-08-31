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
			array( 'wp-element', 'wp-i18n', 'wp-data', 'wc-blocks-checkout' ),
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
	 * Data the block script reads through wcSettings.
	 *
	 * @return array
	 */
	public function get_script_data() {

		return array(
			// The same switch the classic checkout obeys, so a theme that turns
			// the search off gets a plain grouped select on both checkouts
			// rather than on one of them.
			'search' => wc_esm_terminal_search_enabled(),
		);
	}
}

<?php
/**
 * Plugin Name: Estonian Shipping Methods for WooCommerce
 * Plugin URI: https://github.com/KonektOU/estonian-shipping-methods-for-woocommerce
 * Description: Extends WooCommerce with most commonly used Estonian shipping methods.
 * Version: 1.7.2
 * Author: Konekt OÜ
 * Author URI: https://www.konekt.ee
 * Developer: Risto Niinemets
 * Developer URI: https://www.konekt.ee
 * Text Domain: wc-estonian-shipping-methods
 * Domain Path: /languages
 * WC requires at least: 3.3
 * WC tested up to: 7.5.1
 *
 * @package Estonian_Shipping_Methods_For_WooCommerce
 */

// Security check.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main file constant
 */
define( 'WC_ESTONIAN_SHIPPING_METHODS_MAIN_FILE', __FILE__ );

/**
 * Includes folder path
 */
define( 'WC_ESTONIAN_SHIPPING_METHODS_INCLUDES_PATH', plugin_dir_path( WC_ESTONIAN_SHIPPING_METHODS_MAIN_FILE ) . 'includes' );

/**
 * Main class.
 *
 * @category Plugin
 * @package  Estonian_Shipping_Methods_For_WooCommerce
 */
class Estonian_Shipping_Methods_For_WooCommerce {
	/**
	 * Instance
	 *
	 * @var null
	 */
	private static $instance = null;

	/**
	 * This plugins methods
	 *
	 * @var array
	 */
	public $methods = array(
		// Smartpost.
		'WC_Estonian_Shipping_Method_Smartpost_Estonia'   => false,
		'WC_Estonian_Shipping_Method_Smartpost_Finland'   => false,
		'WC_Estonian_Shipping_Method_Smartpost_Courier'   => false,
		'WC_Estonian_Shipping_Method_Smartpost_Latvia'    => false,
		'WC_Estonian_Shipping_Method_Smartpost_Lithuania' => false,

		// Omniva.
		'WC_Estonian_Shipping_Method_Omniva_Parcel_Machines_EE' => false,
		'WC_Estonian_Shipping_Method_Omniva_Parcel_Machines_LV' => false,
		'WC_Estonian_Shipping_Method_Omniva_Parcel_Machines_LT' => false,

		// Omniva Post Offices.
		'WC_Estonian_Shipping_Method_Omniva_Post_Offices_EE' => false,

		// DPD.
		'WC_Estonian_Shipping_Method_DPD_Shops_EE' => false,
		'WC_Estonian_Shipping_Method_DPD_Shops_LV' => false,
		'WC_Estonian_Shipping_Method_DPD_Shops_LT' => false,

		// Collect.net.
		'WC_Estonian_Shipping_Method_Collect_Net' => false,
	);

	/**
	 * Class constructor
	 */
	public function __construct() {
		// Load plugin functionality when others have loaded.
		add_action( 'plugins_loaded', array( $this, 'plugins_loaded' ) );
	}

	/**
	 * Initialize plugin
	 * @return void
	 */
	public function plugins_loaded() {
		// Check if shipping methods are available.
		if ( ! $this->is_shipping_class_available() ) {
			return false;
		}

		// Load functionality, translations.
		$this->includes();
		$this->load_translations();

		// Shipping.
		add_action( 'woocommerce_shipping_init', array( $this, 'shipping_init' ) );

		// Add shipping methods.
		add_filter( 'woocommerce_shipping_methods', array( $this, 'register_shipping_methods' ) );

		// Allow WC template file search in this plugin.
		add_filter( 'woocommerce_locate_template', array( $this, 'locate_template' ), 20, 3 );
		add_filter( 'woocommerce_locate_core_template', array( $this, 'locate_template' ), 20, 3 );

		add_action( 'before_woocommerce_init', array( $this, 'declare_wc_cot_compatibility' ) );

		$this->add_terminals_hooks();
	}

	/**
	 * Require functionality
	 *
	 * @return void
	 */
	public function includes() {
		// Compatibility helpers.
		require_once WC_ESTONIAN_SHIPPING_METHODS_INCLUDES_PATH . '/compatibility-helpers.php';

		// Abstract classes.
		require_once WC_ESTONIAN_SHIPPING_METHODS_INCLUDES_PATH . '/abstracts/class-wc-estonian-shipping-method.php';
		require_once WC_ESTONIAN_SHIPPING_METHODS_INCLUDES_PATH . '/abstracts/class-wc-estonian-shipping-method-terminals.php';
		require_once WC_ESTONIAN_SHIPPING_METHODS_INCLUDES_PATH . '/abstracts/class-wc-estonian-shipping-method-smartpost.php';
		require_once WC_ESTONIAN_SHIPPING_METHODS_INCLUDES_PATH . '/abstracts/class-wc-estonian-shipping-method-omniva.php';
		require_once WC_ESTONIAN_SHIPPING_METHODS_INCLUDES_PATH . '/abstracts/class-wc-estonian-shipping-method-dpd-shops.php';

		// Methods.
		require_once WC_ESTONIAN_SHIPPING_METHODS_INCLUDES_PATH . '/methods/class-wc-estonian-shipping-method-smartpost-estonia.php';
		require_once WC_ESTONIAN_SHIPPING_METHODS_INCLUDES_PATH . '/methods/class-wc-estonian-shipping-method-smartpost-finland.php';
		require_once WC_ESTONIAN_SHIPPING_METHODS_INCLUDES_PATH . '/methods/class-wc-estonian-shipping-method-smartpost-latvia.php';
		require_once WC_ESTONIAN_SHIPPING_METHODS_INCLUDES_PATH . '/methods/class-wc-estonian-shipping-method-smartpost-lithuania.php';
		require_once WC_ESTONIAN_SHIPPING_METHODS_INCLUDES_PATH . '/methods/class-wc-estonian-shipping-method-smartpost-courier.php';

		require_once WC_ESTONIAN_SHIPPING_METHODS_INCLUDES_PATH . '/methods/class-wc-estonian-shipping-method-omniva-parcel-machines-ee.php';
		require_once WC_ESTONIAN_SHIPPING_METHODS_INCLUDES_PATH . '/methods/class-wc-estonian-shipping-method-omniva-parcel-machines-lv.php';
		require_once WC_ESTONIAN_SHIPPING_METHODS_INCLUDES_PATH . '/methods/class-wc-estonian-shipping-method-omniva-parcel-machines-lt.php';

		require_once WC_ESTONIAN_SHIPPING_METHODS_INCLUDES_PATH . '/methods/class-wc-estonian-shipping-method-omniva-post-offices-ee.php';

		require_once WC_ESTONIAN_SHIPPING_METHODS_INCLUDES_PATH . '/methods/class-wc-estonian-shipping-method-dpd-shops-ee.php';
		require_once WC_ESTONIAN_SHIPPING_METHODS_INCLUDES_PATH . '/methods/class-wc-estonian-shipping-method-dpd-shops-lv.php';
		require_once WC_ESTONIAN_SHIPPING_METHODS_INCLUDES_PATH . '/methods/class-wc-estonian-shipping-method-dpd-shops-lt.php';

		require_once WC_ESTONIAN_SHIPPING_METHODS_INCLUDES_PATH . '/methods/class-wc-estonian-shipping-method-collect-net.php';
	}

	/**
	 * Add hooks even when shipping might not be inited. Adds compatibility with lots of plugins.
	 *
	 * @return void
	 */
	public function add_terminals_hooks() {
		foreach ( $this->methods as $method_id => $method ) {
			if ( is_subclass_of( $method_id, 'WC_Estonian_Shipping_Method_Terminals' ) ) {
				$method = new $method_id();
				$method->add_terminals_hooks();
			}
		}
	}

	/**
	 * Construct our shipping methods for hooks, etc
	 *
	 * @return void
	 */
	public function shipping_init() {
		foreach ( $this->methods as $method_id => $method ) {
			$this->methods[ $method_id ] = new $method_id();
		}
	}

	/**
	 * Check if WooCommerce WC_Shipping_Method class exists
	 *
	 * @return boolean True if it does
	 */
	public function is_shipping_class_available() {
		return class_exists( 'WC_Shipping_Method' );
	}

	/**
	 * Load translations
	 *
	 * Allows overriding the offical translation by placing
	 * the translation files in wp-content/languages/estonian-shipping-methods-for-woocommerce
	 *
	 * @return void
	 */
	public function load_translations() {
		$domain = 'wc-estonian-shipping-methods';
		$locale = apply_filters( 'plugin_locale', get_locale(), $domain );

		load_textdomain( $domain, WP_LANG_DIR . '/estonian-shipping-methods-for-woocommerce/' . $domain . '-' . $locale . '.mo' );
		load_plugin_textdomain( $domain, false, dirname( plugin_basename( WC_ESTONIAN_SHIPPING_METHODS_MAIN_FILE ) ) . '/languages/' );
	}

	/**
	 * Register shipping methods
	 *
	 * @param  array $methods Shipping methods
	 * @return array          Shipping methods
	 */
	public function register_shipping_methods( $methods ) {
		// Add methods.
		foreach ( $this->methods as $method_id => $method ) {
			$methods[ $method_id ] = $method;
		}

		return $methods;
	}

	/**
	 * Get the plugin url.
	 *
	 * @return string
	 */
	public function plugin_url() {
		return untrailingslashit( plugins_url( '/', __FILE__ ) );
	}

	/**
	 * Get the plugin path.
	 *
	 * @return string
	 */
	public function plugin_path() {
		return untrailingslashit( plugin_dir_path( __FILE__ ) );
	}

	/**
	 * Locates the WooCommerce template files from this plugin directory
	 *
	 * @param  string $template      Already found template
	 * @param  string $template_name Searchable template name
	 * @param  string $template_path Template path
	 * @return string                Search result for the template
	 */
	public function locate_template( $template, $template_name, $template_path ) {
		// Tmp holder
		$_template = $template;

		if ( ! $template_path ) {
			$template_path = WC_TEMPLATE_PATH;
		}

		// Set our base path
		$plugin_path = $this->plugin_path() . '/woocommerce/';

		// Look within passed path within the theme - this is priority
		$template = locate_template(
			array(
				trailingslashit( $template_path ) . $template_name,
				$template_name,
			)
		);

		// Get the template from this plugin, if it exists
		if ( ! $template && file_exists( $plugin_path . $template_name ) ) {
			$template	= $plugin_path . $template_name;
		}

		// Use default template
		if ( ! $template ) {
			$template = $_template;
		}

		// Return what we found
		return $template;
	}


	/**
	 * Declare high performance order storage (COT) compatibility
	 *
	 * @return void
	 */
	public function declare_wc_cot_compatibility() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', WC_ESTONIAN_SHIPPING_METHODS_MAIN_FILE, true );
		}
	}

	/**
	 * Fetch instance of this plugin
	 *
	 * @return Estonian_Shipping_Methods_For_WooCommerce
	 */
	public static function instance() {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new self;
		}

		return self::$instance;
	}
}


/**
 * Returns the main instance of Estonian_Shipping_Methods_For_WooCommerce to prevent the need to use globals.
 * @return Estonian_Shipping_Methods_For_WooCommerce
 */
function WC_Estonian_Shipping_Methods() {
	return Estonian_Shipping_Methods_For_WooCommerce::instance();
}

// Global for backwards compatibility.
$GLOBALS['wc_estonian_shipping_methods'] = WC_Estonian_Shipping_Methods();

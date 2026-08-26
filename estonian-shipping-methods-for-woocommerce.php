<?php
/**
 * Plugin Name: Estonian Shipping Methods for WooCommerce
 * Plugin URI: https://github.com/KonektOU/estonian-shipping-methods-for-woocommerce
 * Description: Extends WooCommerce with most commonly used Estonian shipping methods.
 * Version: 1.12.0
 * Author: Konekt OÜ
 * Author URI: https://www.konekt.ee
 * Developer: Risto Niinemets
 * Developer URI: https://www.konekt.ee
 * Text Domain: wc-estonian-shipping-methods
 * Domain Path: /languages
 * Requires at least: 6.0
 * Tested up to: 7.0
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * WC tested up to: 11.0
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
 * Plugin path and URL, for the checkout block's scripts
 */
define( 'WC_ESTONIAN_SHIPPING_METHODS_VERSION', '1.12.0' );
define( 'WC_ESTONIAN_SHIPPING_METHODS_PATH', untrailingslashit( plugin_dir_path( WC_ESTONIAN_SHIPPING_METHODS_MAIN_FILE ) ) );
define( 'WC_ESTONIAN_SHIPPING_METHODS_PLUGIN_URL', untrailingslashit( plugin_dir_url( WC_ESTONIAN_SHIPPING_METHODS_MAIN_FILE ) ) );

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

		// Cleveron.
		'WC_Estonian_Shipping_Method_Cleveron_Office' => false,
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

		WC_Estonian_Shipping_Blocks::init();
		WC_Estonian_Shipping_Upgrade::init();

		// On init rather than here: every method's constructor translates its
		// own title, and WordPress 6.7 logs "translation loading triggered too
		// early" for anything asked for before init - which this was, on every
		// request the site served.
		add_action( 'init', array( $this, 'add_terminals_hooks' ), 5 );

		// The terminal search, for the classic checkout. The block checkout
		// brings its own with the block; the stylesheet is shared.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_checkout_assets' ) );
	}

	/**
	 * Terminal search on the checkout.
	 *
	 * A parcel terminal list runs to hundreds of entries - Omniva Estonia alone
	 * is over four hundred - which is a great deal of scrolling to find the one
	 * down the road. The list is already on the page, so the search filters it
	 * where it stands, without asking anybody's server.
	 *
	 * @return void
	 */
	public function enqueue_checkout_assets() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}

		wp_enqueue_style(
			'wc-estonian-shipping-terminals',
			WC_ESTONIAN_SHIPPING_METHODS_PLUGIN_URL . '/assets/css/checkout-terminals.css',
			array(),
			WC_ESTONIAN_SHIPPING_METHODS_VERSION
		);

		wp_enqueue_script(
			'wc-estonian-shipping-terminals-search',
			WC_ESTONIAN_SHIPPING_METHODS_PLUGIN_URL . '/assets/js/terminals-search.js',
			array(),
			WC_ESTONIAN_SHIPPING_METHODS_VERSION,
			true
		);

		wp_localize_script(
			'wc-estonian-shipping-terminals-search',
			'wcEsmTerminals',
			array(
				'searchPlaceholder' => __( 'Search by town or terminal name', 'wc-estonian-shipping-methods' ),
				'searchLabel'       => __( 'Search terminals', 'wc-estonian-shipping-methods' ),
				/* translators: %d: number of terminals matching the search. */
				'found'             => __( '%d terminals found', 'wc-estonian-shipping-methods' ),
				'nothingFound'      => __( 'No terminals match that search.', 'wc-estonian-shipping-methods' ),
			)
		);
	}

	/**
	 * Require functionality
	 *
	 * @return void
	 */
	public function includes() {
		// Compatibility helpers.
		require_once WC_ESTONIAN_SHIPPING_METHODS_INCLUDES_PATH . '/compatibility-helpers.php';

		// The block checkout, which does not fire any of the classic hooks.
		require_once WC_ESTONIAN_SHIPPING_METHODS_INCLUDES_PATH . '/class-wc-estonian-shipping-blocks.php';

		// Upgrades between versions.
		require_once WC_ESTONIAN_SHIPPING_METHODS_INCLUDES_PATH . '/class-wc-estonian-shipping-upgrade.php';

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

		require_once WC_ESTONIAN_SHIPPING_METHODS_INCLUDES_PATH . '/methods/class-wc-estonian-shipping-method-cleveron-office.php';
	}

	/**
	 * Add hooks even when shipping might not be inited. Adds compatibility with lots of plugins.
	 *
	 * A method that is not a terminal list may still need hooks of its own -
	 * an order can change status in a request that never touches the cart, a
	 * REST call or WP-CLI among them, and WooCommerce does not initialise
	 * shipping there. Anything declaring add_actions() gets the same chance
	 * the terminal methods already had.
	 *
	 * @return void
	 */
	public function add_terminals_hooks() {
		foreach ( $this->methods as $method_id => $method ) {
			if ( is_subclass_of( $method_id, 'WC_Estonian_Shipping_Method_Terminals' ) ) {
				$method = new $method_id();
				$method->add_terminals_hooks();
			} elseif ( method_exists( $method_id, 'add_actions' ) ) {
				$method = new $method_id();
				$method->add_actions();
			}
		}
	}

	/**
	 * Construct our shipping methods for hooks, etc
	 *
	 * @return void
	 */
	public function shipping_init() {
		foreach ( array_keys( $this->methods ) as $class_name ) {
			$this->methods[ $class_name ] = new $class_name();
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
		// Registered under the method's own id rather than its class name.
		// WooCommerce writes this key into the shipping zone table, so it is
		// what a shop ends up looking at, and it is what the method's settings
		// are stored under - a zone row saying "omniva_parcel_machines_ee"
		// beats one saying "WC_Estonian_Shipping_Method_Omniva_Parcel_Machines_EE".
		// Rows written under the old key are moved across on upgrade.
		foreach ( $this->methods as $class_name => $method ) {
			$methods[ is_object( $method ) ? $method->id : $class_name ] = $class_name;
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

			// The terminal selection is part of the block checkout now, so the
			// shop can be told this plugin belongs there.
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', WC_ESTONIAN_SHIPPING_METHODS_MAIN_FILE, true );
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

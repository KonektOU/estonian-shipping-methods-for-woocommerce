<?php
/**
 * PHPUnit bootstrap.
 *
 * These are unit tests: WordPress is not loaded, its functions are stubbed by
 * Brain Monkey, and only the plugin's framework-free classes are exercised.
 *
 * @package Estonian_Shipping_Methods_For_WooCommerce
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'WC_ESM_PLUGIN_DIR', dirname( __DIR__ ) );

require_once WC_ESM_PLUGIN_DIR . '/vendor/autoload.php';
require_once __DIR__ . '/class-wc-esm-test-case.php';

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * The smallest WP_Error that satisfies the plugin's use of it.
	 */
	class WP_Error {

		/**
		 * Error code.
		 *
		 * @var string
		 */
		public $code;

		/**
		 * Error message.
		 *
		 * @var string
		 */
		public $message;

		/**
		 * Constructor.
		 *
		 * @param string $code    Code.
		 * @param string $message Message.
		 */
		public function __construct( $code = '', $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}

		/**
		 * Error code.
		 *
		 * @return string
		 */
		public function get_error_code() {
			return $this->code;
		}

		/**
		 * Error message.
		 *
		 * @return string
		 */
		public function get_error_message() {
			return $this->message;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	/**
	 * Check whether a variable is a WP_Error.
	 *
	 * @param mixed $thing The variable to check.
	 *
	 * @return bool
	 */
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

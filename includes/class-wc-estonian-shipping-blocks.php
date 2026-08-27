<?php
/**
 * Terminal selection on the block checkout.
 *
 * The dropdown these methods have always shown is printed by
 * woocommerce_review_order_after_shipping, which the block checkout never
 * fires: it renders itself from React and talks to the Store API. On a shop
 * using the block checkout - the default for some years now - there was
 * therefore no way to choose a parcel terminal at all.
 *
 * Same shape as the classic one, by the same route the block checkout gives
 * extensions:
 *
 * - the cart response carries the terminals for the shipping method currently
 *   chosen, so the browser is never sent thousands of terminals it has no use
 *   for, and gets a fresh list whenever the method changes;
 * - the choice goes back through an update callback, which keeps it in the
 *   session exactly where the classic checkout keeps it;
 * - the checkout request carries it too, and that is what is written on the
 *   order - with the session as a fallback, and a refusal if it is missing.
 *
 * @package Estonian_Shipping_Methods_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

class WC_Estonian_Shipping_Blocks {

	/**
	 * Namespace for everything this class registers with the Store API.
	 */
	const IDENTIFIER = 'wc-estonian-shipping-methods';

	/**
	 * @return void
	 */
	public static function init() {
		add_action( 'woocommerce_blocks_loaded', array( __CLASS__, 'register_store_api' ) );
		add_action( 'woocommerce_blocks_loaded', array( __CLASS__, 'register_checkout_block' ) );

		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( __CLASS__, 'save_terminal_on_order' ), 10, 2 );
	}

	/**
	 * Everything the block checkout reads and writes.
	 *
	 * @return void
	 */
	public static function register_store_api() {
		if ( function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
			woocommerce_store_api_register_endpoint_data(
				array(
					'endpoint'        => Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema::IDENTIFIER,
					'namespace'       => self::IDENTIFIER,
					'data_callback'   => array( __CLASS__, 'cart_data' ),
					'schema_callback' => array( __CLASS__, 'cart_schema' ),
					'schema_type'     => ARRAY_A,
				)
			);

			woocommerce_store_api_register_endpoint_data(
				array(
					'endpoint'        => Automattic\WooCommerce\StoreApi\Schemas\V1\CheckoutSchema::IDENTIFIER,
					'namespace'       => self::IDENTIFIER,
					'data_callback'   => array( __CLASS__, 'checkout_data' ),
					'schema_callback' => array( __CLASS__, 'checkout_schema' ),
					'schema_type'     => ARRAY_A,
				)
			);
		}

		if ( function_exists( 'woocommerce_store_api_register_update_callback' ) ) {
			woocommerce_store_api_register_update_callback(
				array(
					'namespace' => self::IDENTIFIER,
					'callback'  => array( __CLASS__, 'update_chosen_terminal' ),
				)
			);
		}
	}

	/**
	 * The terminals for the method the customer has chosen, and nothing else.
	 *
	 * @return array
	 */
	public static function cart_data() {
		$method = self::get_chosen_terminals_method();

		if ( ! $method ) {
			return array(
				'method_id'  => '',
				'field_name' => '',
				'label'      => '',
				'terminals'  => array(),
				'selected'   => '',
			);
		}

		$groups = array();

		foreach ( (array) $method->get_sorted_and_grouped_terminals() as $group_name => $terminals ) {
			$options = array();

			foreach ( (array) $terminals as $terminal ) {
				if ( empty( $terminal->place_id ) ) {
					continue;
				}

				$options[] = array(
					'value' => (string) $terminal->place_id,
					'label' => (string) $terminal->name,
				);
			}

			if ( ! empty( $options ) ) {
				$groups[] = array(
					'label'   => (string) $group_name,
					'options' => $options,
				);
			}
		}

		return array(
			'method_id'  => (string) $method->id,
			'field_name' => (string) $method->field_name,
			'label'      => esc_html__( 'Choose terminal', 'wc-estonian-shipping-methods' ),
			'terminals'  => $groups,
			'selected'   => (string) self::get_session_terminal( $method ),
		);
	}

	/**
	 * @return array
	 */
	public static function cart_schema() {
		return array(
			'method_id'  => array(
				'description' => __( 'Shipping method the terminals belong to.', 'wc-estonian-shipping-methods' ),
				'type'        => 'string',
				'readonly'    => true,
			),
			'field_name' => array(
				'description' => __( 'Name the chosen terminal is stored under.', 'wc-estonian-shipping-methods' ),
				'type'        => 'string',
				'readonly'    => true,
			),
			'label'      => array(
				'description' => __( 'Label for the terminal selection.', 'wc-estonian-shipping-methods' ),
				'type'        => 'string',
				'readonly'    => true,
			),
			'terminals'  => array(
				'description' => __( 'Terminals, grouped the way the shipping method groups them.', 'wc-estonian-shipping-methods' ),
				'type'        => 'array',
				'readonly'    => true,
			),
			'selected'   => array(
				'description' => __( 'Terminal chosen so far.', 'wc-estonian-shipping-methods' ),
				'type'        => 'string',
				'readonly'    => true,
			),
		);
	}

	/**
	 * @return array
	 */
	public static function checkout_data() {
		$method = self::get_chosen_terminals_method();

		return array(
			'terminal_id' => $method ? (string) self::get_session_terminal( $method ) : '',
		);
	}

	/**
	 * @return array
	 */
	public static function checkout_schema() {
		return array(
			'terminal_id' => array(
				'description' => __( 'Chosen terminal.', 'wc-estonian-shipping-methods' ),
				'type'        => array( 'string', 'null' ),
				'readonly'    => false,
			),
		);
	}

	/**
	 * The customer picked a terminal: remember it where the classic checkout
	 * remembers it, so both checkouts and the order review agree.
	 *
	 * @param array $data Data sent by the block.
	 *
	 * @return void
	 */
	public static function update_chosen_terminal( $data ) {
		$method = self::get_chosen_terminals_method();

		if ( ! $method || ! isset( $data['terminal_id'] ) ) {
			return;
		}

		WC()->session->set( $method->field_name, sanitize_text_field( (string) $data['terminal_id'] ) );
	}

	/**
	 * Write the chosen terminal onto the order being placed.
	 *
	 * @param \WC_Order        $order   Order.
	 * @param \WP_REST_Request $request Checkout request.
	 *
	 * @throws \Automattic\WooCommerce\StoreApi\Exceptions\RouteException When no terminal was chosen.
	 *
	 * @return void
	 */
	public static function save_terminal_on_order( $order, $request ) {
		$method = self::get_chosen_terminals_method( $order );

		if ( ! $method ) {
			return;
		}

		$posted = isset( $request['extensions'][ self::IDENTIFIER ]['terminal_id'] )
			? sanitize_text_field( (string) $request['extensions'][ self::IDENTIFIER ]['terminal_id'] )
			: '';

		$terminal_id = '' !== $posted ? $posted : (string) self::get_session_terminal( $method );

		if ( '' === $terminal_id ) {
			throw new Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
				'wc_esm_terminal_required',
				esc_html__( 'Please select a parcel terminal', 'wc-estonian-shipping-methods' ),
				400
			);
		}

		$order->update_meta_data( $method->field_name, $terminal_id );
		$method->store_order_terminal_name( $order, $terminal_id );

		WC()->session->set( $method->field_name, $terminal_id );
	}

	/**
	 * Make the block available to the checkout.
	 *
	 * @return void
	 */
	public static function register_checkout_block() {
		if ( ! interface_exists( 'Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface' ) ) {
			return;
		}

		require_once WC_ESTONIAN_SHIPPING_METHODS_INCLUDES_PATH . '/class-wc-estonian-shipping-blocks-integration.php';

		add_action(
			'woocommerce_blocks_checkout_block_registration',
			function ( $registry ) {
				$registry->register( new WC_Estonian_Shipping_Blocks_Integration() );
			}
		);
	}

	/**
	 * The chosen shipping method, when it is one of ours and has terminals.
	 *
	 * @param \WC_Order|null $order Order, when there is one to read the method off.
	 *
	 * @return \WC_Estonian_Shipping_Method_Terminals|null
	 */
	public static function get_chosen_terminals_method( $order = null ) {
		$rates = array();

		if ( $order instanceof WC_Order ) {
			foreach ( $order->get_shipping_methods() as $item ) {
				$rates[] = $item->get_method_id() . ( $item->get_instance_id() ? ':' . $item->get_instance_id() : '' );
			}
		} elseif ( WC()->session ) {
			$rates = (array) WC()->session->get( 'chosen_shipping_methods', array() );
		}

		foreach ( $rates as $rate ) {
			$parts       = explode( ':', (string) $rate );
			$method_id   = $parts[0];
			$instance_id = isset( $parts[1] ) ? absint( $parts[1] ) : 0;

			$method = $instance_id
				? WC_Shipping_Zones::get_shipping_method( $instance_id )
				: null;

			if ( ! $method ) {
				$methods = WC()->shipping() ? WC()->shipping()->get_shipping_methods() : array();
				$method  = isset( $methods[ $method_id ] ) ? $methods[ $method_id ] : null;
			}

			if ( $method instanceof WC_Estonian_Shipping_Method_Terminals ) {
				return $method;
			}
		}

		return null;
	}

	/**
	 * @param \WC_Estonian_Shipping_Method_Terminals $method Method.
	 *
	 * @return string
	 */
	private static function get_session_terminal( $method ) {
		return WC()->session ? (string) WC()->session->get( $method->field_name, '' ) : '';
	}
}

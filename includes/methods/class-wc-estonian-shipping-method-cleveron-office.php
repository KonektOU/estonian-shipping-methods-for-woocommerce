<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Cleveron Office packrobot shipping method
 *
 * @class     WC_Estonian_Shipping_Method_Cleveron_Office
 * @extends   WC_Estonian_Shipping_Method
 * @category  Shipping Methods
 * @package   Estonian_Shipping_Methods_For_WooCommerce
 */
class WC_Estonian_Shipping_Method_Cleveron_Office extends WC_Estonian_Shipping_Method {

	/**
	 * Class constructor.
	 *
	 * @param integer $instance_id Method instance ID.
	 */
	public function __construct( $instance_id = 0 ) {
		$this->id           = 'cleveron_office';
		$this->method_title = __( 'Cleveron Office', 'wc-estonian-shipping-methods' );
		$this->country      = 'EE';

		// The parent sets supports, loads the settings and fills in the title.
		// Do not override supports here: this is a zone method, configured in
		// the zone's own modal, and adding 'settings' back would ask for a
		// global screen the plugin no longer renders.
		parent::__construct( $instance_id );

		// The phone number is what Cleveron texts the customer on, so it is
		// worth refusing at the checkout rather than at the API.
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate_customer_phone_number' ), 10, 1 );
	}

	/**
	 * Set settings fields.
	 *
	 * Everything is an instance field, because this is a zone method and the
	 * zone modal is the only screen the plugin renders for it. That means the
	 * credentials are repeated once per zone - see the plugin's readme for the
	 * one-zone case this was written for.
	 *
	 * @return void
	 */
	public function init_form_fields() {
		parent::init_form_fields();

		$this->instance_form_fields = array_merge(
			$this->instance_form_fields,
			array(
				'cleveron_api'    => array(
					'title'       => __( 'Cleveron Office', 'wc-estonian-shipping-methods' ),
					'type'        => 'title',
					'description' => __( 'Credentials and the packrobot this zone delivers to. Cleveron issues all of them.', 'wc-estonian-shipping-methods' ),
				),
				'api_url'         => array(
					'title'       => __( 'API URL', 'wc-estonian-shipping-methods' ),
					'type'        => 'text',
					'placeholder' => 'https://office.cleveron.com',
					'default'     => '',
				),
				'api_key'         => array(
					'title'   => __( 'API key', 'wc-estonian-shipping-methods' ),
					'type'    => 'text',
					'default' => '',
				),
				'api_token'       => array(
					'title'   => __( 'API token', 'wc-estonian-shipping-methods' ),
					'type'    => 'password',
					'default' => '',
				),
				'apm_external_id' => array(
					'title'       => __( 'APM external ID', 'wc-estonian-shipping-methods' ),
					'type'        => 'text',
					'description' => __( 'Which packrobot this zone delivers to.', 'wc-estonian-shipping-methods' ),
					'desc_tip'    => true,
					'default'     => '',
				),
				'submit_trigger'  => array(
					'title'       => __( 'Send the order when it becomes', 'wc-estonian-shipping-methods' ),
					'type'        => 'select',
					'default'     => 'processing',
					'description' => __( 'Which order status hands the order over to Cleveron Office.', 'wc-estonian-shipping-methods' ),
					'desc_tip'    => true,
					'options'     => array(
						'processing' => __( 'Processing', 'wc-estonian-shipping-methods' ),
						'completed'  => __( 'Completed', 'wc-estonian-shipping-methods' ),
					),
				),
				'slot_size'       => array(
					'title'   => __( 'Slot size', 'wc-estonian-shipping-methods' ),
					'type'    => 'select',
					'default' => 'XS',
					'options' => array(
						'XS' => 'XS',
						'S'  => 'S',
						'M'  => 'M',
						'L'  => 'L',
						'XL' => 'XL',
					),
				),
				'sms_template'    => array(
					'title'       => __( 'SMS template', 'wc-estonian-shipping-methods' ),
					'type'        => 'text',
					'description' => __( 'Cleveron template name. Leave empty to send no text message.', 'wc-estonian-shipping-methods' ),
					'desc_tip'    => true,
					'default'     => '',
				),
				'email_template'  => array(
					'title'       => __( 'E-mail template', 'wc-estonian-shipping-methods' ),
					'type'        => 'text',
					'description' => __( 'Cleveron template name. Leave empty to send no e-mail.', 'wc-estonian-shipping-methods' ),
					'desc_tip'    => true,
					'default'     => '',
				),
				'description'     => array(
					'title'       => __( 'Description', 'wc-estonian-shipping-methods' ),
					'type'        => 'textarea',
					'description' => __( 'Shown under the shipping methods on the checkout when this one is chosen.', 'wc-estonian-shipping-methods' ),
					'desc_tip'    => true,
					'default'     => '',
				),
			)
		);
	}

	/**
	 * Hooks this method needs outside WooCommerce's shipping init.
	 *
	 * Called from the plugin's own init pass, because an order can change
	 * status in a request that never touches the cart - a REST call, WP-CLI,
	 * or the admin - and the shipping methods are not constructed there.
	 *
	 * @return void
	 */
	public function add_actions() {
		add_action( 'woocommerce_order_status_changed', array( $this, 'maybe_create_external_order' ), 10, 3 );
		add_action( 'wc_esm_' . $this->id . '_create_external_order', array( $this, 'create_external_order' ), 10, 2 );
		add_action( 'woocommerce_review_order_after_shipping', array( $this, 'show_description' ) );
	}

	/**
	 * Show the chosen zone's description under the shipping methods.
	 *
	 * @return void
	 */
	public function show_description() {
		$instance = $this->get_chosen_instance();

		if ( ! $instance ) {
			return;
		}

		$description = $instance->get_option( 'description', '' );

		if ( '' === trim( (string) $description ) ) {
			return;
		}

		do_action( $this->id . '_before_description' );

		wc_get_template( 'checkout/form-shipping-cleveron-office.php', compact( 'description' ) );

		do_action( $this->id . '_after_description' );
	}

	/**
	 * The instance of this method the customer actually chose, if any.
	 *
	 * A method object built for the checkout carries no instance id, so its
	 * own get_option() would answer with the zone-less defaults.
	 *
	 * @return WC_Estonian_Shipping_Method_Cleveron_Office|null
	 */
	protected function get_chosen_instance() {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return null;
		}

		foreach ( (array) WC()->session->get( 'chosen_shipping_methods', array() ) as $chosen ) {
			if ( false === strpos( (string) $chosen, ':' ) ) {
				continue;
			}

			list( $method_id, $instance_id ) = explode( ':', $chosen, 2 );

			if ( $method_id === $this->id ) {
				return new self( absint( $instance_id ) );
			}
		}

		return null;
	}

	/**
	 * Queue the hand-over when an order reaches the configured status.
	 *
	 * @param integer|WC_Order $order      Order or its id.
	 * @param string           $old_status Old order status.
	 * @param string           $new_status New order status.
	 *
	 * @return void
	 */
	public function maybe_create_external_order( $order, $old_status, $new_status ) {
		if ( is_numeric( $order ) ) {
			$order = wc_get_order( $order );
		}

		if ( ! $order || ! $order->has_shipping_method( $this->id ) ) {
			return;
		}

		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}

		foreach ( $order->get_shipping_methods() as $shipping_method ) {
			if ( $shipping_method->get_method_id() !== $this->id ) {
				continue;
			}

			$instance_id = absint( $shipping_method->get_instance_id() );

			if ( ! $instance_id ) {
				continue;
			}

			// The trigger is an instance setting, so it has to be read off the
			// zone this order was actually shipped by, not off $this.
			$instance = new self( $instance_id );

			if ( $new_status !== $instance->get_option( 'submit_trigger', 'processing' ) ) {
				continue;
			}

			// The queue keeps a slow or unreachable Cleveron out of whatever
			// request changed the status - the checkout, most often.
			as_enqueue_async_action(
				'wc_esm_' . $this->id . '_create_external_order',
				array( $order->get_id(), $instance_id ),
				'wc-estonian-shipping-methods',
				true
			);
		}
	}

	/**
	 * Hand one order over to Cleveron Office.
	 *
	 * @param integer $order_id    Order id.
	 * @param integer $instance_id Which zone's settings to use.
	 *
	 * @return void
	 */
	public function create_external_order( $order_id, $instance_id = 0 ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return;
		}

		// Re-point this object at the zone the order was shipped by.
		$this->instance_id = absint( $instance_id );
		$this->init_instance_settings();

		$created = $order->get_date_created();

		$order_data = array(
			'service'          => 'C2C',
			'barcode'          => $order->get_order_number(),
			'destination'      => array(
				'apm' => $this->get_option( 'apm_external_id' ),
			),
			'slotSize'         => $this->get_option( 'slot_size', 'XS' ),
			'phone'            => wc_esm_get_order_billing_phone( $order ),
			'email'            => wc_esm_get_order_billing_email( $order ),
			'changesTimestamp' => $created ? $created->format( DateTime::RFC3339 ) : gmdate( DateTime::RFC3339 ),
			'templates'        => array(),
			'extras'           => array(
				'description' => sprintf( '%s #%s', get_bloginfo( 'name', 'display' ), $order->get_order_number() ),
			),
		);

		foreach ( array( 'sms_template', 'email_template' ) as $template_key ) {
			$template = $this->get_option( $template_key );

			if ( ! empty( $template ) ) {
				$order_data['templates'][] = $template;
			}
		}

		$office_order = apply_filters( 'wc_shipping_' . $this->id . '_order_data', $order_data, wc_esm_get_order_id( $order ) );
		$external_id  = $this->get_external_order_id( $order );

		if ( ! empty( $external_id ) ) {
			$request = $this->make_api_request( $this->get_api_endpoint( 'orders/' . rawurlencode( $external_id ) ), $office_order, 'PUT' );
		} else {
			$request = $this->make_api_request( $this->get_api_endpoint( 'orders' ), $office_order, 'POST' );
		}

		if ( is_wp_error( $request ) ) {
			/* translators: %1$s method title, %2$s the error. */
			$order->add_order_note( sprintf( __( '%1$s: order could not be sent. %2$s', 'wc-estonian-shipping-methods' ), $this->get_title(), $request->get_error_message() ) );

			$this->debug( $request->get_error_message() );

			return;
		}

		$code     = (int) wp_remote_retrieve_response_code( $request );
		$response = json_decode( wp_remote_retrieve_body( $request ) );
		$id       = isset( $response->id ) ? $response->id : '';

		if ( 201 === $code && '' !== $id ) {
			do_action( 'wc_shipping_' . $this->id . '_order_created', $response, wc_esm_get_order_id( $order ) );

			/* translators: %1$s method title, %2$s order ID */
			$order->add_order_note( sprintf( __( '%1$s: order created with ID %2$s.', 'wc-estonian-shipping-methods' ), $this->get_title(), $id ) );

			$order->update_meta_data( $this->id . '_order_id', $id );
			$order->save();

			return;
		}

		if ( 200 === $code ) {
			do_action( 'wc_shipping_' . $this->id . '_order_updated', $response, wc_esm_get_order_id( $order ) );

			/* translators: %1$s method title, %2$s order ID */
			$order->add_order_note( sprintf( __( '%1$s: order updated with ID %2$s.', 'wc-estonian-shipping-methods' ), $this->get_title(), '' !== $id ? $id : $external_id ) );

			return;
		}

		/* translators: %1$s method title, %2$d HTTP status code. */
		$order->add_order_note( sprintf( __( '%1$s: order creation failed (HTTP %2$d).', 'wc-estonian-shipping-methods' ), $this->get_title(), $code ) );

		$this->debug( $request );
	}

	/**
	 * The external order id kept on the order.
	 *
	 * @param WC_Order $order Order.
	 *
	 * @return string
	 */
	public function get_external_order_id( $order ) {
		return (string) $order->get_meta( $this->id . '_order_id' );
	}

	/**
	 * Make one request to the Cleveron API.
	 *
	 * @param string $url    Full URL.
	 * @param array  $data   Body.
	 * @param string $method HTTP method.
	 *
	 * @return array|WP_Error
	 */
	public function make_api_request( $url, $data = array(), $method = 'POST' ) {
		return wp_remote_request(
			$url,
			array(
				'method'  => $method,
				'timeout' => 30,
				'body'    => wp_json_encode( $data ),
				'headers' => array(
					'Content-Type'        => 'application/json',
					'Cleveron-Api-Key'    => $this->get_option( 'api_key' ),
					'Cleveron-User-Token' => $this->get_option( 'api_token' ),
				),
			)
		);
	}

	/**
	 * Build a full API URL.
	 *
	 * @param string $endpoint Endpoint below integration/v2/.
	 *
	 * @return string
	 */
	public function get_api_endpoint( $endpoint ) {
		return trailingslashit( $this->get_option( 'api_url' ) ) . 'integration/v2/' . $endpoint;
	}
}

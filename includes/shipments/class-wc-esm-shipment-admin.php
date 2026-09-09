<?php
/**
 * Send it, print it, track it, from the order.
 *
 * @package Estonian_Shipping_Methods_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What a shopkeeper can do with one order, and with a screen of them.
 *
 * Which buttons appear is a decision, not a template detail, so it lives in
 * available_actions() where it can be tested. Every button is gated on the
 * carrier actually offering the thing: a print button on a carrier that
 * prints no labels is a button that fails when pressed, and a tracking link
 * with no URL behind it opens the page the shopkeeper is already on.
 */
class WC_ESM_Shipment_Admin {

	const ACTION = 'wc_esm_order_action';

	/**
	 * Hook up.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_action' ) );
		add_filter( 'bulk_actions-edit-shop_order', array( __CLASS__, 'add_bulk_actions' ) );
		add_filter( 'bulk_actions-woocommerce_page_wc-orders', array( __CLASS__, 'add_bulk_actions' ) );
		add_filter( 'handle_bulk_actions-edit-shop_order', array( __CLASS__, 'handle_bulk_action' ), 10, 3 );
		add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders', array( __CLASS__, 'handle_bulk_action' ), 10, 3 );
	}

	/**
	 * What can be done with this order right now.
	 *
	 * @param WC_Order                      $order    Order.
	 * @param WC_ESM_Shipment_Registry|null $registry Registry, defaulting to the singleton.
	 *
	 * @return array Any of: register, label, track.
	 */
	public static function available_actions( $order, $registry = null ) {
		$registry = $registry ? $registry : WC_ESM_Shipment_Registry::instance();
		$provider = null;

		foreach ( $order->get_shipping_methods() as $item ) {
			$provider = $registry->provider_for_method( $item->get_method_id() );

			if ( $provider ) {
				break;
			}
		}

		if ( ! $provider ) {
			// Somebody else's order. Not ours to offer anything on.
			return array();
		}

		if ( ! WC_ESM_Shipment::is_registered( $order ) ) {
			return array( 'register' );
		}

		$actions = array();

		if ( $provider->supports( 'labels' ) ) {
			$actions[] = 'label';
		}

		if ( $provider->supports( 'tracking' ) ) {
			$barcodes = WC_ESM_Shipment::barcodes( $order );
			$barcode  = $barcodes ? reset( $barcodes ) : '';

			// A carrier can declare tracking and still have nothing to track
			// for this parcel - no barcode, or none it recognises.
			if ( '' !== $barcode && '' !== $provider->get_tracking_url( $barcode ) ) {
				$actions[] = 'track';
			}
		}

		return $actions;
	}

	/**
	 * The metabox on the order screen.
	 *
	 * @return void
	 */
	public static function add_meta_box() {
		$screen = class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' ) && wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled()
			? wc_get_page_screen_id( 'shop-order' )
			: 'shop_order';

		add_meta_box(
			'wc-esm-shipment',
			__( 'Shipment', 'wc-estonian-shipping-methods' ),
			array( __CLASS__, 'render_meta_box' ),
			$screen,
			'side'
		);
	}

	/**
	 * What the metabox shows.
	 *
	 * @param mixed $post_or_order Post or order.
	 *
	 * @return void
	 */
	public static function render_meta_box( $post_or_order ) {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );

		if ( ! $order ) {
			return;
		}

		$actions  = self::available_actions( $order );
		$barcodes = WC_ESM_Shipment::barcodes( $order );
		$error    = WC_ESM_Shipment::error( $order );

		if ( ! $actions && ! $barcodes ) {
			echo '<p>' . esc_html__( 'This order is not shipped by a carrier this plugin handles.', 'wc-estonian-shipping-methods' ) . '</p>';

			return;
		}

		if ( $barcodes ) {
			echo '<p>' . esc_html__( 'Barcode:', 'wc-estonian-shipping-methods' ) . ' ' . esc_html( implode( ', ', $barcodes ) ) . '</p>';
		}

		if ( '' !== $error ) {
			echo '<p class="wc-esm-error"><strong>' . esc_html__( 'Last attempt failed:', 'wc-estonian-shipping-methods' ) . '</strong> ' . esc_html( $error ) . '</p>';
		}

		$labels = array(
			'register' => __( 'Send to the carrier', 'wc-estonian-shipping-methods' ),
			'label'    => __( 'Print the label', 'wc-estonian-shipping-methods' ),
		);

		echo '<form method="post">';
		wp_nonce_field( self::ACTION );
		echo '<input type="hidden" name="order_id" value="' . esc_attr( $order->get_id() ) . '" />';

		foreach ( $actions as $action ) {
			if ( isset( $labels[ $action ] ) ) {
				printf(
					'<button type="submit" class="button" name="%s" value="%s">%s</button> ',
					esc_attr( self::ACTION ),
					esc_attr( $action ),
					esc_html( $labels[ $action ] )
				);
			}
		}

		echo '</form>';

		if ( in_array( 'track', $actions, true ) ) {
			$provider = WC_ESM_Shipment_Registration::provider_for( $order );
			$barcode  = reset( $barcodes );

			printf(
				'<p><a href="%s" target="_blank" rel="noopener noreferrer">%s</a></p>',
				esc_url( $provider->get_tracking_url( $barcode ) ),
				esc_html__( 'Follow the parcel', 'wc-estonian-shipping-methods' )
			);
		}
	}

	/**
	 * Do what a button asked for.
	 *
	 * @return void
	 */
	public static function handle_action() {
		if ( empty( $_POST[ self::ACTION ] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) || ! wp_verify_nonce( wc_get_var( $_POST['_wpnonce'], '' ), self::ACTION ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'wc-estonian-shipping-methods' ) );
		}

		$order = wc_get_order( absint( wc_get_var( $_POST['order_id'], 0 ) ) );

		if ( ! $order ) {
			return;
		}

		$what = sanitize_key( wp_unslash( $_POST[ self::ACTION ] ) );

		// A request is not bound by what the screen chose to draw, so what is
		// allowed is decided here rather than trusted from the post.
		if ( ! in_array( $what, self::available_actions( $order ), true ) ) {
			return;
		}

		if ( 'register' === $what ) {
			WC_ESM_Shipment_Registration::register_order( $order->get_id() );

			return;
		}

		if ( 'label' === $what ) {
			$outcome = WC_ESM_Shipment_Labels::collect( array( $order ), WC_ESM_Shipment_Registry::instance() );

			if ( '' !== $outcome['pdf'] ) {
				WC_ESM_Shipment_Labels::stream( $outcome['pdf'], 'label-' . $order->get_order_number() . '.pdf' );
			}
		}
	}

	/**
	 * Add the bulk actions to the orders list.
	 *
	 * @param array $actions Actions.
	 *
	 * @return array
	 */
	public static function add_bulk_actions( $actions ) {
		$actions['wc_esm_print_labels'] = __( 'Print shipping labels', 'wc-estonian-shipping-methods' );

		return $actions;
	}

	/**
	 * Print a screen of orders.
	 *
	 * @param string $redirect_to Where to go afterwards.
	 * @param string $action      Which bulk action.
	 * @param array  $ids         Order ids.
	 *
	 * @return string
	 */
	public static function handle_bulk_action( $redirect_to, $action, $ids ) {
		if ( 'wc_esm_print_labels' !== $action ) {
			return $redirect_to;
		}

		$orders = array_filter( array_map( 'wc_get_order', array_map( 'absint', (array) $ids ) ) );

		// Bulk printing registers first: there is nothing to print for an
		// order the carrier has never seen, and a shopkeeper who ticked forty
		// orders means to send them.
		foreach ( $orders as $order ) {
			if ( ! WC_ESM_Shipment::is_registered( $order ) ) {
				WC_ESM_Shipment_Registration::register_order( $order->get_id() );
			}
		}

		$orders  = array_filter( array_map( 'wc_get_order', array_map( 'absint', (array) $ids ) ) );
		$outcome = WC_ESM_Shipment_Labels::collect( $orders, WC_ESM_Shipment_Registry::instance() );

		if ( '' !== $outcome['pdf'] ) {
			WC_ESM_Shipment_Labels::stream( $outcome['pdf'] );
		}

		return add_query_arg(
			array(
				'wc_esm_printed'  => (int) $outcome['printed'],
				'wc_esm_problems' => rawurlencode( implode( ' ', $outcome['problems'] ) ),
			),
			$redirect_to
		);
	}
}

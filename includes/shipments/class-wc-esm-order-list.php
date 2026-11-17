<?php
/**
 * The shipment, at a glance, in the orders list.
 *
 * @package Estonian_Shipping_Methods_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A "Shipment" column, a print or send button per row, and a filter.
 *
 * Everything shown is read from what the order already carries - its meta and
 * the registration queue - so a screen of orders costs no carrier a request.
 * The state is worked out in one place, state(), and the filter asks the
 * database the same questions in the same order of precedence, so a filtered
 * list never holds a row whose column says something else.
 *
 * The order's own WooCommerce status is not touched by any of it.
 */
class WC_ESM_Order_List {

	const COLUMN = 'wc_esm_shipment';
	const ACTION = 'wc_esm_list_action';
	const FILTER = 'wc_esm_shipment';

	/**
	 * Hook up, for both the posts-based and the HPOS orders screen.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'manage_edit-shop_order_columns', array( __CLASS__, 'add_column' ), 20 );
		add_action( 'manage_shop_order_posts_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( __CLASS__, 'add_column' ), 20 );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );

		add_filter( 'woocommerce_admin_order_actions', array( __CLASS__, 'add_row_actions' ), 10, 2 );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_row_action' ) );

		add_action( 'restrict_manage_posts', array( __CLASS__, 'render_filter_for_posts' ) );
		add_action( 'woocommerce_order_list_table_restrict_manage_orders', array( __CLASS__, 'render_filter' ) );
		add_filter( 'request', array( __CLASS__, 'filter_posts_request' ) );
		add_filter( 'woocommerce_order_list_table_prepare_items_query_args', array( __CLASS__, 'filter_hpos_query' ) );

		add_action( 'admin_head', array( __CLASS__, 'print_styles' ) );
		add_action( 'admin_notices', array( __CLASS__, 'show_print_problems' ) );
	}

	/**
	 * What each state is called, in the order the filter offers them.
	 *
	 * @return array
	 */
	public static function states() {
		return array(
			'not_sent' => __( 'Not sent', 'wc-estonian-shipping-methods' ),
			'queued'   => __( 'Queued', 'wc-estonian-shipping-methods' ),
			'failed'   => __( 'Failed', 'wc-estonian-shipping-methods' ),
			'sent'     => __( 'Registered', 'wc-estonian-shipping-methods' ),
			'printed'  => __( 'Label printed', 'wc-estonian-shipping-methods' ),
			WC_ESM_Delivery::IN_TRANSIT          => __( 'In transit', 'wc-estonian-shipping-methods' ),
			WC_ESM_Delivery::AWAITING_COLLECTION => __( 'Awaiting collection', 'wc-estonian-shipping-methods' ),
			WC_ESM_Delivery::DELIVERED           => __( 'Delivered', 'wc-estonian-shipping-methods' ),
			WC_ESM_Delivery::RETURNING           => __( 'Returning to sender', 'wc-estonian-shipping-methods' ),
			WC_ESM_Delivery::RETURNED            => __( 'Returned to sender', 'wc-estonian-shipping-methods' ),
		);
	}

	/**
	 * Where this order's shipment stands.
	 *
	 * Sent outranks everything: once the carrier has the parcel, an old
	 * error or a stray queue entry is history, and what the carrier says
	 * about the parcel outranks whether its label was printed. A retry
	 * waiting in the queue outranks the failure it is retrying.
	 *
	 * @param WC_Order                      $order    Order.
	 * @param WC_ESM_Shipment_Registry|null $registry Registry, defaulting to the singleton.
	 * @param bool|callable|null            $queued   Whether it is queued, or how to find out.
	 *
	 * @return string '' when the order is not ours, else a key of states().
	 */
	public static function state( $order, $registry = null, $queued = null ) {
		$registry = $registry ? $registry : WC_ESM_Shipment_Registry::instance();

		if ( ! $registry->provider_for_order( $order ) ) {
			return '';
		}

		if ( WC_ESM_Shipment::is_registered( $order ) ) {
			// Once the carrier reports on the parcel, that is the news.
			$delivery = WC_ESM_Delivery::state( $order );

			if ( '' !== $delivery ) {
				return $delivery;
			}

			return WC_ESM_Shipment::label_printed( $order ) ? 'printed' : 'sent';
		}

		// Asked only now, because it is a query per row.
		if ( null === $queued ) {
			$queued = array( __CLASS__, 'is_queued' );
		}

		if ( is_callable( $queued ) ? call_user_func( $queued, $order ) : $queued ) {
			return 'queued';
		}

		return '' !== WC_ESM_Shipment::error( $order ) ? 'failed' : 'not_sent';
	}

	/**
	 * The buttons a row offers.
	 *
	 * The same decision the order screen makes, minus what belongs in the
	 * column (tracking), and minus sending while the queue is already about
	 * to: two sends racing to the carrier is two parcels.
	 *
	 * @param WC_Order                      $order    Order.
	 * @param WC_ESM_Shipment_Registry|null $registry Registry.
	 * @param bool|callable|null            $queued   Whether it is queued.
	 *
	 * @return array Any of: label, register.
	 */
	public static function actions( $order, $registry = null, $queued = null ) {
		$state = self::state( $order, $registry, $queued );

		if ( '' === $state || 'queued' === $state ) {
			return array();
		}

		return array_values( array_intersect( array( 'label', 'register' ), WC_ESM_Shipment_Admin::available_actions( $order, $registry ) ) );
	}

	/**
	 * Whether the registration queue is holding this order.
	 *
	 * @param WC_Order $order Order.
	 *
	 * @return bool
	 */
	public static function is_queued( $order ) {
		return function_exists( 'as_has_scheduled_action' )
			&& as_has_scheduled_action( WC_ESM_Shipment_Registration::HOOK, array( 'order_id' => (int) $order->get_id() ), WC_ESM_Shipment_Registration::GROUP );
	}

	/**
	 * Put the column after the order status.
	 *
	 * @param array $columns Columns.
	 *
	 * @return array
	 */
	public static function add_column( $columns ) {
		$title = __( 'Shipment', 'wc-estonian-shipping-methods' );

		if ( ! isset( $columns['order_status'] ) ) {
			$columns[ self::COLUMN ] = $title;

			return $columns;
		}

		$out = array();

		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;

			if ( 'order_status' === $key ) {
				$out[ self::COLUMN ] = $title;
			}
		}

		return $out;
	}

	/**
	 * Fill the column.
	 *
	 * @param string       $column            Column.
	 * @param WC_Order|int $order_or_order_id The order, or on the posts screen its id.
	 *
	 * @return void
	 */
	public static function render_column( $column, $order_or_order_id ) {
		if ( self::COLUMN !== $column ) {
			return;
		}

		$order = $order_or_order_id instanceof WC_Order ? $order_or_order_id : wc_get_order( $order_or_order_id );
		$state = $order ? self::state( $order ) : '';

		if ( '' === $state ) {
			echo '<span class="na">&ndash;</span>';

			return;
		}

		// WooCommerce's own status badges, so the column reads like the one
		// beside it and needs no colours of its own.
		$badges = array(
			'not_sent' => 'status-pending',
			'queued'   => 'status-on-hold',
			'failed'   => 'status-failed',
			'sent'     => 'status-processing',
			'printed'  => 'status-processing',
			WC_ESM_Delivery::IN_TRANSIT          => 'status-processing',
			WC_ESM_Delivery::AWAITING_COLLECTION => 'status-on-hold',
			WC_ESM_Delivery::DELIVERED           => 'status-completed',
			WC_ESM_Delivery::RETURNING           => 'status-failed',
			WC_ESM_Delivery::RETURNED            => 'status-failed',
		);
		$states = self::states();
		$title  = 'failed' === $state ? WC_ESM_Shipment::error( $order ) : '';

		printf(
			'<mark class="order-status %s"%s><span>%s</span></mark>',
			esc_attr( $badges[ $state ] ),
			'' !== $title ? ' title="' . esc_attr( $title ) . '"' : '',
			esc_html( $states[ $state ] )
		);
	}

	/**
	 * The print or send button in the row's Actions column.
	 *
	 * @param array    $actions Actions WooCommerce already offers.
	 * @param WC_Order $order   Order.
	 *
	 * @return array
	 */
	public static function add_row_actions( $actions, $order ) {
		$names = array(
			'label'    => __( 'Print shipping label', 'wc-estonian-shipping-methods' ),
			'register' => __( 'Send to the carrier', 'wc-estonian-shipping-methods' ),
		);

		foreach ( self::actions( $order ) as $action ) {
			$actions[ 'wc_esm_' . $action ] = array(
				'url'    => self::action_url( $action, $order->get_id() ),
				'name'   => $names[ $action ],
				'action' => 'wc_esm_' . $action,
			);
		}

		return $actions;
	}

	/**
	 * Where a row button goes.
	 *
	 * @param string $action   label or register.
	 * @param int    $order_id Order id.
	 *
	 * @return string
	 */
	public static function action_url( $action, $order_id ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'   => self::ACTION,
					'do'       => $action,
					'order_id' => (int) $order_id,
				),
				admin_url( 'admin-post.php' )
			),
			self::ACTION . '_' . (int) $order_id
		);
	}

	/**
	 * Do what a row button asked for.
	 *
	 * @return void
	 */
	public static function handle_row_action() {
		$order_id = absint( wc_get_var( $_GET['order_id'], 0 ) );

		check_admin_referer( self::ACTION . '_' . $order_id );

		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'wc-estonian-shipping-methods' ) );
		}

		$order = wc_get_order( $order_id );
		$what  = sanitize_key( wc_get_var( $_GET['do'], '' ) );
		$back  = wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=wc-orders' );

		// A link can be replayed long after the row that drew it changed, so
		// what is allowed is decided again here.
		if ( ! $order || ! in_array( $what, self::actions( $order ), true ) ) {
			wp_safe_redirect( $back );
			exit;
		}

		if ( 'register' === $what ) {
			WC_ESM_Shipment_Registration::register_order( $order->get_id() );
			wp_safe_redirect( $back );
			exit;
		}

		$outcome = WC_ESM_Shipment_Labels::collect( array( $order ), WC_ESM_Shipment_Registry::instance() );

		if ( '' !== $outcome['pdf'] ) {
			WC_ESM_Shipment_Labels::stream( $outcome['pdf'], 'label-' . $order->get_order_number() . '.pdf' );
		}

		wp_safe_redirect( add_query_arg( 'wc_esm_problems', rawurlencode( implode( ' ', $outcome['problems'] ) ), $back ) );
		exit;
	}

	/**
	 * Say what could not be printed, after a row button or the bulk action.
	 *
	 * @return void
	 */
	public static function show_print_problems() {
		$problems = isset( $_GET['wc_esm_problems'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['wc_esm_problems'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.

		if ( '' === $problems || ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}

		printf( '<div class="notice notice-error is-dismissible"><p>%s</p></div>', esc_html( $problems ) );
	}

	/**
	 * The filter dropdown on the posts-based orders screen.
	 *
	 * @param string $post_type Post type of the screen.
	 *
	 * @return void
	 */
	public static function render_filter_for_posts( $post_type ) {
		if ( 'shop_order' === $post_type ) {
			self::render_filter();
		}
	}

	/**
	 * The filter dropdown.
	 *
	 * @return void
	 */
	public static function render_filter() {
		$chosen = self::chosen_filter();

		echo '<select name="' . esc_attr( self::FILTER ) . '">';
		echo '<option value="">' . esc_html__( 'All shipments', 'wc-estonian-shipping-methods' ) . '</option>';

		foreach ( self::states() as $key => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $key ), selected( $chosen, $key, false ), esc_html( $label ) );
		}

		echo '</select>';
	}

	/**
	 * The state the list is filtered to, if any.
	 *
	 * @return string
	 */
	public static function chosen_filter() {
		$chosen = isset( $_GET[ self::FILTER ] ) ? sanitize_key( wp_unslash( $_GET[ self::FILTER ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a list filter.

		return array_key_exists( $chosen, self::states() ) ? $chosen : '';
	}

	/**
	 * Narrow the posts-based orders screen.
	 *
	 * @param array $vars Query vars.
	 *
	 * @return array
	 */
	public static function filter_posts_request( $vars ) {
		global $typenow;

		if ( 'shop_order' !== $typenow || ! is_admin() || '' === self::chosen_filter() ) {
			return $vars;
		}

		$vars['post__in'] = self::ids_or_none( self::filtered_ids( self::chosen_filter() ) );

		return $vars;
	}

	/**
	 * Narrow the HPOS orders screen.
	 *
	 * @param array $args wc_get_orders() arguments.
	 *
	 * @return array
	 */
	public static function filter_hpos_query( $args ) {
		if ( '' !== self::chosen_filter() ) {
			$args['id'] = self::ids_or_none( self::filtered_ids( self::chosen_filter() ) );
		}

		return $args;
	}

	/**
	 * An id list a query cannot read as "no restriction".
	 *
	 * Both post__in and an id argument treat an empty list as no filter at
	 * all, which would show every order under a filter that matched none.
	 *
	 * @param array $ids Ids.
	 *
	 * @return array
	 */
	public static function ids_or_none( $ids ) {
		return $ids ? array_values( $ids ) : array( 0 );
	}

	/**
	 * The ids of the orders in one state.
	 *
	 * @param string $state A key of states().
	 *
	 * @return array
	 */
	public static function filtered_ids( $state ) {
		return self::ids_in_state(
			$state,
			array(
				'ours'       => self::orders_shipped_by( self::carried_method_ids() ),
				'registered' => array_unique( array_merge( self::orders_with_meta( WC_ESM_Shipment::LABEL_REFS ), self::orders_with_meta( WC_ESM_Shipment::BARCODES ) ) ),
				'printed'    => self::orders_with_meta( WC_ESM_Shipment::LABEL_PRINTED ),
				'failed'     => self::orders_with_meta( WC_ESM_Shipment::ERROR ),
				'queued'     => self::queued_order_ids(),
				'delivery'   => self::orders_by_meta_value( WC_ESM_Delivery::STATE ),
			)
		);
	}

	/**
	 * Which orders are in a state, given who is in which set.
	 *
	 * The database half finds the sets; this half applies state()'s order
	 * of precedence to them, and is what the tests check.
	 *
	 * @param string $state A key of states().
	 * @param array  $sets  Order ids under ours, registered, printed, failed,
	 *                      queued; and delivery, as order id => state.
	 *
	 * @return array
	 */
	public static function ids_in_state( $state, $sets ) {
		// Only a state the column would show counts; anything else stored
		// there reads as no report, as it does in state().
		$delivery = array_intersect( isset( $sets['delivery'] ) ? (array) $sets['delivery'] : array(), WC_ESM_Delivery::states() );
		$sets     = array_map(
			static function ( $ids ) {
				return array_map( 'intval', (array) $ids );
			},
			array_diff_key( array_merge( array_fill_keys( array( 'ours', 'registered', 'printed', 'failed', 'queued' ), array() ), $sets ), array( 'delivery' => true ) )
		);

		$ours       = $sets['ours'];
		$registered = array_intersect( $ours, $sets['registered'] );
		$reported   = array_intersect( $registered, array_map( 'intval', array_keys( $delivery ) ) );
		$unsent     = array_diff( $ours, $registered );
		$queued     = array_intersect( $unsent, $sets['queued'] );

		switch ( $state ) {
			case 'printed':
				$ids = array_diff( array_intersect( $registered, $sets['printed'] ), $reported );
				break;
			case 'sent':
				$ids = array_diff( $registered, $sets['printed'], $reported );
				break;
			case WC_ESM_Delivery::IN_TRANSIT:
			case WC_ESM_Delivery::AWAITING_COLLECTION:
			case WC_ESM_Delivery::DELIVERED:
			case WC_ESM_Delivery::RETURNING:
			case WC_ESM_Delivery::RETURNED:
				$ids = array_intersect( $reported, array_map( 'intval', array_keys( $delivery, $state, true ) ) );
				break;
			case 'queued':
				$ids = $queued;
				break;
			case 'failed':
				$ids = array_diff( array_intersect( $unsent, $sets['failed'] ), $queued );
				break;
			case 'not_sent':
				$ids = array_diff( $unsent, $queued, $sets['failed'] );
				break;
			default:
				$ids = array();
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Every shipping method id one of our carriers carries.
	 *
	 * @return array
	 */
	protected static function carried_method_ids() {
		$registry = WC_ESM_Shipment_Registry::instance();

		return array_values(
			array_filter(
				array_keys( WC()->shipping()->get_shipping_methods() ),
				static function ( $method_id ) use ( $registry ) {
					return null !== $registry->provider_for_method( $method_id );
				}
			)
		);
	}

	/**
	 * Orders with a shipping line by one of these methods.
	 *
	 * @param array $method_ids Method ids.
	 *
	 * @return array
	 */
	protected static function orders_shipped_by( $method_ids ) {
		global $wpdb;

		if ( ! $method_ids ) {
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $method_ids ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built above.
		return $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT items.order_id
				FROM {$wpdb->prefix}woocommerce_order_items items
				INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta meta ON meta.order_item_id = items.order_item_id
				WHERE items.order_item_type = 'shipping' AND meta.meta_key = 'method_id' AND meta.meta_value IN ( $placeholders )",
				$method_ids
			)
		);
	}

	/**
	 * Orders carrying a non-empty value under a meta key.
	 *
	 * @param string $key Meta key.
	 *
	 * @return array
	 */
	protected static function orders_with_meta( $key ) {
		global $wpdb;

		$hpos = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

		// An empty list is stored as a serialised empty array, which is not
		// a barcode.
		if ( $hpos ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT order_id FROM {$wpdb->prefix}wc_orders_meta WHERE meta_key = %s AND meta_value NOT IN ( '', 'a:0:{}' )", $key ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value NOT IN ( '', 'a:0:{}' )", $key ) );
	}

	/**
	 * Every order's value under a meta key, as order id => value.
	 *
	 * @param string $key Meta key.
	 *
	 * @return array
	 */
	protected static function orders_by_meta_value( $key ) {
		global $wpdb;

		$hpos  = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		$query = $hpos
			? "SELECT order_id AS id, meta_value AS value FROM {$wpdb->prefix}wc_orders_meta WHERE meta_key = %s"
			: "SELECT post_id AS id, meta_value AS value FROM {$wpdb->postmeta} WHERE meta_key = %s";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- the query is one of two fixed strings.
		$rows = $wpdb->get_results( $wpdb->prepare( $query, $key ) );

		return wp_list_pluck( (array) $rows, 'value', 'id' );
	}

	/**
	 * Orders waiting in the registration queue.
	 *
	 * @return array
	 */
	protected static function queued_order_ids() {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return array();
		}

		$ids = array();

		foreach ( as_get_scheduled_actions(
			array(
				'hook'     => WC_ESM_Shipment_Registration::HOOK,
				'group'    => WC_ESM_Shipment_Registration::GROUP,
				'status'   => 'pending',
				'per_page' => -1,
			)
		) as $action ) {
			$args = $action->get_args();

			if ( ! empty( $args['order_id'] ) ) {
				$ids[] = (int) $args['order_id'];
			}
		}

		return $ids;
	}

	/**
	 * Icons for the row buttons, on the orders screens only.
	 *
	 * WooCommerce draws each action as an icon named after it, and knows
	 * nothing of ours.
	 *
	 * @return void
	 */
	public static function print_styles() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || ! in_array( $screen->id, array( 'edit-shop_order', 'woocommerce_page_wc-orders' ), true ) ) {
			return;
		}

		echo '<style>
			.wc-action-button-wc_esm_label::after, .wc-action-button-wc_esm_register::after { font-family: dashicons !important; }
			.wc-action-button-wc_esm_label::after { content: "\f193" !important; }
			.wc-action-button-wc_esm_register::after { content: "\f317" !important; }
			.column-wc_esm_shipment { width: 11ch; }
		</style>';
	}
}

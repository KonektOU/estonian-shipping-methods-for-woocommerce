<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Abstract class for all of our shipping methods
 *
 * @class     WC_Estonian_Shipping_Method_Terminals
 * @extends   WC_Shipping_Method
 * @category  Shipping Methods
 * @package   Estonian_Shipping_Methods_For_WooCommerce
 */
abstract class WC_Estonian_Shipping_Method_Terminals extends WC_Estonian_Shipping_Method {

	/**
	 * Template file name for dropdown selection in checkout
	 * @var string
	 */
	public $terminals_template = '';

	/**
	 * Country currently being used
	 * @var null
	 */
	public $terminals_country = null;

	/**
	 * Terminals
	 * @var array
	 */
	public $terminals = array();

	/**
	 * __construct function.
	 *
	 * @access public
	 * @return void
	 */
	public function __construct() {
		// Meta and input field name.
		$this->field_name = apply_filters( 'wc_shipping_' . $this->id . '_terminals_field_name', 'wc_shipping_' . $this->id . '_terminal' );

		// i18n.
		$this->i18n_selected_terminal = esc_html__( 'Chosen terminal', 'wc-estonian-shipping-methods' );

		// Construct parent.
		parent::__construct();

		// Add/merge form fields.
		$this->add_form_fields();
	}

	/**
	 * Add hooks even when shipping might not be inited. Adds compatibility with lots of plugins.
	 *
	 * @return void
	 */
	public function add_terminals_hooks() {
		// Show selected terminal in order and emails.
		add_action( 'woocommerce_order_details_after_customer_details', array( $this, 'show_selected_terminal' ), 10, 1 );
		add_action( 'woocommerce_email_customer_details', array( $this, 'show_selected_terminal' ), 15, 1 );

		// WooCommerce PDF Invoices & Packing Slips.
		add_action( 'wpo_wcpdf_after_order_data', array( $this, 'wpo_wcpdf_show_selected_terminal' ), 10, 2 );

		// Custom locations.
		add_action( 'wc_estonian_shipping_method_show_terminal', array( $this, 'show_selected_terminal' ), 10, 1 );
		add_filter( 'wc_estonian_shipping_method_order_terminal_name', array( $this, 'add_order_terminal_name' ), 10, 2 );

		// Show selected terminal in admin order review
		// and since WC 3.3.0 in order preview.
		add_action( 'woocommerce_admin_order_data_after_shipping_address', array( $this, 'show_selected_terminal' ), 20 );
		add_filter( 'woocommerce_admin_order_preview_get_order_details', array( $this, 'show_selected_terminal_in_order_preview' ), 20, 2 );

		// Add terminal selection dropdown and save it.
		add_action( 'woocommerce_review_order_after_shipping', array( $this, 'review_order_after_shipping' ) );
		add_action( 'woocommerce_checkout_update_order_meta', array( $this, 'checkout_save_order_terminal_id_meta' ), 10, 2 );
		add_action( 'woocommerce_checkout_update_order_review', array( $this, 'checkout_save_session_terminal_id' ), 10, 1 );

		// Checkout validation.
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate_user_selected_terminal' ), 10, 1 );
	}

	public function add_form_fields() {
		$this->form_fields = array_merge(
			$this->form_fields,
			array(
				'terminals_format' => array(
					'title'   => __( 'Terminals format', 'wc-estonian-shipping-methods' ),
					'type'    => 'select',
					'default' => 'name',
					'options' => array(
						'name'         => __( 'Only terminal name', 'wc-estonian-shipping-methods' ),
						'with_address' => __( 'Name with address', 'wc-estonian-shipping-methods' ),
					),
				),
				'sort_terminals' => array(
					'title'   => __( 'Sort terminals by', 'wc-estonian-shipping-methods' ),
					'type'    => 'select',
					'default' => 'alphabetically',
					'options' => array(
						'none'           => __( 'No sorting', 'wc-estonian-shipping-methods' ),
						'alphabetically' => __( 'Alphabetically', 'wc-estonian-shipping-methods' ),
						'cities_first'   => __( 'Bigger cities first, then alphabetically the rest', 'wc-estonian-shipping-methods' ),
					),
				),
				'group_terminals' => array(
					'title'   => __( 'Group terminals', 'wc-estonian-shipping-methods' ),
					'type'    => 'select',
					'default' => 'cities',
					'options' => array(
						'cities' => __( 'By cities', 'wc-estonian-shipping-methods' ),
					),
				),
			)
		);
	}

	/**
	 * Adds dropdown selection of terminals right after shipping in checkout
	 * @return void
	 */
	function review_order_after_shipping() {
		// Get currently selected shipping methods
		$chosen_shipping_methods = WC()->session->get( 'chosen_shipping_methods' );

		// Check if ours is one of the selected methods
		if ( ! empty( $chosen_shipping_methods ) && in_array( $this->id, $chosen_shipping_methods ) ) {
			// Get selected terminal
			$selected_terminal   = WC()->session->get( $this->field_name );

			// Set data for terminals template
			$template_data = array(
				'terminals'  => $this->get_sorted_and_grouped_terminals(),
				'field_name' => $this->field_name,
				'field_id'   => $this->field_name,
				'selected'   => $selected_terminal ? $selected_terminal : '',
			);

			// Allow to do some activity before terminals
			do_action( $this->id . '_before_terminals' );

			// Get terminals template
			wc_get_template( 'checkout/form-shipping-' . $this->terminals_template . '.php', $template_data );

			// Allow to do some activity after terminals
			do_action( $this->id . '_after_terminals' );
		}
	}

	/**
	 * Saves selected terminal to order meta
	 *
	 * @param  integer $order_id Order ID
	 * @param  array   $posted   WooCommerce posted data
	 *
	 * @return void
	 */
	public function checkout_save_order_terminal_id_meta( $order_id, $posted ) {
		$terminal_id = wc_get_var( $_POST[ $this->field_name ] );

		if ( $terminal_id ) {
			$order = wc_get_order( $order_id );
			$order->update_meta_data( $this->field_name, $terminal_id );
			$order->save();
		}
	}

	/**
	 * Saves selected terminal in session whilst order review updates
	 *
	 * @param  string $posted Posted data
	 *
	 * @return void
	 */
	function checkout_save_session_terminal_id( $post_data ) {
		parse_str( $post_data, $posted );

		if( isset( $posted[ $this->field_name ] ) ) {
			WC()->session->set( $this->field_name, $posted[ $this->field_name ] );
		}
	}

	/**
	 * Outputs user selected terminal in different locations (admin screen, email, orders)
	 *
	 * @param  mixed $order Order (ID or WC_Order)
	 * @return void
	 */
	public function show_selected_terminal( $order ) {
		// Create order instance if needed.
		if ( ! is_object( $order ) ) {
			$order = wc_get_order( $order );
		}

		// Store order ID
		$this->order_id = wc_esm_get_order_id( $order );

		// Check if the order has our shipping method
		if ( $order->has_shipping_method( $this->id ) ) {
			// Fetch selected terminal ID
			$terminal_id   = $this->get_order_terminal( $this->order_id );
			$terminal_name = $this->get_terminal_name( $terminal_id );

			// Output selected terminal to user customer details
			if( current_filter() == 'woocommerce_order_details_after_customer_details' ) {
				if( version_compare( WC_VERSION, '2.3.0', '<' ) ) {
					$terminal  = '<dt>' . $this->i18n_selected_terminal . ':</dt>';
					$terminal .= '<dd>' . esc_html( $terminal_name ) . '</dd>';
				}
				else {
					$terminal  = '<tr>';
					$terminal .= '<th>' . $this->i18n_selected_terminal . ':</th>';
					$terminal .= '<td data-title="' . esc_attr( $this->i18n_selected_terminal ) . '">' . esc_html( $terminal_name ) . '</td>';
					$terminal .= '</tr>';
				}
			}
			elseif( current_filter() == 'woocommerce_email_customer_details' ) {
				$terminal  = '<h2>' . $this->i18n_selected_terminal . '</h2>';
				$terminal .= '<p>'. esc_html( $terminal_name ) .'</p>';
			}
			// WooCommerce PDF Invoices & Packing Slips
			elseif( current_filter() == 'wpo_wcpdf_after_order_data' ) {
				$terminal  = '<tr class="chosen-terminal selected_terminal">';
				$terminal .= '<th>' . $this->i18n_selected_terminal . ':</th>';
				$terminal .= '<td>' . esc_html( $terminal_name ) . '</td>';
				$terminal .= '</tr>';
			}
			// Output selected terminal to everywhere else
			else {
				$terminal  = '<div class="selected_terminal clear">';
				$terminal .= '<div><strong>' . $this->i18n_selected_terminal . ':</strong></div>';
				$terminal .= esc_html( $terminal_name );
				$terminal .= '</div>';
			}

			// Allow manipulating output
			echo apply_filters( 'wc_shipping_'. $this->id .'_selected_terminal', $terminal, $terminal_id, $terminal_name, current_filter() );
		}
	}

	/**
	 * Custom filter to add order terminal name.
	 *
	 * @param string   $terminal_name Terminal name.
	 * @param WC_Order $order Order.
	 *
	 * @return string
	 */
	public function add_order_terminal_name( $terminal_name, $order ) {
		// Create order instance if needed.
		if ( ! is_object( $order ) ) {
			$order = wc_get_order( $order );
		}

		// Store order ID.
		$this->order_id = wc_esm_get_order_id( $order );

		// Check if the order has our shipping method.
		if ( $order->has_shipping_method( $this->id ) ) {
			// Fetch selected terminal ID.
			$terminal_id   = $this->get_order_terminal( $this->order_id );
			$terminal_name = $this->get_terminal_name( $terminal_id );
		}

		return $terminal_name;
	}

	/**
	 * Outputs user selected terminal for WooCommerce PDF Invoices & Packing Slips plugin
	 *
	 * @param string $document_type Invoice or Packing slip.
	 * @param mixed  $order         Order.
	 *
	 * @return void
	 */
	public function wpo_wcpdf_show_selected_terminal( $document_type, $order ) {
		$this->show_selected_terminal( $order );
	}

	/**
	 * Outputs user selected terminal in admin order preview
	 *
	 * @since  1.5.2
	 * @param  array    $order_details Order details/data
	 * @param  WC_Order $order         Order
	 * @return array                   Modified order details
	 */
	public function show_selected_terminal_in_order_preview( $order_details, $order ) {
		// Create order instance if needed.
		if ( ! is_object( $order ) ) {
			$order = wc_get_order( $order );
		}

		// Store order ID.
		$this->order_id = wc_esm_get_order_id( $order );

		// Check if the order has our shipping method.
		if ( $order->has_shipping_method( $this->id ) ) {
			// Fetch selected terminal ID.
			$terminal_id   = $this->get_order_terminal( $this->order_id );
			$terminal_name = $this->get_terminal_name( $terminal_id );

			if ( isset( $order_details['shipping_via'] ) ) {
				$order_details['shipping_via'] = sprintf( '%s (%s)', $order->get_shipping_method(), esc_html( $terminal_name ) );
			}
		}

		return $order_details;
	}

	/**
	 * Validates user submitted terminal
	 *
	 * @param  array $posted Checkout data
	 *
	 * @return void
	 */
	function validate_user_selected_terminal( $posted ) {
		// Chcek if our field was submitted
		if ( isset( $_POST[ $this->field_name ] ) && empty( $_POST[ $this->field_name ] ) ) {
			// Be sure shipping method was posted
			if( isset( $posted['shipping_method'] ) && is_array( $posted['shipping_method'] ) ) {
				// Check if it was regular parcel terminal
				if( in_array( $this->id, $posted['shipping_method'] ) ) {
					// Add checkout error
					wc_add_notice( __( 'Please select a parcel terminal', 'wc-estonian-shipping-methods' ), 'error' );
				}
			}
		}
	}

	/**
	 * Sorts and groups all terminals as user prefers
	 *
	 * @return array Sorted and grouped terminals
	 */
	function get_sorted_and_grouped_terminals() {
		$sorted_terminals  = $this->get_sorted_terminals();
		$grouped_terminals = $this->get_grouped_terminals( $sorted_terminals );

		// If everything needed to be sorted alphabetically, do so
		if ( $this->get_sorting_option() == 'alphabetically' ) {
			ksort( $grouped_terminals );
		}

		// Format name
		foreach ( $grouped_terminals as $group => $terminals ) {
			foreach ( $terminals as $terminal_key => $terminal ) {
				$grouped_terminals[ $group ][ $terminal_key ]->name = $this->get_formatted_terminal_name( $terminal );
			}
		}

		return $grouped_terminals;
	}

	/**
	 * Sorts all terminals as user prefers
	 *
	 * @param  mixed $terminals Terminals (false = will fetch)
	 * @return array            Sorted terminals
	 */
	function get_sorted_terminals( $terminals = false ) {
		$sort_by          = $this->get_sorting_option();
		$terminals        = $terminals ? $terminals : $this->get_terminals();
		$sorted_terminals = $terminals;

		switch ( $sort_by ) {
			// By default, sort by Itella's priority (bigger cities first).
			default:
			case 'cities_first':
				// Sort by group_sort attribute provided by Smartpost.
				usort( $sorted_terminals, array( $this, 'terminals_group_sort' ) );
				break;

			// Alphabetically.
			case 'alphabetically':
				usort( $sorted_terminals, array( $this, 'terminals_alphabetical_sort' ) );
				break;

			// No sorting.
			case 'none':
				// Do nothing.
				break;
		}

		return $sorted_terminals;
	}

	/**
	 * Groups all terminals as user prefers
	 *
	 * @param  mixed $terminals Terminals (false = will fetch)
	 * @return array            Grouped terminals
	 */
	function get_grouped_terminals( $terminals = FALSE ) {
		$group_by          = $this->get_grouping_option();
		$terminals         = $terminals ? $terminals : $this->get_terminals();
		$grouped_terminals = array();

		switch( $group_by ) {
			// By default, group by cities
			default:
			case 'cities':
				// Go through terminals
				foreach( $terminals as $terminal ) {
					// Allow manipulating city name
					$city_name = apply_filters( 'wc_shipping_'. $this->id .'_city_name', $terminal->city );

					if( ! isset( $grouped_terminals[ $city_name ] ) )
						$grouped_terminals[ $city_name ] = array();

					$grouped_terminals[ $city_name ][] = $terminal;
				}
			break;

			// Group by counties
			case 'counties':
				// Go through terminals
				foreach( $terminals as $terminal ) {
					// Replace Tallinn/Harjumaa with Harjumaa, because Tallinn is not a county,
					// also allow manipulating group names
					$group_name = apply_filters( 'wc_shipping_'. $this->id .'_group_name', $terminal->group_name, $terminal->group_name );

					if( ! isset( $grouped_terminals[ $group_name ] ) )
						$grouped_terminals[ $group_name ] = array();

					$grouped_terminals[ $group_name ][] = $terminal;
				}
			break;
		}

		return $grouped_terminals;
	}

	/**
	 * Get user preferred terminal grouping option
	 *
	 * @return string Grouping option
	 */
	function get_grouping_option() {
		return apply_filters( 'wc_shipping_'. $this->id .'_terminal_grouping', $this->get_option( 'group_terminals', 'cities' ) );
	}

	/**
	 * Get user preferred terminal sorting option
	 *
	 * @return string Sorting option
	 */
	function get_sorting_option() {
		return apply_filters( 'wc_shipping_'. $this->id .'_terminal_sorting', $this->get_option( 'sort_terminals', 'alphabetically' ) );
	}

	/**
	 * Get user preferred terminal name format option
	 *
	 * @return string Formatting option
	 */
	function get_name_formatting_option() {
		if( ! isset( $this->name_format ) || ! $this->name_format )
			$this->name_format = apply_filters( 'wc_shipping_'. $this->id .'_terminal_format', $this->get_option( 'terminals_format', 'name' ) );

		return $this->name_format;
	}

	/**
	 * Formats terminal name
	 *
	 * @param  object $terminal Terminal
	 * @return string           Terminal name
	 */
	function get_formatted_terminal_name( $terminal ) {
		$name = $terminal->name;

		if( $this->get_name_formatting_option() == 'with_address' ) {
			$name .= ' ('. $terminal->address .')';
		}

		return apply_filters( 'wc_shipping_'. $this->id .'_terminal_name', $name, $terminal->name, $terminal->city, $terminal->address );
	}

	/**
	 * Fetches locations and stores them to cache.
	 *
	 * @return array Terminals
	 */
	function get_terminals() {
		return array();
	}

	/**
	 * Fetch terminal cache transient name
	 * @return string Transient name
	 */
	function get_terminals_cache_transient_name() {
		// Shipping country
		$shipping_country        = $this->get_shipping_country();

		// Save country
		$this->terminals_country = $shipping_country;

		// Get terminals transient/cache
		$transient_name          = strtolower( $this->id . '_terminals_' . $shipping_country );

		return $transient_name;
	}

	/**
	 * Fetch terminals cache
	 *
	 * @return array Terminals
	 */
	function get_terminals_cache() {
		// Shipping country
		$shipping_country    = $this->get_shipping_country();

		// Check if terminals are already loaded
		if( $this->terminals && ( is_array( $this->terminals ) && ! empty( $this->terminals ) ) && $this->terminals_country == $shipping_country ) {
			return $this->terminals;
		}

		// Fetch transient cache
		$terminals_transient = get_transient( $this->get_terminals_cache_transient_name() );

		// Check if terminals transient exists
		if ( $terminals_transient ) {
			// Return cached terminals
			return $terminals_transient;
		}
		else {
			return NULL;
		}
	}

	/**
	 * Save terminals to cache (transient)
	 *
	 * @param  array $terminals Terminals array
	 * @return void
	 */
	function save_terminals_cache( $terminals ) {
		// Set transient for cache
		set_transient( $this->get_terminals_cache_transient_name(), $terminals, 86400 );
	}

	/**
	 * Get city ordering number
	 *
	 * @param  string $city City name
	 *
	 * @return integer      Ordering number
	 */
	private function get_city_order_number( $city ) {
		$cities_order = array(
			'Tallinn'      => 30,
			'Tartu'        => 29,
			'Narva'        => 28,
			'Pärnu'        => 27,
			'Kohtla Järve' => 26,
			'Kohtla-Järve' => 26,
			'Maardu'       => 25,
			'Viljandi'     => 24,
			'Rakvere'      => 23,
			'Sillamäe'     => 22,
			'Kuressaare'   => 21,
			'Võru'         => 20,
			'Valga'        => 19,
			'Jõhvi'        => 18,
			'Haapsalu'     => 17,
			'Keila'        => 16,
			'Paide'        => 15,
			'Türi'         => 14,
			'Tapa'         => 13,
			'Põlva'        => 12,
			'Kiviõli'      => 11,
			'Elva'         => 10,
			'Saue'         => 9,
			'Jõgeva'       => 8,
			'Rapla'        => 7,
			'Põltsamaa'    => 6,
			'Paldiski'     => 5,
			'Sindi'        => 4,
			'Kunda'        => 3,
			'Kärdla'       => 2,
			'Kehra'        => 1,

			'Vilnius'      => 30,
			'Kaunas'       => 29,
			'Klaipėda'     => 28,
			'Klaipeda'     => 28,
			'Šiauliai'     => 27,
			'Siauliai'     => 27,
			'Panevėžys'    => 26,
			'Panevezys'    => 26,
			'Alytus'       => 25,
			'Marijampolė'  => 24,
			'Marijampole'  => 24,
			'Mažeikiai'    => 23,
			'Mazeikiai'    => 23,
			'Jonava'       => 22,
			'Utena'        => 21,
			'Kėdainiai'    => 20,
			'Kedainiai'    => 20,
			'Telšiai'      => 19,
			'Telsiai'      => 19,
			'Visaginas'    => 18,
			'Tauragė'      => 17,
			'Taurage'      => 17,
			'Ukmergė'      => 16,
			'Ukmerge'      => 16,
			'Plungė'       => 15,
			'Plunge'       => 15,
			'Šilutė'       => 14,
			'Silute'       => 14,
			'Kretinga'     => 13,
			'Radviliškis'  => 12,
			'Radviliskis'  => 12,
			'Druskininkai' => 11,
			'Palanga'      => 10,
			'Rokiškis'     => 9,
			'Rokiskis'     => 9,
			'Biržai'       => 8,
			'Birzai'       => 8,
			'Gargždai'     => 7,
			'Gargzdai'     => 7,
			'Kuršėnai'     => 6,
			'Kursenai'     => 6,
			'Elektrėnai'   => 5,
			'Elektrenai'   => 5,
			'Jurbarkas'    => 4,
			'Garliava'     => 3,
			'Vilkaviškis'  => 2,
			'Vilkaviskis'  => 2,
			'Raseiniai'    => 1,

			'Rīga'         => 30,
			'Riga'         => 30,
			'Daugavpils'   => 29,
			'Liepāja'      => 28,
			'Liepaja'      => 28,
			'Jelgava'      => 27,
			'Jūrmala'      => 26,
			'Jurmala'      => 26,
			'Ventspils'    => 25,
			'Rēzekne'      => 24,
			'Rezekne'      => 24,
			'Valmiera'     => 23,
			'Jēkabpils'    => 22,
			'Jekabpils'    => 22,
			'Ogre'         => 21,
			'Tukums'       => 20,
			'Salaspils'    => 19,
			'Cēsis'        => 18,
			'Cesis'        => 18,
			'Kuldīga'      => 17,
			'Kuldiga'      => 17,
			'Olaine'       => 16,
			'Saldus'       => 15,
			'Talsi'        => 14,
			'Sigulda'      => 13,
			'Dobele'       => 12,
			'Krāslava'     => 11,
			'Kraslava'     => 11,
			'Bauska'       => 10,
			'Ludza'        => 9,
			'Līvāni'       => 8,
			'Livani'       => 8,
			'Alūksne'      => 7,
			'Aluksne'      => 7,
			'Gulbene'      => 6,
			'Madona'       => 5,
			'Aizkraukle'   => 4,
			'Limbaži'      => 3,
			'Limbazi'      => 3,
			'Preiļi'       => 2,
			'Preili'       => 2,
			'Balvi'        => 1
		);

		if( isset( $cities_order[ $city ] ) ) {
			return $cities_order[ $city ];
		}

		return 0;
	}

	/**
	 * Sort terminals by group_sort attribute
	 *
	 * @see    usort()
	 *
	 * @param  object $a Terminal A
	 * @param  object $b Terminal B
	 *
	 * @return integer   0 = stay, 1 = up, -1 = down
	 */
	function terminals_group_sort( $a, $b ) {
		if( isset( $a->group_sort ) && isset( $b->group_sort ) ) {
			if( $a->group_sort == $b->group_sort ) {
				return 0;
			}
			else {
				return ( $a->group_sort > $b->group_sort ) ? -1 : 1;
			}
		}
		else {
			// Get city ordering number
			$a_number = $this->get_city_order_number( $a->city );
			$b_number = $this->get_city_order_number( $b->city );

			return ( ( $a_number == $b_number ) ? 0 : ( $a_number > $b_number ) ) ? -1 : 1;
		}
	}

	/**
	 * Sort terminals alphabetically by name
	 *
	 * @see    strcmp()
	 *
	 * @param  object $a Terminal A
	 * @param  object $b Terminal B
	 *
	 * @return integer   0 = stay/same, 1 = up, -1 = down
	 */
	function terminals_alphabetical_sort( $a, $b ) {
		return strcmp( $a->name, $b->name );
	}

	/**
	 * Get selected terminal ID from order meta
	 *
	 * @param  integer $order_id Order ID.
	 *
	 * @return integer           Selected terminal
	 */
	public function get_order_terminal( $order_id ) {
		$order = wc_get_order( $order_id );

		return $order ? $order->get_meta( $this->field_name, true ) : false;
	}

	/**
	 * Translates place ID to place name
	 *
	 * @param  integer $place_id Place ID
	 * @return string            Place name
	 */
	function get_terminal_name( $place_id ) {
		$terminals = $this->get_terminals();

		foreach( $terminals as $terminal ) {
			if( intval( $terminal->place_id ) === intval( $place_id ) ) {
				return $this->get_formatted_terminal_name( $terminal );

				break;
			}
		}
	}

	/**
	 * Fetches terminal data
	 *
	 * @param  integer $place_id Place ID
	 * @return string            Place name
	 */
	function get_terminal_data( $place_id ) {
		$terminals = $this->get_terminals();

		foreach ( $terminals as $terminal ) {
			if ( $terminal->place_id == $place_id ) {
				return $terminal;
			}
		}

		return false;
	}

	/**
	 * Request remote URL
	 *
	 * @param  string $url          URL to be requested
	 * @param  string $method       POST/GET
	 * @param  mixed  $body         What ever you want to sent to the requested URL
	 * @param  mixed  $args         Additional request arguments
	 *
	 * @return array                Return fields or all fields from cURL request
	 */
	public function request_remote_url( $url, $method = 'GET', $body = null, $args = array() ) {
		// Remote args.
		$args = wp_parse_args(
			$args,
			array(
				'body'    => '',
				'method'  => $method,
				'headers' => array(
					'Content-Type' => 'application/json',
				),
			)
		);

		// Disable SSL verification on debugging sites.
		if ( defined( 'WP_DEBUG_LOG' ) && true === WP_DEBUG_LOG ) {
			$args['sslverify'] = false;
		}

		// Add body if needed.
		if ( $body ) {
			$args['body'] = $body;
		}

		// Apply hook on arguments.
		$args    = apply_filters( 'wc_shipping_' . $this->id . '_remote_request_args', $args, $url, $body );
		$args    = apply_filters( 'wc_shipping_remote_request_args', $args, $url, $body );
		$request = wp_remote_request( $url, $args );

		return array(
			'success'  => 200 === wp_remote_retrieve_response_code( $request ),
			'response' => $request,
			'data'     => wp_remote_retrieve_body( $request ),
		);
	}
}

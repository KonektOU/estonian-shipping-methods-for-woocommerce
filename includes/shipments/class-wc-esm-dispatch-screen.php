<?php
/**
 * Book the courier, close the manifest.
 *
 * @package Estonian_Shipping_Methods_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce -> Parcel dispatch.
 *
 * What a shop does at the end of a day: book a courier, close a manifest over
 * what is going with them, and look at what has already been booked. Only the
 * carriers that offer a thing appear for it. A carrier that does neither is
 * simply absent from the screen rather than shown with dead buttons.
 *
 * A carrier per tab, and the log last. Only the open tab renders, because a
 * manifest tab counts the orders still outstanding and doing that for every
 * carrier on every load is work nobody asked for.
 */
class WC_ESM_Dispatch_Screen {

	const ACTION     = 'wc_esm_dispatch_action';
	const LOG_OPTION = 'wc_esm_dispatch_log';
	const PAGE       = 'wc-esm-dispatch';

	/**
	 * The tab holding the dispatch log. Every other tab is a carrier id, and
	 * no carrier may claim this one.
	 *
	 * @var string
	 */
	const LOG_TAB = 'log';

	/**
	 * Page size used to walk unmanifested orders.
	 *
	 * @var int
	 */
	const PAGE_SIZE = 200;

	/**
	 * Hard ceiling on how many unmanifested orders one screen load will
	 * fetch, so a runaway query cannot hang the screen.
	 *
	 * @var int
	 */
	const MAX_ORDERS = 5000;

	/**
	 * Hook up.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 20 );
		add_action( 'admin_init', array( __CLASS__, 'handle_action' ) );
	}

	/**
	 * Carriers offering a feature.
	 *
	 * @param string                        $feature  Feature name.
	 * @param WC_ESM_Shipment_Registry|null $registry Registry; the shared one when omitted.
	 *
	 * @return array Providers keyed by id.
	 */
	public static function carriers_for( $feature, $registry = null ) {
		$registry = $registry ? $registry : WC_ESM_Shipment_Registry::instance();

		return $registry->providers_supporting( $feature );
	}

	/**
	 * The tabs the screen is divided into: one per carrier that can dispatch
	 * something, in registration order, and the log last.
	 *
	 * A carrier appears once even when it offers both pickups and manifests -
	 * its tab holds both.
	 *
	 * @param WC_ESM_Shipment_Registry|null $registry Registry; the shared one when omitted.
	 *
	 * @return array Tab labels keyed by tab id.
	 */
	public static function tabs( $registry = null ) {
		$tabs = array();

		foreach ( array( 'pickup', 'manifest' ) as $feature ) {
			foreach ( self::carriers_for( $feature, $registry ) as $id => $provider ) {
				$tabs[ $id ] = $provider->get_title();
			}
		}

		$tabs[ self::LOG_TAB ] = __( 'Recent dispatches', 'wc-estonian-shipping-methods' );

		return $tabs;
	}

	/**
	 * Which tab to open.
	 *
	 * A tab arrives from a URL, so it is whatever somebody typed: a carrier
	 * since switched off, a stale bookmark, markup. Anything the screen does
	 * not itself offer opens the first tab.
	 *
	 * @param string                        $requested Tab asked for.
	 * @param WC_ESM_Shipment_Registry|null $registry  Registry; the shared one when omitted.
	 *
	 * @return string
	 */
	public static function active_tab( $requested, $registry = null ) {
		$tabs      = self::tabs( $registry );
		$requested = sanitize_key( (string) $requested );

		return isset( $tabs[ $requested ] ) ? $requested : (string) key( $tabs );
	}

	/**
	 * The screen's own URL, optionally on a given tab.
	 *
	 * Every redirect after a POST goes through here, so the work lands back
	 * on the tab it was done from rather than on the first one.
	 *
	 * @param string $tab Tab id; the bare page when empty.
	 *
	 * @return string
	 */
	public static function screen_url( $tab = '' ) {
		$url = admin_url( 'admin.php?page=' . self::PAGE );

		return $tab ? $url . '&tab=' . rawurlencode( $tab ) : $url;
	}

	/**
	 * The tab a POST was made from.
	 *
	 * Every form carries its tab, so the redirect lands back where the work
	 * was done. Callers reach this only after handle_action() checked the
	 * nonce.
	 *
	 * @return string
	 */
	protected static function posted_tab() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by handle_action() before anything reaches here.
		return self::active_tab( wc_get_var( $_POST['tab'], '' ) );
	}

	/**
	 * Add the menu entry, only when some carrier can use it.
	 *
	 * @return void
	 */
	public static function add_menu() {
		if ( ! self::carriers_for( 'pickup' ) && ! self::carriers_for( 'manifest' ) ) {
			return;
		}

		add_submenu_page(
			'woocommerce',
			__( 'Parcel dispatch', 'wc-estonian-shipping-methods' ),
			__( 'Parcel dispatch', 'wc-estonian-shipping-methods' ),
			'manage_woocommerce',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * The shipments not yet on a manifest, and whether the count is the
	 * whole truth.
	 *
	 * Both facts come back together on purpose. They were a return value and
	 * a flag on the class before, which meant the caller had to ask in the
	 * right order to get an answer that matched.
	 *
	 * @param string $provider_id Provider id.
	 *
	 * @return array refs and truncated.
	 */
	public static function unmanifested( $provider_id ) {
		$found = self::unmanifested_orders( $provider_id );
		$refs  = array();

		foreach ( $found['orders'] as $order ) {
			$refs = array_merge( $refs, WC_ESM_Shipment::label_refs( $order ) );
		}

		return array(
			'refs'      => array_values( array_unique( $refs ) ),
			'truncated' => $found['truncated'],
		);
	}

	/**
	 * Orders carrying a shipment with this carrier that is not on a manifest.
	 *
	 * Walks every page rather than taking the first, so a shop with more
	 * orders outstanding than one page holds is not told a smaller, wrong
	 * number. The answer says whether MAX_ORDERS cut it short.
	 *
	 * @param string $provider_id Provider id.
	 *
	 * @return array orders and truncated.
	 */
	protected static function unmanifested_orders( $provider_id ) {
		$provider = WC_ESM_Shipment_Registry::instance()->get_provider( $provider_id );

		if ( ! $provider ) {
			return array(
				'orders'    => array(),
				'truncated' => false,
			);
		}

		$found     = array();
		$truncated = false;
		$page      = 1;

		do {
			$orders = wc_get_orders(
				array(
					'limit'      => self::PAGE_SIZE,
					'page'       => $page,
					'status'     => array( 'processing', 'completed', 'on-hold' ),
					'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						array(
							'key'     => WC_ESM_Shipment::LABEL_REFS,
							'compare' => 'EXISTS',
						),
						array(
							'key'     => '_wc_esm_manifested',
							'compare' => 'NOT EXISTS',
						),
					),
				)
			);

			foreach ( $orders as $order ) {
				if ( WC_ESM_Shipment_Registration::provider_for( $order ) === $provider ) {
					$found[] = $order;
				}
			}

			if ( count( $found ) >= self::MAX_ORDERS ) {
				$truncated = true;

				break;
			}

			$page++;
		} while ( count( $orders ) === self::PAGE_SIZE );

		return array(
			'orders'    => $found,
			'truncated' => $truncated,
		);
	}

	/**
	 * The screen.
	 *
	 * @return void
	 */
	public static function render() {
		$tabs = self::tabs();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- choosing a tab is a view, not an action; active_tab() takes only what it recognises.
		$active = self::active_tab( wc_get_var( $_GET['tab'], '' ) );

		echo '<div class="wrap"><h1>' . esc_html__( 'Parcel dispatch', 'wc-estonian-shipping-methods' ) . '</h1>';

		self::render_tabs( $tabs, $active );

		if ( self::LOG_TAB === $active ) {
			self::render_log();
		} else {
			self::render_carrier( $active );
		}

		echo '</div>';
	}

	/**
	 * The tab bar.
	 *
	 * @param array  $tabs   Labels keyed by tab id.
	 * @param string $active Open tab.
	 *
	 * @return void
	 */
	protected static function render_tabs( $tabs, $active ) {
		echo '<nav class="nav-tab-wrapper wp-clearfix">';

		foreach ( $tabs as $id => $label ) {
			printf(
				'<a href="%s" class="nav-tab%s">%s</a>',
				esc_url( self::screen_url( $id ) ),
				$id === $active ? ' nav-tab-active' : '',
				esc_html( $label )
			);
		}

		echo '</nav>';
	}

	/**
	 * One carrier's tab: what it can be asked to do, and nothing else.
	 *
	 * @param string $id Carrier id, which is also its tab id.
	 *
	 * @return void
	 */
	protected static function render_carrier( $id ) {
		$provider = WC_ESM_Shipment_Registry::instance()->get_provider( $id );

		// active_tab() only returns a tab tabs() built, so a provider is
		// there - unless a carrier was switched off between the two calls.
		if ( ! $provider ) {
			return;
		}

		if ( $provider->supports( 'pickup' ) ) {
			self::render_pickup( $id );
		}

		if ( $provider->supports( 'manifest' ) ) {
			self::render_manifest( $id );
		}
	}

	/**
	 * The pickup booking form for one carrier.
	 *
	 * @param string $id Carrier id.
	 *
	 * @return void
	 */
	protected static function render_pickup( $id ) {
		?>
		<h2><?php esc_html_e( 'Courier pickup', 'wc-estonian-shipping-methods' ); ?></h2>
		<form method="post">
			<?php wp_nonce_field( self::ACTION ); ?>
			<input type="hidden" name="<?php echo esc_attr( self::ACTION ); ?>" value="pickup" />
			<input type="hidden" name="provider" value="<?php echo esc_attr( $id ); ?>" />
			<input type="hidden" name="tab" value="<?php echo esc_attr( $id ); ?>" />
			<table class="form-table">
				<tr>
					<th><label for="wc-esm-date-<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Pickup date', 'wc-estonian-shipping-methods' ); ?></label></th>
					<td><input type="date" id="wc-esm-date-<?php echo esc_attr( $id ); ?>" name="date" value="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>" /></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Between', 'wc-estonian-shipping-methods' ); ?></th>
					<td>
						<input type="time" name="time_from" value="09:00" />
						<input type="time" name="time_to" value="17:00" />
					</td>
				</tr>
				<tr>
					<th><label for="wc-esm-comment-<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Message to the courier', 'wc-estonian-shipping-methods' ); ?></label></th>
					<td><input type="text" class="regular-text" id="wc-esm-comment-<?php echo esc_attr( $id ); ?>" name="comment" value="" /></td>
				</tr>
			</table>
			<?php submit_button( __( 'Book a pickup', 'wc-estonian-shipping-methods' ) ); ?>
		</form>
		<?php
	}

	/**
	 * The manifest block for one carrier.
	 *
	 * @param string $id Carrier id.
	 *
	 * @return void
	 */
	protected static function render_manifest( $id ) {
		$outstanding = self::unmanifested( $id );
		$refs        = $outstanding['refs'];
		?>
		<h2><?php esc_html_e( 'Manifest', 'wc-estonian-shipping-methods' ); ?></h2>
		<p>
			<?php
			printf(
				/* translators: %d: number of shipments. */
				esc_html( _n( '%d shipment is not on a manifest yet.', '%d shipments are not on a manifest yet.', count( $refs ), 'wc-estonian-shipping-methods' ) ),
				count( $refs )
			);
			?>
		</p>
		<?php if ( $outstanding['truncated'] ) : ?>
			<p class="description">
				<?php
				printf(
					/* translators: %d: the ceiling that was hit. */
					esc_html__( 'Stopped after %d orders; there may be more not shown here.', 'wc-estonian-shipping-methods' ),
					(int) self::MAX_ORDERS
				);
				?>
			</p>
		<?php endif; ?>
		<form method="post">
			<?php wp_nonce_field( self::ACTION ); ?>
			<input type="hidden" name="<?php echo esc_attr( self::ACTION ); ?>" value="manifest" />
			<input type="hidden" name="provider" value="<?php echo esc_attr( $id ); ?>" />
			<input type="hidden" name="tab" value="<?php echo esc_attr( $id ); ?>" />
			<?php submit_button( __( 'Close the manifest', 'wc-estonian-shipping-methods' ), 'primary', 'submit', true, $refs ? array() : array( 'disabled' => 'disabled' ) ); ?>
		</form>
		<?php
	}

	/**
	 * What has been booked and closed.
	 *
	 * @return void
	 */
	protected static function render_log() {
		$log = (array) get_option( self::LOG_OPTION, array() );

		echo '<h2>' . esc_html__( 'Recent dispatches', 'wc-estonian-shipping-methods' ) . '</h2>';

		if ( empty( $log ) ) {
			echo '<p>' . esc_html__( 'Nothing has been booked or manifested yet.', 'wc-estonian-shipping-methods' ) . '</p>';

			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Carrier', 'wc-estonian-shipping-methods' ) . '</th>';
		echo '<th>' . esc_html__( 'Kind', 'wc-estonian-shipping-methods' ) . '</th>';
		echo '<th>' . esc_html__( 'Reference', 'wc-estonian-shipping-methods' ) . '</th>';
		echo '<th>' . esc_html__( 'When', 'wc-estonian-shipping-methods' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'wc-estonian-shipping-methods' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( array_reverse( $log ) as $entry ) {
			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( $entry['provider'] ),
				esc_html( $entry['type'] ),
				esc_html( $entry['reference'] ),
				esc_html( isset( $entry['created_at'] ) ? $entry['created_at'] : '' ),
				self::render_log_row_actions( $entry ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside render_log_row_actions().
			);
		}

		echo '</tbody></table>';
	}

	/**
	 * What a log row can offer: a manifest download, a pickup cancel, or
	 * nothing at all.
	 *
	 * A button appears only where it would work. The carrier may have been
	 * switched off since, or may no longer offer the feature, and the same
	 * rule the rest of this screen follows applies: no button for something
	 * that would fail.
	 *
	 * @param array                         $entry    Log entry.
	 * @param WC_ESM_Shipment_Registry|null $registry Registry; the shared one when omitted.
	 *
	 * @return array
	 */
	public static function log_row_actions( $entry, $registry = null ) {
		$registry = $registry ? $registry : WC_ESM_Shipment_Registry::instance();
		$provider = $registry->get_provider( isset( $entry['provider'] ) ? $entry['provider'] : '' );

		if ( ! $provider || ! empty( $entry['cancelled'] ) ) {
			return array();
		}

		$type = isset( $entry['type'] ) ? $entry['type'] : '';

		if ( 'manifest' === $type && $provider->supports( 'manifest' ) ) {
			return array( 'manifest_download' );
		}

		if ( 'pickup' === $type && $provider->supports( 'pickup' ) ) {
			return array( 'pickup_cancel' );
		}

		return array();
	}

	/**
	 * The Actions cell for one log row.
	 *
	 * @param array $entry Log entry.
	 *
	 * @return string Escaped HTML.
	 */
	protected static function render_log_row_actions( $entry ) {
		$actions = self::log_row_actions( $entry );

		if ( in_array( 'manifest_download', $actions, true ) ) {
			return self::log_row_form( 'manifest_download', $entry, esc_html__( 'Download', 'wc-estonian-shipping-methods' ) );
		}

		if ( in_array( 'pickup_cancel', $actions, true ) ) {
			$provider = WC_ESM_Shipment_Registry::instance()->get_provider( $entry['provider'] );

			if ( ! $provider ) {
				return '';
			}

			$question = sprintf(
				/* translators: 1: carrier name, 2: pickup reference. */
				__( 'Cancel the pickup %2$s booked with %1$s?', 'wc-estonian-shipping-methods' ),
				$provider->get_title(),
				$entry['reference']
			);

			return self::log_row_form(
				'pickup_cancel',
				$entry,
				esc_html__( 'Cancel', 'wc-estonian-shipping-methods' ),
				sprintf( ' onclick="return confirm( \'%s\' );"', esc_js( $question ) )
			);
		}

		if ( ! empty( $entry['cancelled'] ) ) {
			return esc_html__( 'Cancelled', 'wc-estonian-shipping-methods' );
		}

		return '';
	}

	/**
	 * One small POST form for a log-row action.
	 *
	 * @param string $action Value posted as self::ACTION.
	 * @param array  $entry  Log entry the form acts on.
	 * @param string $label  Already-escaped button label.
	 * @param string $extra  Already-escaped extra button attributes.
	 *
	 * @return string
	 */
	protected static function log_row_form( $action, $entry, $label, $extra = '' ) {
		ob_start();
		?>
		<form method="post">
			<?php wp_nonce_field( self::ACTION ); ?>
			<input type="hidden" name="<?php echo esc_attr( self::ACTION ); ?>" value="<?php echo esc_attr( $action ); ?>" />
			<input type="hidden" name="provider" value="<?php echo esc_attr( $entry['provider'] ); ?>" />
			<input type="hidden" name="reference" value="<?php echo esc_attr( $entry['reference'] ); ?>" />
			<input type="hidden" name="tab" value="<?php echo esc_attr( self::LOG_TAB ); ?>" />
			<button type="submit" class="button"<?php echo $extra; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped by the caller. ?>><?php echo $label; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped by the caller. ?></button>
		</form>
		<?php
		return ob_get_clean();
	}

	/**
	 * Find a log entry by carrier and reference.
	 *
	 * The log is keyed by nothing, so two entries with the same carrier and
	 * reference are indistinguishable. The first is returned: the guard that
	 * uses this only needs to know that a match exists and whether it has
	 * already been cancelled.
	 *
	 * @param array  $log         The log.
	 * @param string $provider_id Provider id.
	 * @param string $reference   Reference.
	 *
	 * @return array|null
	 */
	public static function find_log_entry( $log, $provider_id, $reference ) {
		foreach ( (array) $log as $entry ) {
			if ( isset( $entry['provider'], $entry['reference'] ) && $entry['provider'] === $provider_id && $entry['reference'] === $reference ) {
				return $entry;
			}
		}

		return null;
	}

	/**
	 * Mark every entry matching a carrier and reference as cancelled.
	 *
	 * Every one, not the first: two rows with the same reference are
	 * indistinguishable, and marking only the oldest leaves the second row
	 * still offering to cancel something that is already gone.
	 *
	 * @param array  $log         The log.
	 * @param string $provider_id Provider id.
	 * @param string $reference   Reference.
	 *
	 * @return array
	 */
	public static function mark_cancelled_in( $log, $provider_id, $reference ) {
		foreach ( (array) $log as $index => $entry ) {
			if ( isset( $entry['provider'], $entry['reference'] ) && $entry['provider'] === $provider_id && $entry['reference'] === $reference ) {
				$log[ $index ]['cancelled'] = true;
			}
		}

		return $log;
	}

	/**
	 * Run whatever the screen asked for.
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

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked above.
		$what     = sanitize_key( wp_unslash( $_POST[ self::ACTION ] ) );
		$provider = WC_ESM_Shipment_Registry::instance()->get_provider( sanitize_key( wc_get_var( $_POST['provider'], '' ) ) );

		if ( ! $provider ) {
			return;
		}

		if ( 'pickup' === $what && $provider->supports( 'pickup' ) ) {
			$result = $provider->request_pickup(
				array(
					'date'      => sanitize_text_field( wc_get_var( $_POST['date'], '' ) ),
					'time_from' => sanitize_text_field( wc_get_var( $_POST['time_from'], '' ) ),
					'time_to'   => sanitize_text_field( wc_get_var( $_POST['time_to'], '' ) ),
					'comment'   => sanitize_text_field( wc_get_var( $_POST['comment'], '' ) ),
				)
			);

			if ( $result->is_success() ) {
				self::record( $provider->get_id(), 'pickup', $result->get( 'reference', '' ) );
			}

			self::notify( $result );
		}

		if ( 'manifest' === $what && $provider->supports( 'manifest' ) ) {
			$outstanding = self::unmanifested( $provider->get_id() );
			$result      = $provider->close_manifest( $outstanding['refs'] );

			if ( $result->is_success() ) {
				self::mark_manifested( $provider->get_id() );
				self::record( $provider->get_id(), 'manifest', $result->get( 'reference', '' ) );
			}

			self::notify( $result );
		}

		if ( 'manifest_download' === $what && $provider->supports( 'manifest' ) ) {
			$result = $provider->fetch_manifest( sanitize_text_field( wc_get_var( $_POST['reference'], '' ) ) );

			if ( $result->is_success() && '' !== $result->get( 'pdf', '' ) ) {
				WC_ESM_Shipment_Labels::stream( $result->get( 'pdf' ), 'manifest.pdf' );
			}

			self::notify( $result );
		}

		if ( 'pickup_cancel' === $what && $provider->supports( 'pickup' ) ) {
			$reference = sanitize_text_field( wc_get_var( $_POST['reference'], '' ) );
			$log       = (array) get_option( self::LOG_OPTION, array() );
			$entry     = self::find_log_entry( $log, $provider->get_id(), $reference );

			// A repeated POST - the back button, a stale bookmark - would ask
			// the carrier to cancel something already gone. The log is what
			// tells us it is gone.
			if ( ! $entry || ! empty( $entry['cancelled'] ) ) {
				self::notify( WC_ESM_Shipment_Result::failure( __( 'That pickup has already been cancelled.', 'wc-estonian-shipping-methods' ) ) );
			} else {
				$result = $provider->cancel_pickup( $reference );

				if ( $result->is_success() ) {
					update_option( self::LOG_OPTION, self::mark_cancelled_in( $log, $provider->get_id(), $reference ) );
				}

				self::notify( $result );
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		wp_safe_redirect( self::screen_url( self::posted_tab() ) );
		exit;
	}

	/**
	 * Remember what to say after the redirect.
	 *
	 * @param WC_ESM_Shipment_Result $result What happened.
	 *
	 * @return void
	 */
	protected static function notify( $result ) {
		set_transient(
			'wc_esm_dispatch_notice',
			array(
				'ok'      => $result->is_success(),
				'message' => $result->get_message(),
			),
			MINUTE_IN_SECONDS
		);
	}

	/**
	 * Stamp the orders a manifest was closed over, so they are not counted
	 * again tomorrow.
	 *
	 * @param string $provider_id Provider id.
	 *
	 * @return void
	 */
	protected static function mark_manifested( $provider_id ) {
		foreach ( self::unmanifested_orders( $provider_id )['orders'] as $order ) {
			$order->update_meta_data( '_wc_esm_manifested', gmdate( 'c' ) );
			$order->save();
		}
	}

	/**
	 * Write one line in the dispatch log.
	 *
	 * @param string $provider_id Provider id.
	 * @param string $type        pickup or manifest.
	 * @param string $reference   What the carrier called it.
	 *
	 * @return void
	 */
	protected static function record( $provider_id, $type, $reference ) {
		$log   = (array) get_option( self::LOG_OPTION, array() );
		$log[] = array(
			'provider'   => $provider_id,
			'type'       => $type,
			'reference'  => (string) $reference,
			'created_at' => gmdate( 'Y-m-d H:i' ),
		);

		// Twenty is a screen's worth; this is a convenience, not an audit
		// trail, and the carrier's own portal is where the real record lives.
		update_option( self::LOG_OPTION, array_slice( $log, -20 ) );
	}
}

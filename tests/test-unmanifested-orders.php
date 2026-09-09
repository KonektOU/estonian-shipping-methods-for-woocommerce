<?php
/**
 * What is waiting to go on a manifest.
 *
 * @package Estonian_Shipping_Methods_For_WooCommerce
 */

require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/class-wc-esm-shipment-result.php';
require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/class-wc-esm-shipment.php';
require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/abstracts/class-wc-esm-shipment-provider.php';
require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/class-wc-esm-shipment-registry.php';
require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/class-wc-esm-unmanifested-orders.php';
require_once __DIR__ . '/test-shipment-labels.php';

/**
 * Tests for WC_ESM_Unmanifested_Orders.
 */
class Test_Unmanifested_Orders extends WC_ESM_Test_Case {

	/**
	 * Pages of orders wc_get_orders() will hand back, in order.
	 *
	 * @var array
	 */
	protected $pages = array();

	/**
	 * Stand the order query up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->pages = array();
		$pages       = &$this->pages;

		Brain\Monkey\Functions\when( 'wc_get_orders' )->alias(
			static function ( $args ) use ( &$pages ) {
				$page = isset( $args['page'] ) ? (int) $args['page'] : 1;

				return isset( $pages[ $page - 1 ] ) ? $pages[ $page - 1 ] : array();
			}
		);
	}

	/**
	 * A registry holding one carrier that manifests.
	 *
	 * @return WC_ESM_Shipment_Registry
	 */
	protected function registry() {
		$provider         = new WC_ESM_Labelling_Provider();
		$provider->id     = 'dpd';
		$provider->method = 'dpd_shops_ee';

		$registry = new WC_ESM_Shipment_Registry();
		$registry->register( $provider );

		return $registry;
	}

	/**
	 * An order with a shipment waiting.
	 *
	 * @param string $method Method it was shipped by.
	 * @param array  $refs   Label references.
	 * @param int    $id     Order id.
	 *
	 * @return WC_ESM_Shipped_Order
	 */
	protected function order( $method, $refs, $id ) {
		return new WC_ESM_Shipped_Order( $method, $refs, $id );
	}

	/**
	 * The references of everything this carrier has outstanding.
	 *
	 * @return void
	 */
	public function test_it_collects_this_carriers_references() {
		$this->pages = array(
			array(
				$this->order( 'dpd_shops_ee', array( 'r1' ), 1 ),
				$this->order( 'dpd_shops_ee', array( 'r2' ), 2 ),
			),
		);

		$outstanding = WC_ESM_Unmanifested_Orders::for_provider( 'dpd', $this->registry() );

		$this->assertSame( array( 'r1', 'r2' ), $outstanding['refs'] );
		$this->assertFalse( $outstanding['truncated'] );
	}

	/**
	 * Another carrier's orders are not this carrier's to manifest, even
	 * though the query cannot tell them apart.
	 *
	 * @return void
	 */
	public function test_another_carriers_orders_are_left_out() {
		$this->pages = array(
			array(
				$this->order( 'dpd_shops_ee', array( 'r1' ), 1 ),
				$this->order( 'omniva_post_offices_ee', array( 'r2' ), 2 ),
			),
		);

		$this->assertSame( array( 'r1' ), WC_ESM_Unmanifested_Orders::for_provider( 'dpd', $this->registry() )['refs'] );
	}

	/**
	 * The same reference on two orders is counted once: a manifest lists
	 * shipments, not orders.
	 *
	 * @return void
	 */
	public function test_a_repeated_reference_is_counted_once() {
		$this->pages = array(
			array(
				$this->order( 'dpd_shops_ee', array( 'r1' ), 1 ),
				$this->order( 'dpd_shops_ee', array( 'r1' ), 2 ),
			),
		);

		$this->assertSame( array( 'r1' ), WC_ESM_Unmanifested_Orders::for_provider( 'dpd', $this->registry() )['refs'] );
	}

	/**
	 * A carrier nobody registered has nothing outstanding, rather than a
	 * fatal: a shop can switch a carrier off with orders still open.
	 *
	 * @return void
	 */
	public function test_an_unregistered_carrier_has_nothing_outstanding() {
		$outstanding = WC_ESM_Unmanifested_Orders::for_provider( 'collectnet', $this->registry() );

		$this->assertSame( array(), $outstanding['refs'] );
		$this->assertFalse( $outstanding['truncated'] );
	}

	/**
	 * A shop with more outstanding than one page holds is not told a
	 * smaller, wrong number: every page is walked.
	 *
	 * @return void
	 */
	public function test_every_page_is_walked() {
		$full = array();

		for ( $i = 0; $i < WC_ESM_Unmanifested_Orders::PAGE_SIZE; $i++ ) {
			$full[] = $this->order( 'dpd_shops_ee', array( 'r' . $i ), $i );
		}

		$this->pages = array( $full, array( $this->order( 'dpd_shops_ee', array( 'last' ), 999 ) ) );

		$outstanding = WC_ESM_Unmanifested_Orders::for_provider( 'dpd', $this->registry() );

		$this->assertContains( 'last', $outstanding['refs'] );
		$this->assertCount( WC_ESM_Unmanifested_Orders::PAGE_SIZE + 1, $outstanding['refs'] );
	}

	/**
	 * A runaway query cannot hang the screen: the walk stops at a ceiling
	 * and says that the number is not the whole truth.
	 *
	 * @return void
	 */
	public function test_the_walk_stops_at_a_ceiling_and_says_so() {
		$page = array();

		for ( $i = 0; $i < WC_ESM_Unmanifested_Orders::PAGE_SIZE; $i++ ) {
			$page[] = $this->order( 'dpd_shops_ee', array( 'r' . $i ), $i );
		}

		// Enough identical full pages to run past the ceiling.
		$this->pages = array_fill( 0, (int) ceil( WC_ESM_Unmanifested_Orders::MAX_ORDERS / WC_ESM_Unmanifested_Orders::PAGE_SIZE ) + 2, $page );

		$this->assertTrue( WC_ESM_Unmanifested_Orders::for_provider( 'dpd', $this->registry() )['truncated'] );
	}
}

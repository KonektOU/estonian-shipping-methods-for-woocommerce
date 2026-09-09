<?php
/**
 * Print a whole screen of orders at once.
 *
 * @package Estonian_Shipping_Methods_For_WooCommerce
 */

require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/class-wc-esm-shipment-result.php';
require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/class-wc-esm-shipment.php';
require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/abstracts/class-wc-esm-shipment-provider.php';
require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/class-wc-esm-shipment-registry.php';
require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/class-wc-esm-pdf-merger.php';
require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/class-wc-esm-shipment-labels.php';
require_once __DIR__ . '/test-shipment.php';

/**
 * A shipping line on an order.
 */
class WC_ESM_Fake_Shipping_Item {

	/**
	 * Method id.
	 *
	 * @var string
	 */
	protected $method_id;

	/**
	 * Constructor.
	 *
	 * @param string $method_id Method id.
	 */
	public function __construct( $method_id ) {
		$this->method_id = $method_id;
	}

	/**
	 * Method id.
	 *
	 * @return string
	 */
	public function get_method_id() {
		return $this->method_id;
	}

	/**
	 * Instance id.
	 *
	 * @return int
	 */
	public function get_instance_id() {
		return 0;
	}
}

/**
 * An order that was shipped by something.
 */
class WC_ESM_Shipped_Order extends WC_ESM_Fake_Order {

	/**
	 * Shipping lines.
	 *
	 * @var array
	 */
	public $shipping = array();

	/**
	 * Order id.
	 *
	 * @var int
	 */
	public $id = 1;

	/**
	 * Constructor.
	 *
	 * @param string $method_id Method the order was shipped by.
	 * @param array  $refs      Label references already on the order.
	 * @param int    $id        Order id.
	 */
	public function __construct( $method_id, $refs = array(), $id = 1 ) {
		$this->shipping = array( new WC_ESM_Fake_Shipping_Item( $method_id ) );
		$this->id       = $id;

		if ( $refs ) {
			$this->meta[ WC_ESM_Shipment::LABEL_REFS ] = $refs;
		}
	}

	/**
	 * Shipping lines.
	 *
	 * @return array
	 */
	public function get_shipping_methods() {
		return $this->shipping;
	}

	/**
	 * Order id.
	 *
	 * @return int
	 */
	public function get_id() {
		return $this->id;
	}

	/**
	 * Order number.
	 *
	 * @return string
	 */
	public function get_order_number() {
		return (string) $this->id;
	}
}

/**
 * A carrier that hands back whatever labels the test prepared.
 */
class WC_ESM_Labelling_Provider extends WC_ESM_Shipment_Provider {

	/**
	 * Declared features.
	 *
	 * @var array
	 */
	protected $features = array( 'labels' );

	/**
	 * Id.
	 *
	 * @var string
	 */
	public $id = 'labeller';

	/**
	 * Method it carries.
	 *
	 * @var string
	 */
	public $method = 'labeller_method';

	/**
	 * What fetch_labels() answers.
	 *
	 * @var WC_ESM_Shipment_Result|null
	 */
	public $answer = null;

	/**
	 * References it was asked for.
	 *
	 * @var array
	 */
	public $asked_for = array();

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function get_id() {
		return $this->id;
	}

	/**
	 * Title.
	 *
	 * @return string
	 */
	public function get_title() {
		return ucfirst( $this->id );
	}

	/**
	 * Which methods this carries.
	 *
	 * @param string $method_id Method id.
	 *
	 * @return bool
	 */
	public function carries( $method_id ) {
		return $this->method === $method_id;
	}

	/**
	 * Answer as instructed.
	 *
	 * @param array $refs References.
	 *
	 * @return WC_ESM_Shipment_Result
	 */
	public function fetch_labels( $refs ) {
		$this->asked_for = $refs;

		return $this->answer ? $this->answer : WC_ESM_Shipment_Result::success( array( 'pdf' => '%PDF-' . $this->id ) );
	}
}

/**
 * Tests for WC_ESM_Shipment_Labels.
 */
class Test_Shipment_Labels extends WC_ESM_Test_Case {

	/**
	 * Merging two carriers' labels reaches the merger, which asks WordPress
	 * for a temporary file; the tests have PHP's own instead.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		Brain\Monkey\Functions\when( 'wp_tempnam' )->alias(
			static function () {
				return tempnam( sys_get_temp_dir(), 'wcesm' );
			}
		);
	}

	/**
	 * A registry with two labelling carriers in it.
	 *
	 * @return array registry, first carrier, second carrier
	 */
	protected function two_carriers() {
		$one         = new WC_ESM_Labelling_Provider();
		$two         = new WC_ESM_Labelling_Provider();
		$two->id     = 'other';
		$two->method = 'other_method';

		$registry = new WC_ESM_Shipment_Registry();
		$registry->register( $one );
		$registry->register( $two );

		return array( $registry, $one, $two );
	}

	/**
	 * Orders are grouped by the carrier that carries them, so each carrier
	 * is asked once for all of its labels rather than once per order.
	 *
	 * @return void
	 */
	public function test_orders_are_grouped_by_carrier() {
		list( $registry ) = $this->two_carriers();

		$groups = WC_ESM_Shipment_Labels::group(
			array(
				new WC_ESM_Shipped_Order( 'labeller_method', array( 'r1' ), 1 ),
				new WC_ESM_Shipped_Order( 'other_method', array( 'r2' ), 2 ),
				new WC_ESM_Shipped_Order( 'labeller_method', array( 'r3' ), 3 ),
			),
			$registry
		);

		$this->assertSame( array( 'labeller', 'other' ), array_keys( $groups ) );
		$this->assertSame( array( 'r1', 'r3' ), $groups['labeller']['refs'] );
	}

	/**
	 * An order no carrier here carries is not ours to print.
	 *
	 * @return void
	 */
	public function test_an_order_we_do_not_carry_is_skipped() {
		list( $registry ) = $this->two_carriers();

		$groups = WC_ESM_Shipment_Labels::group(
			array( new WC_ESM_Shipped_Order( 'flat_rate', array( 'r1' ), 1 ) ),
			$registry
		);

		$this->assertSame( array(), $groups );
	}

	/**
	 * An order that was never sent to the carrier has no reference to print
	 * by, and is reported rather than silently dropped.
	 *
	 * @return void
	 */
	public function test_an_unsent_order_is_reported() {
		list( $registry ) = $this->two_carriers();

		$outcome = WC_ESM_Shipment_Labels::collect(
			array( new WC_ESM_Shipped_Order( 'labeller_method', array(), 7 ) ),
			$registry
		);

		$this->assertSame( '', $outcome['pdf'] );
		$this->assertNotEmpty( $outcome['problems'] );
		$this->assertStringContainsString( '7', $outcome['problems'][0] );
	}

	/**
	 * Two carriers' labels come back as one document.
	 *
	 * @return void
	 */
	public function test_two_carriers_labels_become_one_document() {
		list( $registry, $one, $two ) = $this->two_carriers();

		$outcome = WC_ESM_Shipment_Labels::collect(
			array(
				new WC_ESM_Shipped_Order( 'labeller_method', array( 'r1' ), 1 ),
				new WC_ESM_Shipped_Order( 'other_method', array( 'r2' ), 2 ),
			),
			$registry
		);

		$this->assertSame( array( 'r1' ), $one->asked_for );
		$this->assertSame( array( 'r2' ), $two->asked_for );
		$this->assertSame( 2, $outcome['printed'] );
		$this->assertSame( array(), $outcome['problems'] );
	}

	/**
	 * One carrier failing does not stop the others printing; what failed is
	 * reported alongside what worked.
	 *
	 * @return void
	 */
	public function test_one_carrier_failing_does_not_stop_the_rest() {
		list( $registry, $one, $two ) = $this->two_carriers();
		$one->answer                  = WC_ESM_Shipment_Result::failure( 'Smartposti was down.' );

		$outcome = WC_ESM_Shipment_Labels::collect(
			array(
				new WC_ESM_Shipped_Order( 'labeller_method', array( 'r1' ), 1 ),
				new WC_ESM_Shipped_Order( 'other_method', array( 'r2' ), 2 ),
			),
			$registry
		);

		$this->assertSame( '%PDF-other', $outcome['pdf'] );
		$this->assertSame( 1, $outcome['printed'] );
		$this->assertStringContainsString( 'Smartposti was down.', $outcome['problems'][0] );
	}

	/**
	 * A carrier that prints no labels at all says so once, for the whole
	 * group, rather than once per order.
	 *
	 * @return void
	 */
	public function test_a_carrier_that_prints_nothing_says_so_once() {
		$provider         = new WC_ESM_Labelling_Provider();
		$provider->id     = 'cleveron';
		$provider->method = 'cleveron_office';

		$silent = new class() extends WC_ESM_Labelling_Provider {
			/**
			 * Declared features: none.
			 *
			 * @var array
			 */
			protected $features = array();
		};
		$silent->id     = 'cleveron';
		$silent->method = 'cleveron_office';

		$registry = new WC_ESM_Shipment_Registry();
		$registry->register( $silent );

		$outcome = WC_ESM_Shipment_Labels::collect(
			array(
				new WC_ESM_Shipped_Order( 'cleveron_office', array( 'r1' ), 1 ),
				new WC_ESM_Shipped_Order( 'cleveron_office', array( 'r2' ), 2 ),
			),
			$registry
		);

		$this->assertCount( 1, $outcome['problems'] );
		$this->assertSame( 0, $outcome['printed'] );
	}

	/**
	 * Nothing selected is not an error, just nothing to print.
	 *
	 * @return void
	 */
	public function test_nothing_selected_prints_nothing() {
		list( $registry ) = $this->two_carriers();

		$outcome = WC_ESM_Shipment_Labels::collect( array(), $registry );

		$this->assertSame( '', $outcome['pdf'] );
		$this->assertSame( 0, $outcome['printed'] );
	}
}

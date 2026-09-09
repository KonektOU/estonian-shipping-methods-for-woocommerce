<?php
/**
 * Base class for the plugin's unit tests.
 *
 * @package Estonian_Shipping_Methods_For_WooCommerce
 */

use Brain\Monkey;
use PHPUnit\Framework\TestCase;

/**
 * Sets Brain Monkey up and stubs the WordPress functions the pure classes reach for.
 */
abstract class WC_ESM_Test_Case extends TestCase {

	/**
	 * Start Brain Monkey and stub the handful of WordPress functions used.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Monkey\Functions\stubs(
			array(
				'__'                  => null,
				'esc_html'            => null,
				'esc_attr'            => null,
				'esc_url'             => null,
				'sanitize_text_field' => null,
				'wp_json_encode'      => static function ( $data ) {
					return json_encode( $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				},
			)
		);

		Monkey\Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value ) {
				return $value;
			}
		);

		Monkey\Functions\when( 'wp_parse_args' )->alias(
			static function ( $args, $defaults = array() ) {
				return array_merge( (array) $defaults, (array) $args );
			}
		);

		Monkey\Functions\when( 'wp_list_pluck' )->alias(
			static function ( $list, $field ) {
				return array_map(
					static function ( $item ) use ( $field ) {
						return isset( $item[ $field ] ) ? $item[ $field ] : null;
					},
					(array) $list
				);
			}
		);
	}

	/**
	 * Tear Brain Monkey down.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A snapshot of an order going to an Estonian parcel terminal.
	 *
	 * Individual tests override the keys they care about.
	 *
	 * @param array $overrides Keys to replace.
	 *
	 * @return array
	 */
	protected function snapshot( $overrides = array() ) {
		return array_replace_recursive(
			array(
				'order_id'     => 1234,
				'order_number' => '1234',
				'method_id'    => 'smartpost_estonia',
				'terminal_id'  => '01007220',
				'terminal'     => array(
					'place_id'    => '01007220',
					'name'        => 'Tallinna Lasnamäe Maksimarket',
					'city'        => 'Tallinn',
					'address'     => 'J. Smuuli tee 43',
					'country'     => 'EE',
					'postalcode'  => '11415',
					'routingcode' => '3203',
				),
				'recipient'    => array(
					'name'  => 'Mari Maasikas',
					'phone' => '+37255512345',
					'email' => 'mari@example.com',
				),
				'address'      => array(
					'street'    => 'Pikk',
					'house'     => '12',
					'apartment' => '3',
					'city'      => 'Tallinn',
					'postcode'  => '10123',
					'country'   => 'EE',
				),
				'weight'       => 1.25,
				'cod_amount'   => 0.0,
				'currency'     => 'EUR',
				'content'      => 'Testpood - Order #1234',
				'timewindow'   => '1',
				'base_country' => 'EE',
				'created_at'   => '2026-08-20T10:15:00+03:00',
				'shop_name'    => 'Testpood',
			),
			$overrides
		);
	}
}

<?php
/**
 * Base test case with Brain Monkey and an in-memory option store.
 *
 * @package WP_Mail_Validator
 */

namespace WP_Mail_Validator\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

abstract class TestCase extends \PHPUnit\Framework\TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Fake wp_options table.
	 *
	 * @var array
	 */
	protected $wp_options = array();

	/**
	 * Fake transients: name => array( value, expiration ).
	 *
	 * @var array
	 */
	protected $transients = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->wp_options = array();
		$this->transients = array();

		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\stubs(
			array(
				'wp_parse_args'   => static function ( $args, $defaults ) {
					return array_merge( $defaults, (array) $args );
				},
				'is_email'        => static function ( $email ) {
					return false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : false;
				},
				'get_option'      => function ( $name, $default = false ) {
					return array_key_exists( $name, $this->wp_options ) ? $this->wp_options[ $name ] : $default;
				},
				'update_option'   => function ( $name, $value ) {
					$this->wp_options[ $name ] = $value;
					return true;
				},
				'add_option'      => function ( $name, $value ) {
					if ( array_key_exists( $name, $this->wp_options ) ) {
						return false;
					}
					$this->wp_options[ $name ] = $value;
					return true;
				},
				'get_transient'   => function ( $name ) {
					return isset( $this->transients[ $name ] ) ? $this->transients[ $name ][0] : false;
				},
				'set_transient'   => function ( $name, $value, $expiration = 0 ) {
					$this->transients[ $name ] = array( $value, $expiration );
					return true;
				},
				'get_current_user_id' => 0,
				'number_format_i18n' => static function ( $number ) {
					return number_format( (float) $number );
				},
				'home_url'        => 'https://blog.example',
				'wp_parse_url'    => static function ( $url, $component = -1 ) {
					return parse_url( $url, $component ); // phpcs:ignore
				},
				'wp_check_invalid_utf8' => static function ( $text ) {
					return $text;
				},
			)
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Stores plugin options, merged over the defaults.
	 *
	 * @param array $options Options to set.
	 */
	protected function set_plugin_options( array $options ) {
		$this->wp_options[ WP_MAIL_VALIDATOR_OPTION ] = array_merge( wp_mail_validator_defaults(), $options );
	}

	/**
	 * Returns the stored plugin options.
	 *
	 * @return array
	 */
	protected function plugin_options() {
		return $this->wp_options[ WP_MAIL_VALIDATOR_OPTION ];
	}
}

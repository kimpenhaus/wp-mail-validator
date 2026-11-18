<?php
/**
 * Tests for the public API and the integrations in includes/integrations/.
 *
 * @package WP_Mail_Validator
 */

namespace WP_Mail_Validator\Tests\Unit;

use Brain\Monkey\Functions;
use WP_Mail_Validator\Tests\TestCase;
use WP_Mail_Validator_Checker as Checker;

class IntegrationsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\stubs(
			array(
				'is_admin'            => false,
				'wp_unslash'          => static function ( $value ) {
					return stripslashes( $value );
				},
				'sanitize_text_field' => static function ( $value ) {
					return trim( $value );
				},
			)
		);
	}

	protected function tearDown(): void {
		$_POST = array();
		parent::tearDown();
	}

	private function blocked_count() {
		return isset( $this->wp_options[ WP_MAIL_VALIDATOR_COUNTER_OPTION ] ) ? $this->wp_options[ WP_MAIL_VALIDATOR_COUNTER_OPTION ] : 0;
	}

	/*
	 * Public API
	 */

	public function test_api_rejects_and_counts_disposable_address() {
		$this->set_plugin_options( array() );

		$this->assertFalse( wp_mail_validator_is_valid_email( 'bob@mailinator.com' ) );
		$this->assertSame( 1, $this->blocked_count() );
	}

	public function test_api_does_not_wait_for_the_mailbox_when_checking_in_the_background() {
		$this->set_plugin_options( array() );
		Functions\expect( 'wp_mail_validator_check_mail_address' )->once()->with( 'bob@example.com', true )->andReturn( Checker::DEFERRED );

		$this->assertTrue( wp_mail_validator_is_valid_email( 'bob@example.com' ) );
		$this->assertSame( 0, $this->blocked_count() );
	}

	public function test_api_probes_the_mailbox_when_background_check_is_off() {
		$this->set_plugin_options( array( 'check_mailbox_in_background' => 'no' ) );
		Functions\expect( 'wp_mail_validator_check_mail_address' )->once()->with( 'bob@example.com', false )->andReturn( Checker::INVALID_MAIL );

		$this->assertFalse( wp_mail_validator_is_valid_email( 'bob@example.com' ) );
	}

	public function test_api_filter() {
		$this->set_plugin_options( array() );

		$this->assertFalse( wp_mail_validator_filter_is_valid_email( true, 'bob@mailinator.com' ) );

		Functions\expect( 'wp_mail_validator_is_valid_email' )->never();
		$this->assertFalse( wp_mail_validator_filter_is_valid_email( false, 'bob@example.com' ), 'An address already refused is not checked again.' );
	}

	/*
	 * WooCommerce
	 */

	public function test_woocommerce_account_with_disposable_address_is_refused() {
		$this->set_plugin_options( array( 'check_registrations' => 'yes' ) );
		$errors = \Mockery::mock( 'WP_Error' );
		$errors->shouldReceive( 'add' )->once()->with( 'wp_mail-validator-registration-error', 'This email address cannot be used. Please register with a different one.' );

		$this->assertSame( $errors, wp_mail_validator_validate_woocommerce_registration( $errors, 'bob', 'bob@mailinator.com' ) );
		$this->assertSame( 1, $this->blocked_count() );
	}

	public function test_woocommerce_accounts_are_not_checked_when_registrations_are_not() {
		$this->set_plugin_options( array() );
		$errors = \Mockery::mock( 'WP_Error' );
		$errors->shouldNotReceive( 'add' );

		wp_mail_validator_validate_woocommerce_registration( $errors, 'bob', 'bob@mailinator.com' );
	}

	/*
	 * Multisite
	 */

	private function signup( $existing_email_errors = array() ) {
		$errors = \Mockery::mock( 'WP_Error' );
		$errors->shouldReceive( 'get_error_messages' )->with( 'user_email' )->andReturn( $existing_email_errors );

		return array(
			'user_name'  => 'bob',
			'user_email' => 'bob@mailinator.com',
			'errors'     => $errors,
		);
	}

	public function test_multisite_signup_with_disposable_address_is_refused() {
		$this->set_plugin_options( array( 'check_registrations' => 'yes' ) );
		$signup = $this->signup();
		$signup['errors']->shouldReceive( 'add' )->once()->with( 'user_email', \Mockery::type( 'string' ) );

		$this->assertSame( $signup, wp_mail_validator_validate_multisite_signup( $signup ) );
	}

	public function test_multisite_signup_is_not_checked_when_registrations_are_not() {
		$this->set_plugin_options( array() );
		$signup = $this->signup();
		$signup['errors']->shouldNotReceive( 'add' );

		wp_mail_validator_validate_multisite_signup( $signup );
	}

	public function test_multisite_signup_refused_by_wordpress_is_not_checked_again() {
		$this->set_plugin_options( array( 'check_registrations' => 'yes' ) );
		$signup = $this->signup( array( 'Sorry, that email address is already used!' ) );
		Functions\expect( 'wp_mail_validator_rejects_registration' )->never();

		wp_mail_validator_validate_multisite_signup( $signup );
	}

	public function test_users_added_in_the_dashboard_are_not_checked() {
		$this->set_plugin_options( array( 'check_registrations' => 'yes' ) );
		Functions\when( 'is_admin' )->justReturn( true );
		$signup = $this->signup();
		$signup['errors']->shouldNotReceive( 'add' );

		wp_mail_validator_validate_multisite_signup( $signup );
	}

	/*
	 * Contact Form 7
	 */

	private function cf7_result( $field_is_valid = true ) {
		$result = \Mockery::mock( 'WPCF7_Validation' );
		$result->shouldReceive( 'is_valid' )->with( 'your-email' )->andReturn( $field_is_valid );

		return $result;
	}

	private function cf7_tag() {
		return (object) array(
			'type' => 'email*',
			'name' => 'your-email',
		);
	}

	public function test_cf7_field_with_disposable_address_is_invalid() {
		$this->set_plugin_options( array( 'check_contact_form_7' => 'yes' ) );
		$_POST['your-email'] = ' bob@mailinator.com ';
		$tag    = $this->cf7_tag();
		$result = $this->cf7_result();
		$result->shouldReceive( 'invalidate' )->once()->with( $tag, 'This email address cannot be used. Please enter a different one.' );

		$this->assertSame( $result, wp_mail_validator_validate_contact_form_7( $result, $tag ) );
		$this->assertSame( 1, $this->blocked_count() );
	}

	public function test_cf7_field_that_passes_stays_valid() {
		$this->set_plugin_options( array( 'check_contact_form_7' => 'yes' ) );
		$_POST['your-email'] = 'bob@example.com';
		Functions\expect( 'wp_mail_validator_is_valid_email' )->once()->with( 'bob@example.com' )->andReturn( true );
		$result = $this->cf7_result();
		$result->shouldNotReceive( 'invalidate' );

		wp_mail_validator_validate_contact_form_7( $result, $this->cf7_tag() );
	}

	public function test_cf7_is_not_checked_by_default() {
		$this->set_plugin_options( array() );
		$_POST['your-email'] = 'bob@mailinator.com';
		$result = $this->cf7_result();
		$result->shouldNotReceive( 'invalidate' );

		wp_mail_validator_validate_contact_form_7( $result, $this->cf7_tag() );
	}

	/**
	 * @dataProvider cf7_fields_not_checked
	 */
	public function test_cf7_fields_that_are_not_checked( $post, $field_is_valid ) {
		$this->set_plugin_options( array( 'check_contact_form_7' => 'yes' ) );
		$_POST = $post;
		Functions\expect( 'wp_mail_validator_is_valid_email' )->never();
		$result = $this->cf7_result( $field_is_valid );
		$result->shouldNotReceive( 'invalidate' );

		wp_mail_validator_validate_contact_form_7( $result, $this->cf7_tag() );
	}

	public function cf7_fields_not_checked() {
		return array(
			'already invalid' => array( array( 'your-email' => 'bob@mailinator.com' ), false ),
			'empty optional'  => array( array( 'your-email' => '  ' ), true ),
			'not submitted'   => array( array(), true ),
		);
	}
}

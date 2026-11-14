<?php
/**
 * Tests for the plugin functions in wp-mail-validator.php.
 *
 * @package WP_Mail_Validator
 */

namespace WP_Mail_Validator\Tests\Unit;

use Brain\Monkey\Functions;
use WP_Mail_Validator\Tests\TestCase;
use WP_Mail_Validator_Checker as Checker;

class PluginTest extends TestCase {

	/*
	 * Helpers
	 */

	public function test_split_lines_drops_bom_blank_lines_and_whitespace() {
		$this->assertSame(
			array( 'a.com', 'b.com', 'c.com' ),
			wp_mail_validator_split_lines( "\xEF\xBB\xBFa.com\r\n\r\n  b.com  \nc.com\n" )
		);
	}

	public function test_patterns_match_case_insensitively() {
		$this->assertTrue( wp_mail_validator_matches_patterns( 'Jane@SPAM.example', "nothing\n@spam\\.example$" ) );
		$this->assertFalse( wp_mail_validator_matches_patterns( 'jane@example.com', "@spam\\.example$" ) );
	}

	public function test_patterns_may_contain_the_delimiter_and_slashes() {
		$this->assertTrue( wp_mail_validator_matches_patterns( 'a#b@example.com', 'a#b' ) );
		$this->assertTrue( wp_mail_validator_matches_patterns( 'a/b@example.com', 'a/b' ) );
	}

	public function test_invalid_pattern_does_not_match_or_break() {
		$this->assertFalse( wp_mail_validator_matches_patterns( 'jane@example.com', '(unclosed' ) );
	}

	public function test_bundled_disposable_list_is_loaded() {
		$this->assertGreaterThan( 1000, count( wp_mail_validator_disposable_domains() ) );
	}

	public function test_disposable_domain_and_its_subdomains_are_detected() {
		$this->assertTrue( wp_mail_validator_is_disposable_domain( 'mailinator.com' ) );
		$this->assertTrue( wp_mail_validator_is_disposable_domain( 'eu.MAILINATOR.com' ) );
		$this->assertFalse( wp_mail_validator_is_disposable_domain( 'gmail.com' ) );
		$this->assertFalse( wp_mail_validator_is_disposable_domain( 'com' ) );
		$this->assertFalse( wp_mail_validator_is_disposable_domain( '' ) );
	}

	/*
	 * Result mapping
	 */

	/**
	 * @dataProvider checker_results
	 */
	public function test_checker_result_mapping( $result, $options, $expected ) {
		$this->assertSame( $expected, wp_mail_validator_accepts_result( $result, array_merge( wp_mail_validator_defaults(), $options ) ) );
	}

	public function checker_results() {
		return array(
			'valid'                          => array( Checker::VALID_MAIL, array(), true ),
			'invalid mailbox'                => array( Checker::INVALID_MAIL, array(), false ),
			'unknown server'                 => array( Checker::UNKNOWN_SERVER, array(), false ),
			'bad syntax'                     => array( Checker::SYNTAX_INCORRECT, array(), false ),
			'connection failed, strict'      => array( Checker::CONNECTION_FAILED, array(), false ),
			'connection failed, tolerant'    => array( Checker::CONNECTION_FAILED, array( 'ignore_failed_connection' => 'yes' ), true ),
			'request rejected, strict'       => array( Checker::REQUEST_REJECTED, array(), false ),
			'request rejected, tolerant'     => array( Checker::REQUEST_REJECTED, array( 'ignore_request_rejected' => 'yes' ), true ),
			'server unreachable, strict'     => array( Checker::SYNTAX_CORRECT, array(), false ),
			'server unreachable, tolerant'   => array( Checker::SYNTAX_CORRECT, array( 'accept_correct_syntax_on_server_timeout' => 'yes' ), true ),
		);
	}

	/*
	 * Mail-address validation (blacklists only: these return before any DNS lookup)
	 */

	public function test_user_blacklist_marks_spam_and_counts_it() {
		$this->set_plugin_options(
			array(
				'use_user_defined_blacklist' => 'yes',
				'user_defined_blacklist'     => '@evil\.example$',
				'eaten_spam'                 => 41,
			)
		);

		$this->assertSame( 'spam', wp_mail_validator_validate_mail_address( 1, 'bob@evil.example' ) );
		$this->assertSame( 42, $this->plugin_options()['eaten_spam'] );
	}

	public function test_bundled_trashmail_list_is_used_when_no_custom_list_is_set() {
		$this->set_plugin_options( array() );
		$this->assertSame( 'spam', wp_mail_validator_validate_mail_address( 1, 'bob@mailinator.com' ) );
	}

	public function test_custom_trashmail_list_replaces_bundled_list() {
		$this->set_plugin_options( array( 'trashmail_service_blacklist' => 'only-this\.example' ) );
		$this->assertSame( 'spam', wp_mail_validator_validate_mail_address( 1, 'bob@only-this.example' ) );
	}

	public function test_disabled_trashmail_check_is_skipped() {
		$this->set_plugin_options( array( 'use_trashmail_service_blacklist' => 'no', 'check_mailbox' => 'no' ) );
		// Falls through to the syntax check, which fails without any network access.
		Functions\when( 'is_email' )->justReturn( false );
		$this->assertSame( 'spam', wp_mail_validator_validate_mail_address( 1, 'bob@mailinator.com' ) );
		$this->assertSame( 1, $this->plugin_options()['eaten_spam'] );
	}

	/*
	 * Comment filter
	 */

	public function test_comment_already_marked_as_spam_is_not_checked() {
		$this->assertSame( 'spam', wp_mail_validator_validate_comment_mail( 'spam', array( 'comment_author_email' => 'x' ) ) );
	}

	/**
	 * @dataProvider ping_cases
	 */
	public function test_pings( $type, $options, $expected ) {
		$this->set_plugin_options( $options );
		$this->assertSame( $expected, wp_mail_validator_validate_comment_mail( 1, array( 'comment_type' => $type ) ) );
	}

	public function ping_cases() {
		return array(
			'trackback accepted' => array( 'trackback', array(), 1 ),
			'trackback dropped'  => array( 'trackback', array( 'accept_trackbacks' => 'no' ), 'trash' ),
			'pingback accepted'  => array( 'pingback', array(), 1 ),
			'pingback dropped'   => array( 'pingback', array( 'accept_pingbacks' => 'no' ), 'trash' ),
		);
	}

	public function test_comments_of_logged_in_users_are_not_checked() {
		$this->wp_options['require_name_email'] = '1';
		$this->set_plugin_options( array() );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );

		$this->assertSame( 1, wp_mail_validator_validate_comment_mail( 1, array( 'comment_author_email' => 'bob@mailinator.com' ) ) );
	}

	public function test_comments_are_not_checked_when_mail_is_not_required() {
		$this->wp_options['require_name_email'] = '';
		$this->set_plugin_options( array() );

		$this->assertSame( 1, wp_mail_validator_validate_comment_mail( 1, array( 'comment_author_email' => 'bob@mailinator.com' ) ) );
	}

	public function test_guest_comment_with_trashmail_address_is_spam() {
		$this->wp_options['require_name_email'] = '1';
		$this->set_plugin_options( array() );

		$this->assertSame( 'spam', wp_mail_validator_validate_comment_mail( 1, array( 'comment_author_email' => 'bob@mailinator.com' ) ) );
	}

	/*
	 * Registration filter
	 */

	public function test_registration_with_trashmail_address_gets_an_error() {
		$this->set_plugin_options( array( 'check_registrations' => 'yes' ) );
		$errors = \Mockery::mock( 'WP_Error' );
		$errors->shouldReceive( 'add' )->once()->with( 'wp_mail-validator-registration-error', \Mockery::type( 'string' ) );

		$this->assertSame( $errors, wp_mail_validator_validate_registration_mail( $errors, 'bob', 'bob@mailinator.com' ) );
	}

	public function test_registrations_are_not_checked_when_disabled() {
		$this->set_plugin_options( array() );
		$errors = \Mockery::mock( 'WP_Error' );
		$errors->shouldNotReceive( 'add' );

		wp_mail_validator_validate_registration_mail( $errors, 'bob', 'bob@mailinator.com' );
	}

	/*
	 * Settings sanitizer
	 */

	public function test_sanitizer_whitelists_switches_and_drops_unknown_keys() {
		$this->set_plugin_options( array( 'eaten_spam' => 5 ) );

		$output = wp_mail_validator_sanitize_options(
			array(
				'check_mailbox'            => 'no',
				'accept_pingbacks'         => '<script>',
				'default_gateway'          => '1.2.3.4',
				'wp_mail_validator_hacked' => 'x',
			)
		);

		$this->assertSame( 'no', $output['check_mailbox'] );
		$this->assertSame( 'no', $output['accept_pingbacks'] );
		$this->assertSame( 5, $output['eaten_spam'], 'Counter is kept when the form does not send it.' );
		$this->assertSame( array_keys( wp_mail_validator_defaults() ), array_keys( $output ) );
	}

	public function test_sanitizer_keeps_regex_backslashes_and_removes_invalid_patterns() {
		$this->set_plugin_options( array() );
		Functions\expect( 'add_settings_error' )->once()->with( WP_MAIL_VALIDATOR_OPTION, 'invalid_user_defined_blacklist', \Mockery::pattern( '/\(broken/' ) );

		$output = wp_mail_validator_sanitize_options( array( 'user_defined_blacklist' => "@spam\\.example$\r\n(broken\n\n@spam\\.example$" ) );

		$this->assertSame( '@spam\.example$', $output['user_defined_blacklist'] );
	}

	public function test_restore_button_resets_trashmail_list_to_bundled() {
		$this->set_plugin_options( array( 'trashmail_service_blacklist' => 'custom' ) );

		$output = wp_mail_validator_sanitize_options( array( 'trashmail_service_blacklist' => 'custom', 'restore_trashmail_blacklist' => '1' ) );

		$this->assertSame( '', $output['trashmail_service_blacklist'] );
	}

	public function test_sanitizer_handles_non_array_input() {
		$this->set_plugin_options( array() );
		$this->assertSame( wp_mail_validator_defaults(), wp_mail_validator_sanitize_options( 'garbage' ) );
	}

	/*
	 * Upgrade
	 */

	public function test_upgrade_switches_old_default_trashmail_list_to_bundled_list() {
		$old_default = file_get_contents( __DIR__ . '/fixtures/trash.mail.service.blacklist-0.6.5' );
		$this->wp_options[ WP_MAIL_VALIDATOR_OPTION ] = array(
			'trashmail_service_blacklist' => $old_default,
			'default_gateway'             => '',
			'eaten_spam'                  => '3',
		);

		wp_mail_validator_upgrade();

		$this->assertSame( '', $this->plugin_options()['trashmail_service_blacklist'] );
		$this->assertSame( WP_MAIL_VALIDATOR_VERSION, $this->plugin_options()['version'] );
		$this->assertArrayNotHasKey( 'default_gateway', $this->plugin_options() );
	}

	public function test_upgrade_keeps_a_user_edited_trashmail_list() {
		$this->set_plugin_options( array( 'trashmail_service_blacklist' => "my.example\nother.example" ) );

		wp_mail_validator_upgrade();

		$this->assertSame( "my.example\nother.example", $this->plugin_options()['trashmail_service_blacklist'] );
	}

	public function test_upgrade_runs_only_once_per_version() {
		$this->set_plugin_options( array( 'version' => WP_MAIL_VALIDATOR_VERSION ) );
		Functions\expect( 'update_option' )->never();

		wp_mail_validator_upgrade();
	}

	/*
	 * Theme functions
	 */

	public function test_info_label_contains_counter() {
		$this->set_plugin_options( array( 'eaten_spam' => 7 ) );
		$this->assertStringContainsString( '<strong>7</strong>', wp_mail_validator_info_label( '<p>', '</p>' ) );
	}

	public function test_version_comes_from_constant() {
		$this->assertSame( WP_MAIL_VALIDATOR_VERSION, wp_mail_validator_version() );
	}
}

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
	public function test_only_negative_checker_results_reject( $result, $rejects ) {
		$this->assertSame( $rejects, Checker::rejects( $result ) );
	}

	public function checker_results() {
		return array(
			'valid'          => array( Checker::VALID_MAIL, false ),
			'catch-all'      => array( Checker::CATCH_ALL, false ),
			'unknown'        => array( Checker::UNKNOWN, false ),
			'invalid'        => array( Checker::INVALID_MAIL, true ),
			'unknown server' => array( Checker::UNKNOWN_SERVER, true ),
			'bad syntax'     => array( Checker::SYNTAX_INCORRECT, true ),
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
			)
		);
		$this->wp_options[ WP_MAIL_VALIDATOR_COUNTER_OPTION ] = 41;

		$this->assertSame( 'spam', wp_mail_validator_validate_mail_address( 1, 'bob@evil.example' ) );
		$this->assertSame( 42, $this->wp_options[ WP_MAIL_VALIDATOR_COUNTER_OPTION ] );
	}

	public function test_disposable_address_is_spam() {
		$this->set_plugin_options( array() );
		$this->assertSame( 'spam', wp_mail_validator_validate_mail_address( 1, ' bob@Mailinator.com ' ) );
	}

	public function test_disabled_trashmail_check_is_skipped() {
		$this->set_plugin_options( array( 'use_trashmail_service_blacklist' => 'no', 'check_mailbox' => 'no' ) );
		// Falls through to the syntax check, which fails without any network access.
		Functions\when( 'is_email' )->justReturn( false );
		$this->assertSame( 'spam', wp_mail_validator_validate_mail_address( 1, 'bob@mailinator.com' ) );
		$this->assertSame( 1, $this->wp_options[ WP_MAIL_VALIDATOR_COUNTER_OPTION ] );
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
		$this->set_plugin_options( array( 'check_registrations' => 'yes' ) );

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
		$this->assertSame( 'yes', $output['check_registrations'], 'Settings not submitted keep their value.' );
		$this->assertSame( array_keys( wp_mail_validator_defaults() ), array_keys( $output ) );
	}

	public function test_sanitizer_keeps_regex_backslashes_and_removes_invalid_patterns() {
		$this->set_plugin_options( array() );
		Functions\expect( 'add_settings_error' )->once()->with( WP_MAIL_VALIDATOR_OPTION, 'invalid_user_defined_blacklist', \Mockery::pattern( '/\(broken/' ) );

		$output = wp_mail_validator_sanitize_options( array( 'user_defined_blacklist' => "@spam\\.example$\r\n(broken\n\n@spam\\.example$" ) );

		$this->assertSame( '@spam\.example$', $output['user_defined_blacklist'] );
	}

	public function test_sanitizer_handles_non_array_input() {
		$this->set_plugin_options( array() );
		$this->assertSame( wp_mail_validator_defaults(), wp_mail_validator_sanitize_options( 'garbage' ) );
	}

	/*
	 * Upgrade
	 */

	private function store_legacy_options( array $options ) {
		$this->wp_options[ WP_MAIL_VALIDATOR_OPTION ] = $options;
	}

	private function legacy_list( $version ) {
		return file_get_contents( __DIR__ . '/fixtures/trash.mail.service.blacklist-' . $version );
	}

	/**
	 * @dataProvider legacy_versions
	 */
	public function test_upgrade_drops_shipped_trashmail_list_and_migrates_counter( $version ) {
		$this->store_legacy_options(
			array(
				'trashmail_service_blacklist' => str_replace( "\n", "\r\n", $this->legacy_list( $version ) ),
				'default_gateway'             => '',
				'eaten_spam'                  => '3',
				'check_registrations'         => 'yes',
			)
		);

		wp_mail_validator_upgrade();

		$this->assertSame( array_merge( wp_mail_validator_defaults(), array( 'check_registrations' => 'yes' ) ), $this->plugin_options() );
		$this->assertSame( 3, $this->wp_options[ WP_MAIL_VALIDATOR_COUNTER_OPTION ] );
		$this->assertSame( WP_MAIL_VALIDATOR_VERSION, $this->wp_options[ WP_MAIL_VALIDATOR_VERSION_OPTION ] );
	}

	public function legacy_versions() {
		return array(
			'0.5.0' => array( '0.5.0' ),
			'0.6.5' => array( '0.6.5' ),
		);
	}

	public function test_upgrade_moves_admin_added_trashmail_entries_to_user_blacklist() {
		$this->store_legacy_options(
			array(
				'trashmail_service_blacklist' => $this->legacy_list( '0.6.5' ) . "\nmy-own\\.example\nMailinator.com\n",
				'user_defined_blacklist'      => 'existing',
			)
		);

		wp_mail_validator_upgrade();

		$this->assertSame( "existing\nmy-own\\.example", $this->plugin_options()['user_defined_blacklist'] );
		$this->assertSame( 'yes', $this->plugin_options()['use_user_defined_blacklist'] );
	}

	public function test_upgrade_ignores_trashmail_entries_when_check_was_disabled() {
		$this->store_legacy_options(
			array(
				'trashmail_service_blacklist'     => 'my-own\\.example',
				'use_trashmail_service_blacklist' => 'no',
			)
		);

		wp_mail_validator_upgrade();

		$this->assertSame( '', $this->plugin_options()['user_defined_blacklist'] );
		$this->assertSame( 'no', $this->plugin_options()['use_user_defined_blacklist'] );
	}

	public function test_upgrade_keeps_existing_counter_option() {
		$this->store_legacy_options( array( 'eaten_spam' => '3' ) );
		$this->wp_options[ WP_MAIL_VALIDATOR_COUNTER_OPTION ] = 10;

		wp_mail_validator_upgrade();

		$this->assertSame( 10, $this->wp_options[ WP_MAIL_VALIDATOR_COUNTER_OPTION ] );
	}

	public function test_upgrade_runs_only_once_per_version() {
		$this->wp_options[ WP_MAIL_VALIDATOR_VERSION_OPTION ] = WP_MAIL_VALIDATOR_VERSION;
		Functions\expect( 'update_option' )->never();

		wp_mail_validator_upgrade();
	}

	/*
	 * Theme functions
	 */

	public function test_info_label_contains_name_and_counter() {
		$this->wp_options[ WP_MAIL_VALIDATOR_COUNTER_OPTION ] = 1234;
		$label = wp_mail_validator_info_label( '<p>', '</p>' );
		$this->assertStringStartsWith( '<p>Protected by <a href="https://wordpress.org/plugins/wp-mail-validator/"', $label );
		$this->assertStringContainsString( '>Mail Validator</a>: <strong>1,234</strong> spam attacks blocked</p>', $label );
	}

	public function test_info_label_uses_the_singular_for_one_attack() {
		$this->wp_options[ WP_MAIL_VALIDATOR_COUNTER_OPTION ] = 1;
		$this->assertStringEndsWith( '<strong>1</strong> spam attack blocked', wp_mail_validator_info_label() );
	}

	/*
	 * Settings menu
	 */

	public function test_settings_menu_entry_is_translatable() {
		Functions\expect( 'add_options_page' )
			->once()
			->with( 'Mail Validator', 'Mail Validator', 'manage_options', 'wp-mail-validator', 'wp_mail_validator_options_page' );

		wp_mail_validator_add_options_page();
	}

	public function test_version_comes_from_constant() {
		$this->assertSame( WP_MAIL_VALIDATOR_VERSION, wp_mail_validator_version() );
	}
}

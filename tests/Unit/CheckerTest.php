<?php
/**
 * Tests for WP_Mail_Validator_Checker.
 *
 * @package WP_Mail_Validator
 */

namespace WP_Mail_Validator\Tests\Unit;

use WP_Mail_Validator\Tests\TestCase;
use WP_Mail_Validator_Checker as Checker;

/**
 * Checker with DNS and network replaced by fakes; SMTP exposed for socket-pair tests.
 */
class FakeNetworkChecker extends Checker {

	/** @var string[] */
	public $mx_hosts = array( 'mx.example.com' );

	/** @var bool Whether the port 25 test server greets. */
	public $port_25 = true;

	/** @var int[] Results the mailbox probe returns, in order. */
	public $probe_results = array();

	/** @var int Number of mailbox probes. */
	public $probes = 0;

	protected function get_mail_exchangers( $domain ) {
		return $this->mx_hosts;
	}

	protected function smtp_greets( $host ) {
		return $this->port_25;
	}

	protected function probe_mailbox( array $mx_hosts, $mail_address ) {
		$this->probes++;
		return array_shift( $this->probe_results );
	}

	public function smtp( $socket, $mail_address ) {
		return $this->smtp_rcpt_check( $socket, $mail_address );
	}
}

class CheckerTest extends TestCase {

	public function test_domain_of_returns_lowercased_domain() {
		$this->assertSame( 'example.com', Checker::domain_of( 'John.Doe@Example.COM' ) );
		$this->assertSame( 'example.com', Checker::domain_of( '"a@b"@example.com' ) );
		$this->assertSame( '', Checker::domain_of( 'no-at-sign' ) );
	}

	public function test_invalid_syntax_is_rejected() {
		$this->assertSame( Checker::SYNTAX_INCORRECT, ( new FakeNetworkChecker() )->validate( 'not an address' ) );
	}

	/**
	 * Addresses the pre-0.7 regex wrongly rejected.
	 *
	 * @dataProvider modern_addresses
	 */
	public function test_modern_addresses_pass_syntax_check( $address ) {
		$this->assertSame( Checker::VALID_MAIL, ( new FakeNetworkChecker( false ) )->validate( $address ) );
	}

	public function modern_addresses() {
		return array(
			'long TLD'        => array( 'jane@example.online' ),
			'four-letter TLD' => array( 'jane@example.info' ),
			'plus addressing' => array( 'jane+blog@example.com' ),
		);
	}

	public function test_domain_without_mail_server_is_unknown() {
		$checker           = new FakeNetworkChecker();
		$checker->mx_hosts = array();
		$this->assertSame( Checker::UNKNOWN_SERVER, $checker->validate( 'jane@example.com' ) );
	}

	public function test_mailbox_probe_is_skipped_when_disabled() {
		$checker = new FakeNetworkChecker( false );
		$this->assertSame( Checker::VALID_MAIL, $checker->validate( 'jane@example.com' ) );
		$this->assertSame( 0, $checker->probes );
	}

	/*
	 * Port 25 self-test
	 */

	public function test_blocked_port_25_skips_probe_and_is_remembered_for_a_day() {
		$checker          = new FakeNetworkChecker();
		$checker->port_25 = false;

		$this->assertSame( Checker::UNKNOWN, $checker->validate( 'jane@example.com' ) );
		$this->assertSame( 0, $checker->probes );
		$this->assertSame( array( 'blocked', DAY_IN_SECONDS ), $this->transients['wp_mail_validator_port_25'] );
	}

	public function test_port_25_status_is_taken_from_cache() {
		$this->transients['wp_mail_validator_port_25'] = array( 'open', DAY_IN_SECONDS );
		$checker          = new FakeNetworkChecker();
		$checker->port_25 = false; // Would be "blocked" if tested again.

		$this->assertTrue( $checker->port_25_open() );
	}

	/*
	 * Caching
	 */

	public function test_probe_result_is_cached_per_address() {
		$checker                = new FakeNetworkChecker();
		$checker->probe_results = array( Checker::INVALID_MAIL );

		$this->assertSame( Checker::INVALID_MAIL, $checker->validate( 'Jane@Example.com' ) );
		$this->assertSame( Checker::INVALID_MAIL, $checker->validate( 'jane@example.com' ) );
		$this->assertSame( 1, $checker->probes );
		$this->assertSame( array( Checker::INVALID_MAIL, DAY_IN_SECONDS ), $this->transients[ 'wp_mail_validator_address_' . md5( 'jane@example.com' ) ] );
	}

	public function test_inconclusive_result_is_cached_for_an_hour_only() {
		$checker                = new FakeNetworkChecker();
		$checker->probe_results = array( Checker::UNKNOWN );

		$checker->validate( 'jane@example.com' );

		$this->assertSame( HOUR_IN_SECONDS, $this->transients[ 'wp_mail_validator_address_' . md5( 'jane@example.com' ) ][1] );
	}

	public function test_catch_all_domain_is_cached_for_all_its_addresses() {
		$checker                = new FakeNetworkChecker();
		$checker->probe_results = array( Checker::CATCH_ALL );

		$this->assertSame( Checker::CATCH_ALL, $checker->validate( 'jane@example.com' ) );
		$this->assertSame( Checker::CATCH_ALL, $checker->validate( 'john@example.com' ) );
		$this->assertSame( 1, $checker->probes );
	}

	/*
	 * Deferred probe (background check)
	 */

	public function test_deferred_probe_is_needed_until_port_25_was_tested() {
		$checker = new FakeNetworkChecker();

		$this->assertSame( Checker::DEFERRED, $checker->validate( 'jane@example.com', true ) );
		$this->assertSame( 0, $checker->probes );
		$this->assertArrayNotHasKey( 'wp_mail_validator_port_25', $this->transients, 'The port test is slow, too.' );
	}

	public function test_deferred_probe_is_needed_for_uncached_address() {
		$this->transients['wp_mail_validator_port_25'] = array( 'open', DAY_IN_SECONDS );
		$checker = new FakeNetworkChecker();

		$this->assertSame( Checker::DEFERRED, $checker->validate( 'jane@example.com', true ) );
		$this->assertSame( 0, $checker->probes );
	}

	public function test_deferred_probe_uses_cached_results() {
		$this->transients['wp_mail_validator_port_25'] = array( 'open', DAY_IN_SECONDS );
		$this->transients[ 'wp_mail_validator_address_' . md5( 'jane@example.com' ) ] = array( Checker::INVALID_MAIL, DAY_IN_SECONDS );
		$this->transients[ 'wp_mail_validator_domain_' . md5( 'catch-all.example' ) ] = array( 'catch-all', DAY_IN_SECONDS );
		$checker = new FakeNetworkChecker();

		$this->assertSame( Checker::INVALID_MAIL, $checker->validate( 'Jane@Example.com', true ) );
		$this->assertSame( Checker::CATCH_ALL, $checker->validate( 'john@catch-all.example', true ) );
		$this->assertSame( 0, $checker->probes );
	}

	public function test_deferred_probe_is_not_needed_when_port_25_is_blocked() {
		$this->transients['wp_mail_validator_port_25'] = array( 'blocked', DAY_IN_SECONDS );
		$this->assertSame( Checker::UNKNOWN, ( new FakeNetworkChecker() )->validate( 'jane@example.com', true ) );
	}

	public function test_deferred_probe_is_not_needed_when_mailbox_check_is_disabled() {
		$this->assertSame( Checker::VALID_MAIL, ( new FakeNetworkChecker( false ) )->validate( 'jane@example.com', true ) );
	}

	public function test_fast_checks_still_reject_when_probe_is_deferred() {
		$checker           = new FakeNetworkChecker();
		$checker->mx_hosts = array();

		$this->assertSame( Checker::SYNTAX_INCORRECT, $checker->validate( 'not an address', true ) );
		$this->assertSame( Checker::UNKNOWN_SERVER, $checker->validate( 'jane@example.com', true ) );
	}

	/*
	 * SMTP dialogue
	 */

	/**
	 * Runs the SMTP check against canned server replies.
	 *
	 * @param string $server_replies Everything the server says.
	 * @return array { 0: int result, 1: string commands the client sent }
	 */
	private function talk( $server_replies ) {
		list( $client, $server ) = stream_socket_pair( STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP );
		fwrite( $server, $server_replies );
		stream_socket_shutdown( $server, STREAM_SHUT_WR ); // Server says nothing more: reads hit EOF instead of a timeout.

		$result   = ( new FakeNetworkChecker() )->smtp( $client, 'jane@example.com' );
		$commands = stream_get_contents( $server );
		fclose( $server );

		return array( $result, $commands );
	}

	/**
	 * @dataProvider smtp_dialogues
	 */
	public function test_smtp_dialogue( $server_replies, $expected ) {
		$this->assertSame( $expected, $this->talk( $server_replies )[0] );
	}

	public function smtp_dialogues() {
		$ok = "220 mx ready\r\n250-mx hello\r\n250 ENHANCEDSTATUSCODES\r\n250 2.1.0 sender ok\r\n";

		return array(
			'mailbox exists'               => array( $ok . "250 2.1.5 ok\r\n550 5.1.1 no such user\r\n", Checker::VALID_MAIL ),
			'mailbox forwarded'            => array( $ok . "251 2.1.5 will forward\r\n550 5.1.1 no such user\r\n", Checker::VALID_MAIL ),
			'catch-all server'             => array( $ok . "250 2.1.5 ok\r\n250 2.1.5 ok\r\n", Checker::CATCH_ALL ),
			'mailbox unknown (gmail)'      => array( $ok . "550-5.1.1 The email account that you tried to reach does not exist.\r\n550 5.1.1 Please try double-checking.\r\n", Checker::INVALID_MAIL ),
			'null MX recipient'            => array( $ok . "556 5.1.10 recipient has null MX\r\n", Checker::INVALID_MAIL ),
			'unknown without enhanced code' => array( $ok . "550 no such user\r\n", Checker::UNKNOWN ),
			'policy block'                 => array( $ok . "550 5.7.1 access denied, listed at spamhaus\r\n", Checker::UNKNOWN ),
			'greylisted'                   => array( $ok . "451 4.7.1 try again later\r\n", Checker::UNKNOWN ),
			'EHLO refused, HELO accepted'  => array( "220 ready\r\n502 5.5.1 unknown command\r\n250 hello\r\n250 ok\r\n550 5.1.1 unknown\r\n", Checker::INVALID_MAIL ),
			'greeting refuses service'     => array( "554 5.7.1 go away\r\n", Checker::UNKNOWN ),
			'sender rejected'              => array( "220 ready\r\n250 hello\r\n553 5.7.1 sender refused\r\n", Checker::UNKNOWN ),
			'server silent after greeting' => array( "220 ready\r\n", Checker::UNKNOWN ),
			'no greeting at all'           => array( '', Checker::UNKNOWN ),
		);
	}

	public function test_smtp_commands_use_crlf_and_site_host_and_send_no_mail() {
		list( , $commands ) = $this->talk( "220 ready\r\n250 hello\r\n250 ok\r\n250 ok\r\n550 5.1.1 unknown\r\n" );

		$this->assertMatchesRegularExpression(
			'/\AEHLO blog\.example\r\nMAIL FROM:<wordpress@blog\.example>\r\nRCPT TO:<jane@example\.com>\r\nRCPT TO:<wpmv-[0-9a-f]{16}@example\.com>\r\nQUIT\r\n\z/',
			$commands
		);
	}
}

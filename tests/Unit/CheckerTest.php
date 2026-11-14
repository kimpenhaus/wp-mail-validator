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
 * Checker with DNS replaced by a fixed list and SMTP exposed for socket-pair tests.
 */
class FakeDnsChecker extends Checker {

	/** @var string[] */
	public $mx_hosts = array( 'mx.example.com' );

	/** @var int|null Result of the mailbox probe, null = run the real probe. */
	public $probe_result = null;

	protected function get_mail_exchangers( $domain ) {
		return $this->mx_hosts;
	}

	protected function probe_mailbox( array $mx_hosts, $mail_address ) {
		return null === $this->probe_result ? parent::probe_mailbox( $mx_hosts, $mail_address ) : $this->probe_result;
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
		$this->assertSame( Checker::SYNTAX_INCORRECT, ( new FakeDnsChecker() )->validate( 'not an address' ) );
	}

	/**
	 * Addresses the pre-0.7 regex wrongly rejected.
	 *
	 * @dataProvider modern_addresses
	 */
	public function test_modern_addresses_pass_syntax_check( $address ) {
		$checker = new FakeDnsChecker( false );
		$this->assertSame( Checker::VALID_MAIL, $checker->validate( $address ) );
	}

	public function modern_addresses() {
		return array(
			'long TLD'        => array( 'jane@example.online' ),
			'four-letter TLD' => array( 'jane@example.info' ),
			'plus addressing' => array( 'jane+blog@example.com' ),
			'surrounding space' => array( '  jane@example.com ' ),
		);
	}

	public function test_domain_without_mail_server_is_unknown() {
		$checker           = new FakeDnsChecker();
		$checker->mx_hosts = array();
		$this->assertSame( Checker::UNKNOWN_SERVER, $checker->validate( 'jane@example.com' ) );
	}

	public function test_mailbox_probe_is_skipped_when_disabled() {
		$checker               = new FakeDnsChecker( false );
		$checker->probe_result = Checker::INVALID_MAIL;
		$this->assertSame( Checker::VALID_MAIL, $checker->validate( 'jane@example.com' ) );
	}

	public function test_mailbox_probe_result_is_returned_when_enabled() {
		$checker               = new FakeDnsChecker( true );
		$checker->probe_result = Checker::INVALID_MAIL;
		$this->assertSame( Checker::INVALID_MAIL, $checker->validate( 'jane@example.com' ) );
	}

	/**
	 * @dataProvider smtp_dialogues
	 */
	public function test_smtp_dialogue( $server_replies, $expected ) {
		list( $client, $server ) = stream_socket_pair( STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP );
		fwrite( $server, $server_replies );
		stream_socket_shutdown( $server, STREAM_SHUT_WR ); // Server says nothing more: reads hit EOF instead of a timeout.

		$result = ( new FakeDnsChecker() )->smtp( $client, 'jane@example.com' );

		$this->assertSame( $expected, $result );
		fclose( $server );
	}

	public function smtp_dialogues() {
		$ok = "220 mx ready\r\n250 hello\r\n250 sender ok\r\n";

		return array(
			'mailbox exists'               => array( $ok . "250 recipient ok\r\n", Checker::VALID_MAIL ),
			'mailbox forwarded'            => array( $ok . "251 will forward\r\n", Checker::VALID_MAIL ),
			'mailbox unknown'              => array( $ok . "550 no such user\r\n", Checker::INVALID_MAIL ),
			'greylisted'                   => array( $ok . "451 try again later\r\n", Checker::REQUEST_REJECTED ),
			'multi-line greeting'          => array( "220-mx.example.com\r\n220-more banner\r\n220 ready\r\n250 hello\r\n250 ok\r\n250 ok\r\n", Checker::VALID_MAIL ),
			'greeting refuses service'     => array( "554 go away\r\n", Checker::REQUEST_REJECTED ),
			'sender rejected'              => array( "220 ready\r\n250 hello\r\n553 sender refused\r\n", Checker::REQUEST_REJECTED ),
			'server silent after greeting' => array( "220 ready\r\n", Checker::REQUEST_REJECTED ),
			'no greeting at all'           => array( '', Checker::CONNECTION_FAILED ),
		);
	}

	public function test_smtp_commands_use_crlf_and_site_host() {
		list( $client, $server ) = stream_socket_pair( STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP );
		fwrite( $server, "220 ready\r\n250 hello\r\n250 ok\r\n250 ok\r\n" );

		( new FakeDnsChecker() )->smtp( $client, 'jane@example.com' );

		$this->assertSame(
			"HELO blog.example\r\nMAIL FROM:<wordpress@blog.example>\r\nRCPT TO:<jane@example.com>\r\nQUIT\r\n",
			stream_get_contents( $server )
		);
		fclose( $server );
	}
}

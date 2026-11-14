<?php
/**
 * Mail-address checks: syntax, DNS (MX / A / AAAA) and an optional SMTP mailbox probe.
 *
 * The SMTP probe follows the idea of the "EMailValidator" class by nepomuk (2003)
 * that earlier versions of this plugin shipped with.
 *
 * @package WP_Mail_Validator
 */

defined( 'ABSPATH' ) || exit;

/**
 * Validates a single mail-address.
 */
class WP_Mail_Validator_Checker {

	const VALID_MAIL        = 10;
	const SYNTAX_CORRECT    = 20;
	const INVALID_MAIL      = -10;
	const UNKNOWN_SERVER    = -20;
	const SYNTAX_INCORRECT  = -30;
	const CONNECTION_FAILED = -40;
	const REQUEST_REJECTED  = -50;

	const SMTP_PORT       = 25;
	const CONNECT_TIMEOUT = 3;
	const READ_TIMEOUT    = 3;
	const MAX_MX_HOSTS    = 2;

	/**
	 * Whether to ask the mail server if the mailbox exists.
	 *
	 * @var bool
	 */
	private $check_mailbox;

	/**
	 * Constructor.
	 *
	 * @param bool $check_mailbox Whether to probe the mailbox via SMTP.
	 */
	public function __construct( $check_mailbox = true ) {
		$this->check_mailbox = (bool) $check_mailbox;
	}

	/**
	 * Validates a mail-address.
	 *
	 * @param string $mail_address Mail-address to validate.
	 * @return int One of the class constants.
	 */
	public function validate( $mail_address ) {
		$mail_address = trim( (string) $mail_address );

		if ( ! is_email( $mail_address ) ) {
			return self::SYNTAX_INCORRECT;
		}

		$mx_hosts = $this->get_mail_exchangers( self::domain_of( $mail_address ) );

		if ( empty( $mx_hosts ) ) {
			return self::UNKNOWN_SERVER;
		}

		if ( ! $this->check_mailbox ) {
			return self::VALID_MAIL;
		}

		return $this->probe_mailbox( $mx_hosts, $mail_address );
	}

	/**
	 * Returns the lower-cased domain part of a mail-address.
	 *
	 * @param string $mail_address Mail-address.
	 * @return string
	 */
	public static function domain_of( $mail_address ) {
		$at = strrpos( $mail_address, '@' );
		return false === $at ? '' : strtolower( substr( $mail_address, $at + 1 ) );
	}

	/**
	 * Returns the mail servers of a domain, ordered by preference.
	 *
	 * Falls back to the domain itself if it has no MX but an A/AAAA record (RFC 5321, 5.1).
	 * A "null MX" (RFC 7505) means the domain does not accept mail at all.
	 *
	 * @param string $domain Domain.
	 * @return string[]
	 */
	protected function get_mail_exchangers( $domain ) {
		$fqdn    = rtrim( $domain, '.' ) . '.';
		$hosts   = array();
		$weights = array();

		if ( getmxrr( $fqdn, $hosts, $weights ) ) {
			array_multisort( $weights, SORT_NUMERIC, $hosts );

			return array_values(
				array_filter(
					$hosts,
					static function ( $host ) {
						return '' !== trim( $host, '.' );
					}
				)
			);
		}

		if ( checkdnsrr( $fqdn, 'A' ) || checkdnsrr( $fqdn, 'AAAA' ) ) {
			return array( $domain );
		}

		return array();
	}

	/**
	 * Asks the domain's mail servers whether they accept mail for the address.
	 *
	 * @param string[] $mx_hosts     Mail servers, ordered by preference.
	 * @param string   $mail_address Mail-address.
	 * @return int
	 */
	protected function probe_mailbox( array $mx_hosts, $mail_address ) {
		$connected = false;
		$result    = self::CONNECTION_FAILED;

		foreach ( array_slice( $mx_hosts, 0, self::MAX_MX_HOSTS ) as $host ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fsockopen -- network socket; a refused connection is an expected outcome, not an error.
			$socket = @fsockopen( $host, self::SMTP_PORT, $errno, $errstr, self::CONNECT_TIMEOUT );

			if ( ! $socket ) {
				continue;
			}

			$connected = true;
			stream_set_timeout( $socket, self::READ_TIMEOUT );
			$result = $this->smtp_rcpt_check( $socket, $mail_address );

			if ( self::CONNECTION_FAILED !== $result && self::REQUEST_REJECTED !== $result ) {
				break;
			}
		}

		// No server reachable (e.g. outgoing port 25 blocked): only the syntax is known to be correct.
		return $connected ? $result : self::SYNTAX_CORRECT;
	}

	/**
	 * Runs HELO / MAIL FROM / RCPT TO on an open connection.
	 *
	 * @param resource $socket       Open SMTP connection.
	 * @param string   $mail_address Recipient to check; validated by is_email(), so it holds no CR/LF.
	 * @return int
	 */
	protected function smtp_rcpt_check( $socket, $mail_address ) {
		$greeting = $this->read_reply( $socket );

		if ( 220 !== $greeting ) {
			$this->close( $socket, false );
			return 0 === $greeting ? self::CONNECTION_FAILED : self::REQUEST_REJECTED;
		}

		$local_host = wp_parse_url( home_url(), PHP_URL_HOST );
		$local_host = $local_host ? $local_host : 'localhost';

		if ( 250 !== $this->command( $socket, 'HELO ' . $local_host )
			|| 250 !== $this->command( $socket, 'MAIL FROM:<wordpress@' . $local_host . '>' ) ) {
			$this->close( $socket );
			return self::REQUEST_REJECTED;
		}

		$reply = $this->command( $socket, 'RCPT TO:<' . $mail_address . '>' );
		$this->close( $socket );

		if ( 250 === $reply || 251 === $reply ) {
			return self::VALID_MAIL;
		}

		// 5xx: mailbox does not exist. Anything else (4xx greylisting, timeout) is not conclusive.
		return ( $reply >= 500 && $reply < 600 ) ? self::INVALID_MAIL : self::REQUEST_REJECTED;
	}

	/**
	 * Sends an SMTP command and returns the reply code.
	 *
	 * @param resource $socket  Open SMTP connection.
	 * @param string   $command Command without line ending.
	 * @return int Reply code, 0 on timeout or closed connection.
	 */
	private function command( $socket, $command ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- network socket, not a file.
		if ( false === fwrite( $socket, $command . "\r\n" ) ) {
			return 0;
		}

		return $this->read_reply( $socket );
	}

	/**
	 * Reads a (possibly multi-line) SMTP reply and returns its code.
	 *
	 * @param resource $socket Open SMTP connection.
	 * @return int Reply code, 0 on timeout or closed connection.
	 */
	private function read_reply( $socket ) {
		for ( $line_count = 0; $line_count < 100; $line_count++ ) {
			$line = fgets( $socket, 1024 );

			if ( false === $line ) {
				return 0;
			}

			// Continuation lines look like "250-…", the last line like "250 …".
			if ( strlen( $line ) < 4 || '-' !== $line[3] ) {
				return (int) substr( $line, 0, 3 );
			}
		}

		return 0;
	}

	/**
	 * Closes an SMTP connection.
	 *
	 * @param resource $socket   Open SMTP connection.
	 * @param bool     $send_quit Whether to say goodbye first.
	 */
	private function close( $socket, $send_quit = true ) {
		if ( $send_quit ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- network socket, not a file.
			fwrite( $socket, "QUIT\r\n" );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- network socket, not a file.
		fclose( $socket );
	}
}

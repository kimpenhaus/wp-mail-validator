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
 *
 * Negative results reject the address; all others let it pass. The mailbox probe is
 * deliberately conservative: only a mail server's explicit "recipient unknown" rejects.
 */
class WP_Mail_Validator_Checker {

	const VALID_MAIL       = 10;
	const CATCH_ALL        = 1;   // The mail server accepts any recipient, so the mailbox could not be verified.
	const UNKNOWN          = 0;   // The mailbox could not be verified (port 25 blocked, greylisting, policy, …).
	const INVALID_MAIL     = -10;
	const UNKNOWN_SERVER   = -20;
	const SYNTAX_INCORRECT = -30;

	const SMTP_PORT       = 25;
	const CONNECT_TIMEOUT = 3;
	const READ_TIMEOUT    = 3;
	const MAX_MX_HOSTS    = 2;

	/**
	 * Domain whose mail server is used to test whether outgoing port 25 is open.
	 */
	const PORT_TEST_DOMAIN = 'gmail.com';

	/**
	 * Enhanced status codes (RFC 3463) that mean "this recipient does not exist".
	 */
	const UNKNOWN_RECIPIENT_CODES = array( '5.1.1', '5.1.10' );

	const TRANSIENT_PREFIX = 'wp_mail_validator_';

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
	 * Whether a result rejects the mail-address.
	 *
	 * @param int $result One of the class constants.
	 * @return bool
	 */
	public static function rejects( $result ) {
		return $result < 0;
	}

	/**
	 * Validates a mail-address.
	 *
	 * @param string $mail_address Mail-address to validate.
	 * @return int One of the class constants.
	 */
	public function validate( $mail_address ) {
		if ( ! is_email( $mail_address ) ) {
			return self::SYNTAX_INCORRECT;
		}

		$domain   = self::domain_of( $mail_address );
		$mx_hosts = $this->get_mail_exchangers( $domain );

		if ( empty( $mx_hosts ) ) {
			return self::UNKNOWN_SERVER;
		}

		if ( ! $this->check_mailbox ) {
			return self::VALID_MAIL;
		}

		if ( ! $this->port_25_open() ) {
			return self::UNKNOWN;
		}

		return $this->cached_probe( $mx_hosts, strtolower( $mail_address ), $domain );
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
	 * Whether this server can open outgoing SMTP connections. Tested once a day.
	 *
	 * @return bool
	 */
	public function port_25_open() {
		$key    = self::TRANSIENT_PREFIX . 'port_25';
		$status = get_transient( $key );

		if ( false === $status ) {
			$mx_hosts = $this->get_mail_exchangers( self::PORT_TEST_DOMAIN );
			$status   = $mx_hosts && $this->smtp_greets( $mx_hosts[0] ) ? 'open' : 'blocked';
			set_transient( $key, $status, DAY_IN_SECONDS );
		}

		return 'open' === $status;
	}

	/**
	 * Probes the mailbox, caching results per address and catch-all servers per domain.
	 *
	 * @param string[] $mx_hosts     Mail servers, ordered by preference.
	 * @param string   $mail_address Lower-cased mail-address.
	 * @param string   $domain       Domain of the mail-address.
	 * @return int
	 */
	private function cached_probe( array $mx_hosts, $mail_address, $domain ) {
		$domain_key  = self::TRANSIENT_PREFIX . 'domain_' . md5( $domain );
		$address_key = self::TRANSIENT_PREFIX . 'address_' . md5( $mail_address );

		if ( 'catch-all' === get_transient( $domain_key ) ) {
			return self::CATCH_ALL;
		}

		$cached = get_transient( $address_key );
		if ( false !== $cached ) {
			return (int) $cached;
		}

		$result = $this->probe_mailbox( $mx_hosts, $mail_address );

		if ( self::CATCH_ALL === $result ) {
			set_transient( $domain_key, 'catch-all', DAY_IN_SECONDS );
		} else {
			// Inconclusive results may change soon (greylisting), definite ones hardly.
			set_transient( $address_key, $result, self::UNKNOWN === $result ? HOUR_IN_SECONDS : DAY_IN_SECONDS );
		}

		return $result;
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
	 * @return int VALID_MAIL, CATCH_ALL, INVALID_MAIL or UNKNOWN.
	 */
	protected function probe_mailbox( array $mx_hosts, $mail_address ) {
		foreach ( array_slice( $mx_hosts, 0, self::MAX_MX_HOSTS ) as $host ) {
			$socket = $this->connect( $host );

			if ( ! $socket ) {
				continue;
			}

			$result = $this->smtp_rcpt_check( $socket, $mail_address );

			if ( self::UNKNOWN !== $result ) {
				return $result;
			}
		}

		return self::UNKNOWN;
	}

	/**
	 * Whether a mail server answers with an SMTP greeting.
	 *
	 * @param string $host Mail server.
	 * @return bool
	 */
	protected function smtp_greets( $host ) {
		$socket = $this->connect( $host );

		if ( ! $socket ) {
			return false;
		}

		$greets = 220 === $this->read_reply( $socket )[0];
		$this->close( $socket );

		return $greets;
	}

	/**
	 * Opens an SMTP connection.
	 *
	 * @param string $host Mail server.
	 * @return resource|false
	 */
	private function connect( $host ) {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fsockopen -- network socket; a refused connection is an expected outcome, not an error.
		$socket = @fsockopen( $host, self::SMTP_PORT, $errno, $errstr, self::CONNECT_TIMEOUT );

		if ( $socket ) {
			stream_set_timeout( $socket, self::READ_TIMEOUT );
		}

		return $socket;
	}

	/**
	 * Runs EHLO / MAIL FROM / RCPT TO on an open connection. No mail is sent.
	 *
	 * If the address is accepted, a random address at the same domain is tried as
	 * well: a server that accepts that too accepts anything (catch-all).
	 *
	 * @param resource $socket       Open SMTP connection.
	 * @param string   $mail_address Recipient to check; validated by is_email(), so it holds no CR/LF.
	 * @return int VALID_MAIL, CATCH_ALL, INVALID_MAIL or UNKNOWN.
	 */
	protected function smtp_rcpt_check( $socket, $mail_address ) {
		if ( 220 !== $this->read_reply( $socket )[0] ) {
			$this->close( $socket );
			return self::UNKNOWN;
		}

		$local_host = wp_parse_url( home_url(), PHP_URL_HOST );
		$local_host = $local_host ? $local_host : 'localhost';

		$greeted = 250 === $this->command( $socket, 'EHLO ' . $local_host )[0]
			|| 250 === $this->command( $socket, 'HELO ' . $local_host )[0];

		if ( ! $greeted || 250 !== $this->command( $socket, 'MAIL FROM:<wordpress@' . $local_host . '>' )[0] ) {
			$this->close( $socket );
			return self::UNKNOWN;
		}

		$reply = $this->command( $socket, 'RCPT TO:<' . $mail_address . '>' );

		if ( self::is_accepted( $reply ) ) {
			$random  = 'wpmv-' . bin2hex( random_bytes( 8 ) ) . '@' . self::domain_of( $mail_address );
			$catches = self::is_accepted( $this->command( $socket, 'RCPT TO:<' . $random . '>' ) );
			$result  = $catches ? self::CATCH_ALL : self::VALID_MAIL;
		} elseif ( in_array( $reply[1], self::UNKNOWN_RECIPIENT_CODES, true ) ) {
			$result = self::INVALID_MAIL;
		} else {
			// Greylisting (4xx), policy blocks (5.7.x), replies without enhanced code: no verdict.
			$result = self::UNKNOWN;
		}

		$this->close( $socket );

		return $result;
	}

	/**
	 * Whether an RCPT reply accepts the recipient.
	 *
	 * @param array $reply Reply as returned by read_reply().
	 * @return bool
	 */
	private static function is_accepted( array $reply ) {
		return 250 === $reply[0] || 251 === $reply[0];
	}

	/**
	 * Sends an SMTP command and returns the reply.
	 *
	 * @param resource $socket  Open SMTP connection.
	 * @param string   $command Command without line ending.
	 * @return array See read_reply().
	 */
	private function command( $socket, $command ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- network socket, not a file.
		if ( false === fwrite( $socket, $command . "\r\n" ) ) {
			return array( 0, '' );
		}

		return $this->read_reply( $socket );
	}

	/**
	 * Reads a (possibly multi-line) SMTP reply.
	 *
	 * @param resource $socket Open SMTP connection.
	 * @return array { 0: int reply code, 0 on timeout or closed connection; 1: string enhanced status code, e.g. "5.1.1", or "" }
	 */
	private function read_reply( $socket ) {
		for ( $line_count = 0; $line_count < 100; $line_count++ ) {
			$line = fgets( $socket, 1024 );

			if ( false === $line ) {
				return array( 0, '' );
			}

			// Continuation lines look like "250-…", the last line like "250 …".
			if ( strlen( $line ) < 4 || '-' !== $line[3] ) {
				$enhanced = preg_match( '/^\d{3} ([245]\.\d{1,3}\.\d{1,3})\b/', $line, $match ) ? $match[1] : '';
				return array( (int) substr( $line, 0, 3 ), $enhanced );
			}
		}

		return array( 0, '' );
	}

	/**
	 * Says goodbye and closes an SMTP connection.
	 *
	 * @param resource $socket Open SMTP connection.
	 */
	private function close( $socket ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- network socket, not a file.
		fwrite( $socket, "QUIT\r\n" );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- network socket, not a file.
		fclose( $socket );
	}
}

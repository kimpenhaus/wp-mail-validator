<?php
/**
 * Plugin Name:       WP-Mail-Validator
 * Plugin URI:        https://github.com/kimpenhaus/wp-mail-validator
 * Description:       Anti-spam plugin that validates the mail-address of commenters and registrants: syntax, mail server (MX record), mailbox, user-defined blacklist and disposable mail services.
 * Version:           0.7.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Marcus Kimpenhaus
 * Author URI:        https://github.com/kimpenhaus/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-mail-validator
 *
 * @package WP_Mail_Validator
 */

defined( 'ABSPATH' ) || exit;

define( 'WP_MAIL_VALIDATOR_VERSION', '0.7.0' );
define( 'WP_MAIL_VALIDATOR_OPTION', 'wp_mail_validator_options' );
define( 'WP_MAIL_VALIDATOR_COUNTER_OPTION', 'wp_mail_validator_eaten_spam' );
define( 'WP_MAIL_VALIDATOR_VERSION_OPTION', 'wp_mail_validator_version' );
define( 'WP_MAIL_VALIDATOR_DISPOSABLE_FILE', __DIR__ . '/data/disposable-email-domains.txt' );

require_once __DIR__ . '/includes/class-wp-mail-validator-checker.php';

/*
 * Options
 */

/**
 * Default settings. Switches are stored as 'yes' / 'no' for compatibility with earlier versions.
 *
 * @return array
 */
function wp_mail_validator_defaults() {
	return array(
		'check_mailbox'                           => 'yes',
		'ignore_failed_connection'                => 'no',
		'ignore_request_rejected'                 => 'no',
		'accept_correct_syntax_on_server_timeout' => 'no',
		'accept_pingbacks'                        => 'yes',
		'accept_trackbacks'                       => 'yes',
		'use_user_defined_blacklist'              => 'no',
		'user_defined_blacklist'                  => '',
		'use_trashmail_service_blacklist'         => 'yes',
		'check_registrations'                     => 'no',
	);
}

/**
 * Returns the settings merged with their defaults; unknown keys are dropped.
 *
 * @return array
 */
function wp_mail_validator_get_options() {
	$stored   = get_option( WP_MAIL_VALIDATOR_OPTION, array() );
	$defaults = wp_mail_validator_defaults();

	return array_intersect_key( wp_parse_args( is_array( $stored ) ? $stored : array(), $defaults ), $defaults );
}

/*
 * Validation
 */

/**
 * Registers the validation hooks.
 */
function wp_mail_validator_init() {
	add_filter( 'pre_comment_approved', 'wp_mail_validator_validate_comment_mail', 99, 2 );
	add_filter( 'registration_errors', 'wp_mail_validator_validate_registration_mail', 99, 3 );
}
add_action( 'init', 'wp_mail_validator_init' );

/**
 * Moves comments with an invalid mail-address to spam.
 *
 * @param int|string|WP_Error $approved     Approval status.
 * @param array               $comment_data Comment data.
 * @return int|string|WP_Error
 */
function wp_mail_validator_validate_comment_mail( $approved, $comment_data ) {
	// Comments already marked as spam or trash (or rejected) need no further investigation.
	if ( 'spam' === $approved || 'trash' === $approved || is_wp_error( $approved ) ) {
		return $approved;
	}

	$options = wp_mail_validator_get_options();
	$type    = isset( $comment_data['comment_type'] ) ? $comment_data['comment_type'] : '';

	// Trackbacks and pingbacks carry no mail-address that could be checked.
	if ( 'trackback' === $type ) {
		return 'yes' === $options['accept_trackbacks'] ? $approved : 'trash';
	}

	if ( 'pingback' === $type ) {
		return 'yes' === $options['accept_pingbacks'] ? $approved : 'trash';
	}

	if ( get_option( 'require_name_email' ) && ! get_current_user_id() ) {
		$mail_address = isset( $comment_data['comment_author_email'] ) ? $comment_data['comment_author_email'] : '';
		return wp_mail_validator_validate_mail_address( $approved, $mail_address );
	}

	return $approved;
}

/**
 * Rejects registrations with an invalid mail-address.
 *
 * @param WP_Error $errors               Registration errors.
 * @param string   $sanitized_user_login User login.
 * @param string   $user_email           User mail-address.
 * @return WP_Error
 */
function wp_mail_validator_validate_registration_mail( $errors, $sanitized_user_login, $user_email ) {
	$options = wp_mail_validator_get_options();

	if ( 'yes' === $options['check_registrations'] && 'spam' === wp_mail_validator_validate_mail_address( '', $user_email ) ) {
		$errors->add( 'wp_mail-validator-registration-error', __( '<strong>ERROR</strong>: Your mail-address is evaluated as spam.', 'wp-mail-validator' ) );
	}

	return $errors;
}

/**
 * Validates a mail-address against the blacklists and the mail server.
 *
 * @param mixed  $approved     Value returned when the address is fine.
 * @param string $mail_address Mail-address.
 * @return mixed $approved or 'spam'.
 */
function wp_mail_validator_validate_mail_address( $approved, $mail_address ) {
	$options      = wp_mail_validator_get_options();
	$mail_address = trim( (string) $mail_address );

	if ( 'yes' === $options['use_user_defined_blacklist']
		&& wp_mail_validator_matches_patterns( $mail_address, $options['user_defined_blacklist'] ) ) {
		return wp_mail_validator_fend_it();
	}

	if ( 'yes' === $options['use_trashmail_service_blacklist']
		&& wp_mail_validator_is_disposable_domain( WP_Mail_Validator_Checker::domain_of( $mail_address ) ) ) {
		return wp_mail_validator_fend_it();
	}

	$checker = new WP_Mail_Validator_Checker( 'yes' === $options['check_mailbox'] );

	return wp_mail_validator_accepts_result( $checker->validate( $mail_address ), $options )
		? $approved
		: wp_mail_validator_fend_it();
}

/**
 * Whether a checker result lets the mail-address pass, given the options.
 *
 * @param int   $result  One of the WP_Mail_Validator_Checker constants.
 * @param array $options Plugin options.
 * @return bool
 */
function wp_mail_validator_accepts_result( $result, array $options ) {
	switch ( $result ) {
		case WP_Mail_Validator_Checker::VALID_MAIL:
			return true;
		case WP_Mail_Validator_Checker::CONNECTION_FAILED:
			return 'yes' === $options['ignore_failed_connection'];
		case WP_Mail_Validator_Checker::REQUEST_REJECTED:
			return 'yes' === $options['ignore_request_rejected'];
		case WP_Mail_Validator_Checker::SYNTAX_CORRECT:
			// No mail server could be reached.
			return 'yes' === $options['accept_correct_syntax_on_server_timeout'];
		default:
			// SYNTAX_INCORRECT, UNKNOWN_SERVER, INVALID_MAIL.
			return false;
	}
}

/**
 * Whether the mail-address matches one of the patterns (one regular expression per line).
 *
 * @param string $mail_address Mail-address.
 * @param string $patterns     Patterns, one per line.
 * @return bool
 */
function wp_mail_validator_matches_patterns( $mail_address, $patterns ) {
	foreach ( wp_mail_validator_split_lines( $patterns ) as $pattern ) {
		if ( 1 === wp_mail_validator_preg_match( $pattern, $mail_address ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Case-insensitive preg_match() for a user-supplied pattern without delimiters.
 *
 * @param string $pattern Pattern without delimiters.
 * @param string $subject Subject.
 * @return int|false 1 on match, 0 on no match, false if the pattern is invalid.
 */
function wp_mail_validator_preg_match( $pattern, $subject ) {
	// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- invalid user patterns are reported on save; never break comment submission.
	return @preg_match( '#' . str_replace( '#', '\#', $pattern ) . '#i', $subject );
}

/**
 * Splits text into trimmed, non-empty lines.
 *
 * @param string $text Text.
 * @return string[]
 */
function wp_mail_validator_split_lines( $text ) {
	$text = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $text );
	return array_values( array_filter( array_map( 'trim', preg_split( '/\R/', $text ) ), 'strlen' ) );
}

/**
 * Bundled list of disposable mail domains as a lookup table. The file holds one
 * lower-cased domain per line (see .github/workflows/update-disposable-domains.yml).
 *
 * @return array<string, int>
 */
function wp_mail_validator_disposable_domains() {
	static $domains = null;

	if ( null === $domains ) {
		$lines   = is_readable( WP_MAIL_VALIDATOR_DISPOSABLE_FILE ) ? file( WP_MAIL_VALIDATOR_DISPOSABLE_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) : false;
		$domains = $lines ? array_flip( $lines ) : array();
	}

	return $domains;
}

/**
 * Whether the domain, or one of its parent domains, is a known disposable mail service.
 *
 * @param string $domain Domain.
 * @return bool
 */
function wp_mail_validator_is_disposable_domain( $domain ) {
	$domains = wp_mail_validator_disposable_domains();
	$labels  = explode( '.', strtolower( $domain ) );

	// Check "a.b.example.com", "b.example.com" and "example.com", but not the bare TLD.
	for ( $i = 0, $last = count( $labels ) - 1; $i < $last; $i++ ) {
		if ( isset( $domains[ implode( '.', array_slice( $labels, $i ) ) ] ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Counts a fended spam attack.
 *
 * @return string 'spam'
 */
function wp_mail_validator_fend_it() {
	update_option( WP_MAIL_VALIDATOR_COUNTER_OPTION, wp_mail_validator_fended_spam_attack_count() + 1 );

	return 'spam';
}

/*
 * Theme functions
 */

/**
 * Returns a "protected by" label.
 *
 * @param string $string_before HTML to prepend.
 * @param string $string_after  HTML to append.
 * @return string
 */
function wp_mail_validator_info_label( $string_before = '', $string_after = '' ) {
	return $string_before
		. esc_html__( 'Protected by', 'wp-mail-validator' )
		. ': <a href="https://wordpress.org/plugins/wp-mail-validator/" title="WP-Mail-Validator" target="_blank" rel="noopener">WP-Mail-Validator</a> - <strong>'
		. esc_html( wp_mail_validator_fended_spam_attack_count() )
		. '</strong> ' . esc_html__( 'spam attacks fended', 'wp-mail-validator' ) . '!'
		. $string_after;
}

/**
 * Returns the number of fended spam attacks.
 *
 * @return int
 */
function wp_mail_validator_fended_spam_attack_count() {
	return absint( get_option( WP_MAIL_VALIDATOR_COUNTER_OPTION, 0 ) );
}

/**
 * Returns the plugin version.
 *
 * @return string
 */
function wp_mail_validator_version() {
	return WP_MAIL_VALIDATOR_VERSION;
}

/*
 * Settings page
 */

/**
 * Registers the setting and its sanitizer.
 */
function wp_mail_validator_register_setting() {
	register_setting(
		'wp_mail_validator',
		WP_MAIL_VALIDATOR_OPTION,
		array(
			'type'              => 'array',
			'sanitize_callback' => 'wp_mail_validator_sanitize_options',
		)
	);
}
add_action( 'admin_init', 'wp_mail_validator_register_setting' );

/**
 * Sanitizes submitted settings. Only known keys are kept, settings not submitted keep their current value.
 *
 * @param mixed $input Submitted settings.
 * @return array
 */
function wp_mail_validator_sanitize_options( $input ) {
	$input  = is_array( $input ) ? $input : array();
	$output = wp_mail_validator_get_options();

	foreach ( array_keys( $output ) as $key ) {
		if ( ! isset( $input[ $key ] ) ) {
			continue;
		}

		$output[ $key ] = 'user_defined_blacklist' === $key
			? wp_mail_validator_sanitize_patterns( $input[ $key ] )
			: ( 'yes' === $input[ $key ] ? 'yes' : 'no' );
	}

	return $output;
}

/**
 * Normalizes a pattern list and drops invalid regular expressions.
 *
 * @param mixed $value Submitted text.
 * @return string
 */
function wp_mail_validator_sanitize_patterns( $value ) {
	$valid   = array();
	$invalid = array();

	foreach ( wp_mail_validator_split_lines( wp_check_invalid_utf8( (string) $value ) ) as $pattern ) {
		if ( false === wp_mail_validator_preg_match( $pattern, '' ) ) {
			$invalid[] = $pattern;
		} else {
			$valid[] = $pattern;
		}
	}

	if ( $invalid ) {
		add_settings_error(
			WP_MAIL_VALIDATOR_OPTION,
			'invalid_user_defined_blacklist',
			sprintf(
				/* translators: %s: comma-separated list of invalid entries. */
				__( 'These blacklist entries are no valid regular expressions and were removed: %s', 'wp-mail-validator' ),
				esc_html( implode( ', ', $invalid ) )
			)
		);
	}

	return implode( "\n", array_unique( $valid ) );
}

/**
 * Adds the settings page.
 */
function wp_mail_validator_add_options_page() {
	add_options_page( 'WP-Mail-Validator', 'WP-Mail-Validator', 'manage_options', 'wp-mail-validator', 'wp_mail_validator_options_page' );
}
add_action( 'admin_menu', 'wp_mail_validator_add_options_page' );

/**
 * Renders a yes/no switch row.
 *
 * @param array  $options     Current options.
 * @param string $key         Option key.
 * @param string $label       Row label.
 * @param string $description Description; may contain <strong>.
 */
function wp_mail_validator_switch_row( $options, $key, $label, $description ) {
	$name = WP_MAIL_VALIDATOR_OPTION . '[' . $key . ']';
	?>
	<tr>
		<th scope="row"><?php echo esc_html( $label ); ?>:</th>
		<td>
			<fieldset>
				<legend class="screen-reader-text"><?php echo esc_html( $label ); ?></legend>
				<label><input name="<?php echo esc_attr( $name ); ?>" type="radio" value="yes" <?php checked( $options[ $key ], 'yes' ); ?> /> <?php esc_html_e( 'Yes', 'wp-mail-validator' ); ?></label>
				<label><input name="<?php echo esc_attr( $name ); ?>" type="radio" value="no" <?php checked( $options[ $key ], 'no' ); ?> /> <?php esc_html_e( 'No', 'wp-mail-validator' ); ?></label>
				<p class="description"><?php echo wp_kses( $description, array( 'strong' => array() ) ); ?></p>
			</fieldset>
		</td>
	</tr>
	<?php
}

/**
 * Renders the settings page.
 */
function wp_mail_validator_options_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$options = wp_mail_validator_get_options();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Settings', 'wp-mail-validator' ); ?></h1>
		<form method="post" action="options.php">
			<?php settings_fields( 'wp_mail_validator' ); ?>
			<h2><?php esc_html_e( 'Comment validation', 'wp-mail-validator' ); ?></h2>
			<table class="form-table" role="presentation">
				<?php
				wp_mail_validator_switch_row( $options, 'check_mailbox', __( 'Verify mailbox on the mail server', 'wp-mail-validator' ), __( 'Connect to the mail server (port 25) to check whether the mailbox exists. Many hosting providers block outgoing connections on port 25 - if valid comments end up in spam, turn this off.', 'wp-mail-validator' ) );
				wp_mail_validator_switch_row( $options, 'ignore_failed_connection', __( 'Accept on connection failures', 'wp-mail-validator' ), __( 'Choose to ignore connection failures with mail servers while validating mail-addresses', 'wp-mail-validator' ) . '.' );
				wp_mail_validator_switch_row( $options, 'ignore_request_rejected', __( 'Accept on rejected requests', 'wp-mail-validator' ), __( 'Choose to ignore rejected request from mail servers while validating mail-addresses', 'wp-mail-validator' ) . '.' );
				wp_mail_validator_switch_row( $options, 'accept_correct_syntax_on_server_timeout', __( 'Accept syntactically correct mail-addresses', 'wp-mail-validator' ), __( 'Choose if syntactically correct mail-addresses can pass when the mail server did not respond in time', 'wp-mail-validator' ) . '.' );
				wp_mail_validator_switch_row(
					$options,
					'use_trashmail_service_blacklist',
					__( 'Reject mail-adresses from trashmail services', 'wp-mail-validator' ),
					sprintf(
						/* translators: %s: number of known disposable mail domains. */
						__( 'Reject mail-addresses from %s known disposable mail services. The list is updated with every plugin release; add your own entries to the blacklist below.', 'wp-mail-validator' ),
						number_format_i18n( count( wp_mail_validator_disposable_domains() ) )
					)
				);
				wp_mail_validator_switch_row( $options, 'use_user_defined_blacklist', __( 'Reject mail-adresses from user-defined blacklist', 'wp-mail-validator' ), __( 'Choose to reject mail-addresses from a user-defined blacklist <strong>(single entry per line)</strong>', 'wp-mail-validator' ) );
				?>
				<tr>
					<th scope="row"><label for="user_defined_blacklist"><?php esc_html_e( 'Blacklist', 'wp-mail-validator' ); ?></label></th>
					<td>
						<textarea id="user_defined_blacklist" name="<?php echo esc_attr( WP_MAIL_VALIDATOR_OPTION ); ?>[user_defined_blacklist]" rows="15" cols="40" class="code"><?php echo esc_textarea( $options['user_defined_blacklist'] ); ?></textarea>
						<p class="description">
							<?php echo esc_html( number_format_i18n( count( wp_mail_validator_split_lines( $options['user_defined_blacklist'] ) ) ) . ' ' . __( 'Entries', 'wp-mail-validator' ) ); ?><br />
							<?php esc_html_e( 'Each line is a regular expression, matched case-insensitively against the whole mail-address.', 'wp-mail-validator' ); ?>
						</p>
					</td>
				</tr>
			</table>
			<h2><?php esc_html_e( 'Ping validation', 'wp-mail-validator' ); ?></h2>
			<table class="form-table" role="presentation">
				<?php
				wp_mail_validator_switch_row( $options, 'accept_pingbacks', __( 'Validate pingbacks', 'wp-mail-validator' ), __( 'Choose to accept Pingbacks <strong>(Pingbacks might be a security risk, because they\'re not carrying a mail-address to validate)</strong>', 'wp-mail-validator' ) );
				wp_mail_validator_switch_row( $options, 'accept_trackbacks', __( 'Validate trackbacks', 'wp-mail-validator' ), __( 'Choose to accept Trackbacks <strong>(Trackbacks might be a security risk, because they\'re not carrying a mail-address to validate)</strong>', 'wp-mail-validator' ) );
				?>
			</table>
			<h2><?php esc_html_e( 'Registrants validation', 'wp-mail-validator' ); ?></h2>
			<table class="form-table" role="presentation">
				<?php wp_mail_validator_switch_row( $options, 'check_registrations', __( 'Validate user-registrations', 'wp-mail-validator' ), __( 'Choose to validate registrants mail-address', 'wp-mail-validator' ) ); ?>
			</table>
			<?php submit_button(); ?>
		</form>
	</div>
	<div class="wrap">
		<h2><?php esc_html_e( 'Statistics', 'wp-mail-validator' ); ?></h2>
		<div class="card">
			<p>
				<?php esc_html_e( 'Version', 'wp-mail-validator' ); ?>: <strong><?php echo esc_html( WP_MAIL_VALIDATOR_VERSION ); ?></strong> |
				<?php esc_html_e( 'Spam attacks fended', 'wp-mail-validator' ); ?>: <strong><?php echo esc_html( number_format_i18n( wp_mail_validator_fended_spam_attack_count() ) ); ?></strong>
			</p>
			<p>
				<a href="https://github.com/kimpenhaus/wp-mail-validator#readme"><?php esc_html_e( 'Documentation', 'wp-mail-validator' ); ?></a> |
				<a href="https://wordpress.org/support/plugin/wp-mail-validator/"><?php esc_html_e( 'Support', 'wp-mail-validator' ); ?></a> |
				<a href="https://github.com/kimpenhaus/wp-mail-validator/issues"><?php esc_html_e( 'Issue Tracker', 'wp-mail-validator' ); ?></a>
			</p>
		</div>
	</div>
	<?php
}

/*
 * Installation and upgrades
 */

/**
 * Migrates data from earlier versions. Runs once per plugin version.
 */
function wp_mail_validator_upgrade() {
	if ( WP_MAIL_VALIDATOR_VERSION === get_option( WP_MAIL_VALIDATOR_VERSION_OPTION ) ) {
		return;
	}

	$stored  = get_option( WP_MAIL_VALIDATOR_OPTION, array() );
	$stored  = is_array( $stored ) ? $stored : array();
	$options = wp_mail_validator_get_options();

	// Up to 0.6.x the spam counter lived inside the settings.
	if ( isset( $stored['eaten_spam'] ) ) {
		add_option( WP_MAIL_VALIDATOR_COUNTER_OPTION, absint( $stored['eaten_spam'] ) );
	}

	// Up to 0.6.x the trashmail list was an editable copy in the settings. Entries the
	// admin added to it are kept in the user-defined blacklist.
	if ( ! empty( $stored['trashmail_service_blacklist'] ) && 'yes' === $options['use_trashmail_service_blacklist'] ) {
		$added = wp_mail_validator_entries_added_to_legacy_list( $stored['trashmail_service_blacklist'] );

		if ( $added ) {
			$options['user_defined_blacklist']     = implode( "\n", array_unique( array_merge( wp_mail_validator_split_lines( $options['user_defined_blacklist'] ), $added ) ) );
			$options['use_user_defined_blacklist'] = 'yes';
		}
	}

	update_option( WP_MAIL_VALIDATOR_OPTION, $options );
	update_option( WP_MAIL_VALIDATOR_VERSION_OPTION, WP_MAIL_VALIDATOR_VERSION );
}
add_action( 'admin_init', 'wp_mail_validator_upgrade', 5 );
register_activation_hook( __FILE__, 'wp_mail_validator_upgrade' );

/**
 * Returns the entries of a pre-0.7 trashmail list that were neither shipped with
 * the plugin nor are covered by the bundled disposable domain list.
 *
 * @param string $legacy_list Stored list, one entry per line.
 * @return string[]
 */
function wp_mail_validator_entries_added_to_legacy_list( $legacy_list ) {
	$shipped = array_flip( require __DIR__ . '/includes/legacy-trashmail-checksums.php' );

	return array_values(
		array_filter(
			wp_mail_validator_split_lines( $legacy_list ),
			static function ( $entry ) use ( $shipped ) {
				$entry = strtolower( $entry );
				return ! isset( $shipped[ hash( 'crc32b', $entry ) ] ) && ! wp_mail_validator_is_disposable_domain( $entry );
			}
		)
	);
}

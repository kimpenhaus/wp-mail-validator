<?php
/**
 * Plugin Name:       Mail Validator – Disposable & Fake Address Blocker
 * Plugin URI:        https://github.com/kimpenhaus/mail-validator
 * Description:       Blocks comment and registration spam from disposable and fake email addresses: checks the syntax, the mail server and the mailbox, known disposable email services and your own blocklist.
 * x-release-please-start-version
 * Version:           0.7.3
 * x-release-please-end
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

define( 'WP_MAIL_VALIDATOR_VERSION', '0.7.3' ); // Bumped by release-please (x-release-please-version).
define( 'WP_MAIL_VALIDATOR_OPTION', 'wp_mail_validator_options' );
define( 'WP_MAIL_VALIDATOR_COUNTER_OPTION', 'wp_mail_validator_eaten_spam' );
define( 'WP_MAIL_VALIDATOR_VERSION_OPTION', 'wp_mail_validator_version' );
define( 'WP_MAIL_VALIDATOR_DISPOSABLE_FILE', __DIR__ . '/data/disposable-email-domains.txt' );
define( 'WP_MAIL_VALIDATOR_HELD_META', '_wp_mail_validator_held' );
define( 'WP_MAIL_VALIDATOR_CRON_HOOK', 'wp_mail_validator_check_held_comment' );

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
		'check_mailbox'                   => 'yes',
		'check_mailbox_in_background'     => 'yes',
		'accept_pingbacks'                => 'yes',
		'accept_trackbacks'               => 'yes',
		'use_user_defined_blacklist'      => 'no',
		'user_defined_blacklist'          => '',
		'use_trashmail_service_blacklist' => 'yes',
		'check_registrations'             => 'no',
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
	add_action( 'wp_insert_comment', 'wp_mail_validator_schedule_held_comment', 10, 2 );
	add_filter( 'notify_moderator', 'wp_mail_validator_notify_moderator', 10, 2 );
	add_action( WP_MAIL_VALIDATOR_CRON_HOOK, 'wp_mail_validator_check_held_comment' );
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
		$result       = wp_mail_validator_check_mail_address( $mail_address, 'yes' === $options['check_mailbox_in_background'] );

		if ( WP_Mail_Validator_Checker::DEFERRED === $result ) {
			return wp_mail_validator_hold_comment( $approved );
		}

		return WP_Mail_Validator_Checker::rejects( $result ) ? wp_mail_validator_fend_it() : $approved;
	}

	return $approved;
}

/*
 * Mailbox check in the background
 *
 * A comment whose mailbox still has to be probed is held (pending) and checked by a
 * WP-Cron event right after it was saved, so the commenter does not wait for slow
 * mail servers. The status WordPress would have given the comment is kept in comment
 * meta and restored if the address passes; moderators are only notified afterwards.
 */

/**
 * Holds a comment for the mailbox check until WordPress has saved it.
 *
 * @param int|string $approved Status WordPress would give the comment, 0 or 1.
 * @return int 0 (pending).
 */
function wp_mail_validator_hold_comment( $approved ) {
	$GLOBALS['wp_mail_validator_held_comment'] = 1 === (int) $approved ? '1' : '0';

	return 0;
}

/**
 * Schedules the mailbox check for a comment held by wp_mail_validator_hold_comment().
 *
 * @param int        $comment_id Comment ID.
 * @param WP_Comment $comment    Comment.
 */
function wp_mail_validator_schedule_held_comment( $comment_id, $comment ) {
	$approved = isset( $GLOBALS['wp_mail_validator_held_comment'] ) ? $GLOBALS['wp_mail_validator_held_comment'] : null;
	unset( $GLOBALS['wp_mail_validator_held_comment'] );

	// Not held by us, or a later filter changed the status (e.g. to spam).
	if ( null === $approved || '0' !== (string) $comment->comment_approved ) {
		return;
	}

	add_comment_meta( $comment_id, WP_MAIL_VALIDATOR_HELD_META, $approved, true );
	wp_schedule_single_event( time(), WP_MAIL_VALIDATOR_CRON_HOOK, array( (int) $comment_id ) );
}

/**
 * Holds back the moderation email while a comment waits for the mailbox check.
 *
 * @param bool $maybe_notify Whether to notify the moderators.
 * @param int  $comment_id   Comment ID.
 * @return bool
 */
function wp_mail_validator_notify_moderator( $maybe_notify, $comment_id ) {
	return $maybe_notify && ! metadata_exists( 'comment', $comment_id, WP_MAIL_VALIDATOR_HELD_META );
}

/**
 * WP-Cron callback: probes the mailbox of a held comment, then moves the comment to
 * spam or gives it the status WordPress would have given it.
 *
 * @param int $comment_id Comment ID.
 */
function wp_mail_validator_check_held_comment( $comment_id ) {
	$comment = get_comment( $comment_id );

	if ( ! $comment || ! metadata_exists( 'comment', $comment->comment_ID, WP_MAIL_VALIDATOR_HELD_META ) ) {
		return;
	}

	$approved = get_comment_meta( $comment->comment_ID, WP_MAIL_VALIDATOR_HELD_META, true );
	delete_comment_meta( $comment->comment_ID, WP_MAIL_VALIDATOR_HELD_META );

	// A moderator has decided in the meantime.
	if ( '0' !== (string) $comment->comment_approved ) {
		return;
	}

	if ( WP_Mail_Validator_Checker::rejects( wp_mail_validator_check_mail_address( $comment->comment_author_email ) ) ) {
		wp_spam_comment( $comment );
		wp_mail_validator_fend_it();
	} elseif ( '1' === $approved ) {
		// Approving notifies the post author, as for any new approved comment.
		wp_set_comment_status( $comment, 'approve' );
	} else {
		wp_new_comment_notify_moderator( $comment->comment_ID );
	}
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
		$errors->add( 'wp_mail-validator-registration-error', __( '<strong>Error:</strong> This email address cannot be used. Please register with a different one.', 'wp-mail-validator' ) );
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
	return WP_Mail_Validator_Checker::rejects( wp_mail_validator_check_mail_address( $mail_address ) )
		? wp_mail_validator_fend_it()
		: $approved;
}

/**
 * Runs all checks on a mail-address according to the settings.
 *
 * @param string $mail_address Mail-address.
 * @param bool   $defer_probe  Return DEFERRED instead of probing the mailbox now (see WP_Mail_Validator_Checker::validate()).
 * @return int One of the WP_Mail_Validator_Checker constants.
 */
function wp_mail_validator_check_mail_address( $mail_address, $defer_probe = false ) {
	$options      = wp_mail_validator_get_options();
	$mail_address = trim( (string) $mail_address );

	if ( 'yes' === $options['use_user_defined_blacklist']
		&& wp_mail_validator_matches_patterns( $mail_address, $options['user_defined_blacklist'] ) ) {
		return WP_Mail_Validator_Checker::BLOCKED;
	}

	if ( 'yes' === $options['use_trashmail_service_blacklist']
		&& wp_mail_validator_is_disposable_domain( WP_Mail_Validator_Checker::domain_of( $mail_address ) ) ) {
		return WP_Mail_Validator_Checker::BLOCKED;
	}

	$checker = new WP_Mail_Validator_Checker( 'yes' === $options['check_mailbox'] );

	return $checker->validate( $mail_address, $defer_probe );
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
	$count = wp_mail_validator_fended_spam_attack_count();
	$link  = '<a href="https://wordpress.org/plugins/wp-mail-validator/" target="_blank" rel="noopener">' . esc_html__( 'Mail Validator', 'wp-mail-validator' ) . '</a>';

	return $string_before
		. sprintf(
			/* translators: 1: link to the plugin, 2: number of blocked spam attacks. */
			esc_html( _n( 'Protected by %1$s: %2$s spam attack blocked', 'Protected by %1$s: %2$s spam attacks blocked', $count, 'wp-mail-validator' ) ),
			$link,
			'<strong>' . esc_html( number_format_i18n( $count ) ) . '</strong>'
		)
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
				__( 'These blocklist entries are not valid regular expressions and were removed: %s', 'wp-mail-validator' ),
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
	add_options_page( __( 'Mail Validator', 'wp-mail-validator' ), __( 'Mail Validator', 'wp-mail-validator' ), 'manage_options', 'wp-mail-validator', 'wp_mail_validator_options_page' );
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
		<th scope="row"><?php echo esc_html( $label ); ?></th>
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
		<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
		<form method="post" action="options.php">
			<?php settings_fields( 'wp_mail_validator' ); ?>
			<h2><?php esc_html_e( 'Checks', 'wp-mail-validator' ); ?></h2>
			<p><?php esc_html_e( 'The checks apply to comments of visitors who are not logged in, and to registrations if they are turned on below. Comments are only checked if "Comment author must fill out name and email" is enabled under Settings → Discussion.', 'wp-mail-validator' ); ?></p>
			<table class="form-table" role="presentation">
				<?php
				wp_mail_validator_switch_row(
					$options,
					'check_mailbox',
					__( 'Check the mailbox', 'wp-mail-validator' ),
					__( 'Ask the mail server of the address (port 25) whether the mailbox exists. Only an explicit "recipient unknown" counts as spam; if the server does not answer clearly, the address is accepted. No email is sent.', 'wp-mail-validator' )
					. ' <strong>' . ( ( new WP_Mail_Validator_Checker() )->port_25_open()
						? __( 'Status: this server can reach other mail servers.', 'wp-mail-validator' )
						: __( 'Status: your hosting provider blocks outgoing connections on port 25, so this check is skipped.', 'wp-mail-validator' ) )
					. '</strong>'
				);
				wp_mail_validator_switch_row(
					$options,
					'check_mailbox_in_background',
					__( 'Check the mailbox in the background', 'wp-mail-validator' ),
					__( 'Visitors do not have to wait for slow mail servers: a comment whose mailbox has not been checked recently is held as pending and checked by WP-Cron right after it was sent. If the mailbox exists, the comment gets the status it would have had anyway; you are only notified about it after the check. Registrations are always checked immediately.', 'wp-mail-validator' )
					. ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON
						? ' <strong>' . __( 'WP-Cron is disabled on this site: make sure a server cron job runs it, otherwise held comments stay pending.', 'wp-mail-validator' ) . '</strong>'
						: '' )
				);
				wp_mail_validator_switch_row(
					$options,
					'use_trashmail_service_blacklist',
					__( 'Block disposable email addresses', 'wp-mail-validator' ),
					sprintf(
						/* translators: %s: number of known disposable email services. */
						__( 'Reject addresses of %s known disposable email services. The list is updated with every plugin release; add your own entries to the blocklist below.', 'wp-mail-validator' ),
						number_format_i18n( count( wp_mail_validator_disposable_domains() ) )
					)
				);
				wp_mail_validator_switch_row( $options, 'use_user_defined_blacklist', __( 'Use your own blocklist', 'wp-mail-validator' ), __( 'Reject addresses that match an entry of the blocklist below.', 'wp-mail-validator' ) );
				$entries = count( wp_mail_validator_split_lines( $options['user_defined_blacklist'] ) );
				?>
				<tr>
					<th scope="row"><label for="user_defined_blacklist"><?php esc_html_e( 'Blocklist', 'wp-mail-validator' ); ?></label></th>
					<td>
						<textarea id="user_defined_blacklist" name="<?php echo esc_attr( WP_MAIL_VALIDATOR_OPTION ); ?>[user_defined_blacklist]" rows="15" cols="40" class="code"><?php echo esc_textarea( $options['user_defined_blacklist'] ); ?></textarea>
						<p class="description">
							<?php
							/* translators: %s: number of entries in the blocklist. */
							echo esc_html( sprintf( _n( '%s entry', '%s entries', $entries, 'wp-mail-validator' ), number_format_i18n( $entries ) ) );
							?>
							<br />
							<?php esc_html_e( 'One regular expression per line, matched case-insensitively against the whole email address, for example:', 'wp-mail-validator' ); ?>
							<code>@example\.com$</code>
						</p>
					</td>
				</tr>
			</table>
			<h2><?php esc_html_e( 'Pingbacks and trackbacks', 'wp-mail-validator' ); ?></h2>
			<table class="form-table" role="presentation">
				<?php
				wp_mail_validator_switch_row( $options, 'accept_pingbacks', __( 'Accept pingbacks', 'wp-mail-validator' ), __( 'Pingbacks carry no email address that could be checked. Choose No to move them to the trash.', 'wp-mail-validator' ) );
				wp_mail_validator_switch_row( $options, 'accept_trackbacks', __( 'Accept trackbacks', 'wp-mail-validator' ), __( 'Trackbacks carry no email address that could be checked. Choose No to move them to the trash.', 'wp-mail-validator' ) );
				?>
			</table>
			<h2><?php esc_html_e( 'Registrations', 'wp-mail-validator' ); ?></h2>
			<table class="form-table" role="presentation">
				<?php wp_mail_validator_switch_row( $options, 'check_registrations', __( 'Check registrations', 'wp-mail-validator' ), __( 'Apply the checks to the email address of new users. A registration with an address that fails is refused.', 'wp-mail-validator' ) ); ?>
			</table>
			<?php submit_button(); ?>
		</form>
	</div>
	<div class="wrap">
		<h2><?php esc_html_e( 'Statistics', 'wp-mail-validator' ); ?></h2>
		<div class="card">
			<p>
				<?php esc_html_e( 'Version', 'wp-mail-validator' ); ?>: <strong><?php echo esc_html( WP_MAIL_VALIDATOR_VERSION ); ?></strong> |
				<?php esc_html_e( 'Spam attacks blocked', 'wp-mail-validator' ); ?>: <strong><?php echo esc_html( number_format_i18n( wp_mail_validator_fended_spam_attack_count() ) ); ?></strong>
			</p>
			<p>
				<a href="https://github.com/kimpenhaus/mail-validator#readme"><?php esc_html_e( 'Documentation', 'wp-mail-validator' ); ?></a> |
				<a href="https://wordpress.org/support/plugin/wp-mail-validator/"><?php esc_html_e( 'Support', 'wp-mail-validator' ); ?></a> |
				<a href="https://github.com/kimpenhaus/mail-validator/issues"><?php esc_html_e( 'Issue Tracker', 'wp-mail-validator' ); ?></a>
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

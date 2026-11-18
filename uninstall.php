<?php
/**
 * Removes the plugin data when the plugin is deleted.
 *
 * @package WP_Mail_Validator
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'wp_mail_validator_options' );
delete_option( 'wp_mail_validator_eaten_spam' );
delete_option( 'wp_mail_validator_version' );

// Comments still waiting for the mailbox check stay pending and get moderated as usual.
wp_unschedule_hook( 'wp_mail_validator_check_held_comment' );
delete_metadata( 'comment', 0, '_wp_mail_validator_held', '', true );

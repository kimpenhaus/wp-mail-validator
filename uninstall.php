<?php
/**
 * Removes the plugin options when the plugin is deleted.
 *
 * @package WP_Mail_Validator
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'wp_mail_validator_options' );

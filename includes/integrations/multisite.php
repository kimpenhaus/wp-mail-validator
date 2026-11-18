<?php
/**
 * Multisite: checks the mail-address of sign-ups on wp-signup.php, where a network
 * sends its registrations. Uses the "Check registrations" setting.
 *
 * @package WP_Mail_Validator
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'wpmu_validate_user_signup', 'wp_mail_validator_validate_multisite_signup', 99 );

/**
 * Refuses multisite sign-ups with an invalid mail-address.
 *
 * @param array $result { user_name, orig_username, user_email, errors (WP_Error) }.
 * @return array
 */
function wp_mail_validator_validate_multisite_signup( $result ) {
	// Users added in the dashboard by an administrator are not checked, as on single sites.
	if ( is_admin() || ! isset( $result['user_email'], $result['errors'] ) ) {
		return $result;
	}

	// WordPress has already refused the address (invalid, taken, not allowed on the network).
	if ( $result['errors']->get_error_messages( 'user_email' ) ) {
		return $result;
	}

	if ( wp_mail_validator_rejects_registration( $result['user_email'] ) ) {
		$result['errors']->add( 'user_email', __( 'This email address cannot be used. Please register with a different one.', 'wp-mail-validator' ) );
	}

	return $result;
}

<?php
/**
 * WooCommerce: checks the mail-address of new customer accounts, created on the
 * "My account" page or during checkout. Uses the "Check registrations" setting.
 *
 * @package WP_Mail_Validator
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'woocommerce_registration_errors', 'wp_mail_validator_validate_woocommerce_registration', 99, 3 );

/**
 * Refuses WooCommerce customer accounts with an invalid mail-address.
 *
 * @param WP_Error $errors   Registration errors.
 * @param string   $username Customer user name.
 * @param string   $email    Customer mail-address.
 * @return WP_Error
 */
function wp_mail_validator_validate_woocommerce_registration( $errors, $username, $email ) {
	if ( wp_mail_validator_rejects_registration( $email ) ) {
		$errors->add( 'wp_mail-validator-registration-error', __( 'This email address cannot be used. Please register with a different one.', 'wp-mail-validator' ) );
	}

	return $errors;
}

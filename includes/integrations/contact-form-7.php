<?php
/**
 * Contact Form 7: checks the email fields of all forms, if "Check Contact Form 7" is on.
 *
 * @package WP_Mail_Validator
 */

defined( 'ABSPATH' ) || exit;

// After Contact Form 7's own checks (priority 10) for required fields and syntax.
add_filter( 'wpcf7_validate_email', 'wp_mail_validator_validate_contact_form_7', 20, 2 );
add_filter( 'wpcf7_validate_email*', 'wp_mail_validator_validate_contact_form_7', 20, 2 );

/**
 * Marks an email field of a Contact Form 7 form as invalid if the address fails.
 *
 * @param WPCF7_Validation $result Validation result.
 * @param WPCF7_FormTag    $tag    Email field.
 * @return WPCF7_Validation
 */
function wp_mail_validator_validate_contact_form_7( $result, $tag ) {
	$options = wp_mail_validator_get_options();

	if ( 'yes' !== $options['check_contact_form_7'] || ! $result->is_valid( $tag->name ) ) {
		return $result;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the submission is verified and handled by Contact Form 7.
	$mail_address = isset( $_POST[ $tag->name ] ) ? sanitize_text_field( wp_unslash( $_POST[ $tag->name ] ) ) : '';

	// An empty optional field is fine.
	if ( '' !== $mail_address && ! wp_mail_validator_is_valid_email( $mail_address ) ) {
		$result->invalidate( $tag, __( 'This email address cannot be used. Please enter a different one.', 'wp-mail-validator' ) );
	}

	return $result;
}

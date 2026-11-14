<?php
/**
 * PHPUnit bootstrap: loads the plugin without WordPress. WordPress functions are
 * mocked per test with Brain Monkey (see TestCase).
 *
 * @package WP_Mail_Validator
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

define( 'ABSPATH', '/tmp/wordpress/' );

// The plugin registers hooks while it is loaded, before Brain Monkey is set up per test.
require_once dirname( __DIR__ ) . '/vendor/brain/monkey/inc/wp-hook-functions.php';
require_once dirname( __DIR__ ) . '/vendor/brain/monkey/inc/wp-helper-functions.php';

function register_activation_hook( $file, $callback ) {} // phpcs:ignore

require_once __DIR__ . '/TestCase.php';
require_once dirname( __DIR__ ) . '/wp-mail-validator.php';

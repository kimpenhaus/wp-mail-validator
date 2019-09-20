<?php
/*
Plugin Name: WP-Mail-Validator
Plugin URI: https://github.com/kimpenhaus/wp-mail-validator
Description: WP-Mail-Validator is an anti-spam plugin. It provides mail-address validation in 5 ways: 1. syntax 2. host 3. mx-record of mailserver 4. refuse from user-defined blacklist 5. refuse trashmail services
Version: 0.6
Author: Marcus Kimpenhaus
Author URI: https://github.com/kimpenhaus/
Text Domain: wp-mail-validator
*/

require_once('email-validator.inc.php');

// <-- i18n textdomain -->
$text_domain = 'wp-mail-validator';
load_plugin_textdomain($text_domain);

// <-- trash mail service blacklist -->
$trashmail_service_blacklist_file = plugin_dir_url(__FILE__) . '/data/trash.mail.service.blacklist';

// <-- plugin options -->
$wp_mail_validator_options = array();

if (get_option('wp_mail_validator_options')) {
    $wp_mail_validator_options = get_option('wp_mail_validator_options');
}

if (empty($wp_mail_validator_options['eaten_spam'])) {
    $wp_mail_validator_options['eaten_spam'] = '0';
}

if (empty($wp_mail_validator_options['ignore_failed_connection'])) {
    $wp_mail_validator_options['ignore_failed_connection'] = 'no';
}

if (empty($wp_mail_validator_options['ignore_request_rejected'])) {
    $wp_mail_validator_options['ignore_request_rejected'] = 'no';
}

if (empty($wp_mail_validator_options['accept_correct_syntax_on_server_timeout'])) {
    $wp_mail_validator_options['accept_correct_syntax_on_server_timeout'] = 'no';
}

if (empty($wp_mail_validator_options['default_gateway'])) {
    $wp_mail_validator_options['default_gateway'] = '';
}

if (empty($wp_mail_validator_options['accept_pingbacks'])) {
    $wp_mail_validator_options['accept_pingbacks'] = 'yes';
}

if (empty($wp_mail_validator_options['accept_trackbacks'])) {
    $wp_mail_validator_options['accept_trackbacks'] = 'yes';
}

if (empty($wp_mail_validator_options['use_user_defined_blacklist'])) {
    $wp_mail_validator_options['use_user_defined_blacklist'] = 'no';
}

if (empty($wp_mail_validator_options['user_defined_blacklist'])) {
    $wp_mail_validator_options['user_defined_blacklist'] = '';
}

if (empty($wp_mail_validator_options['use_trashmail_service_blacklist'])) {
    $wp_mail_validator_options['use_trashmail_service_blacklist'] = 'yes';
}

if (empty($wp_mail_validator_options['trashmail_service_blacklist'])) {
    $trashmail_service_blacklist_entries = file_exists($trashmail_service_blacklist_file) ? file_get_contents($trashmail_service_blacklist_file) : '';
    $wp_mail_validator_options['trashmail_service_blacklist'] = $trashmail_service_blacklist_entries;
}

if (empty($wp_mail_validator_options['check_registrations'])) {
    $wp_mail_validator_options['check_registrations'] = 'no';
}

// <-- os detection -->
$is_windows = strncasecmp(PHP_OS, 'WIN', 3) == 0 ? true : false;

// get windows compatibility
if (! function_exists('getmxrr')) {
    function getmxrr($hostName, &$mxHosts, &$mxPreference)
    {
        global $wp_mail_validator_options;
        
        $gateway = $wp_mail_validator_options['default_gateway'];
    
        $nsLookup = shell_exec("nslookup -q=mx {$hostName} {$gateway} 2>nul");
        preg_match_all("'^.*MX preference = (\d{1,10}), mail exchanger = (.*)$'simU", $nsLookup, $mxMatches);

        if (count($mxMatches[2]) > 0) {
            array_multisort($mxMatches[1], $mxMatches[2]);

            for ($i = 0; $i < count($mxMatches[2]); $i++) {
                $mxHosts[$i] = $mxMatches[2][$i];
                $mxPreference[$i] = $mxMatches[1][$i];
            }

            return true;
        } else {
            return false;
        }
    }
}

// <-- plugin functionality -->

function wp_mail_validator_init() {
    add_filter('pre_comment_approved', 'wp_mail_validator_validate_comment_mail', 99, 2);
    add_filter('registration_errors', 'wp_mail_validator_validate_registration_mail', 99, 3);

    if (is_admin()) {
        add_action('admin_menu', 'wp_mail_validator_add_options_page');
        add_action('admin_enqueue_scripts', 'wp_mail_validator_enque_scripts');
    }
}

function wp_mail_validator_validate_comment_mail($approved, $comment_data)
{
    // if comment is already marked as spam or trash
    // no further investigation is done
    if ($approved === 'spam' || $approved === 'trash') {
        return $approved;
    }

    global $user_ID;
    global $text_domain;
    global $wp_mail_validator_options;
    
    // currently it is not possible to check trackbacks / pingbacks while there
    // is no 'comment_author_email' given in the trackback values
    
    // check if trackbacks should be left or dropped out
    if ((isset($comment_data['comment_type'])) && ($comment_data['comment_type'] == 'trackback')) {
        if ($wp_mail_validator_options['accept_trackbacks'] == 'yes') {
            return $approved;
        } else {
            return 'trash';
        }
    }
    
    // check if pingbacks should be left or dropped out
    if ((isset($comment_data['comment_type'])) && ($comment_data['comment_type'] == 'pingback')) {
        if ($wp_mail_validator_options['accept_pingbacks'] == 'yes') {
            return $approved;
        } else {
            return 'trash';
        }
    }
    
    // if it's a comment and not a logged in user - check mail
    if ((get_option('require_name_email')) && (!$user_ID)) {
        $mail_address = $comment_data['comment_author_email'];
        return wp_mail_validator_validate_mail_address($approved, $mail_address);
    }

    return $approved;
}

function wp_mail_validator_validate_registration_mail($errors, $sanitized_user_login, $user_email)
{
    global $text_domain;
    global $wp_mail_validator_options;

    if ($wp_mail_validator_options['check_registrations'] == 'yes') {
        $approved = wp_mail_validator_validate_mail_address('', $user_email);

        if ($approved === 'spam') {
            $errors->add('wp_mail-validator-registration-error', __( '<strong>ERROR</strong>: Your mail-address is evaluated as spam.', $text_domain));
        }
    }

    return $errors;
}

function wp_mail_validator_validate_mail_address($approved, $mail_address)
{
    global $wp_mail_validator_options;
    global $text_domain;

    // check mail-address against user defined blacklist (if enabled)
    if ($wp_mail_validator_options['use_user_defined_blacklist'] == 'yes') {
        $regexps = preg_split('/[\r\n]+/', $wp_mail_validator_options['user_defined_blacklist'], -1, PREG_SPLIT_NO_EMPTY);
        
        foreach ($regexps as $regexp) {
            if (preg_match('/' . $regexp . '/', $mail_address)) {
                wp_mail_validator_fend_it();
                return 'spam';
            }
        }
    }

    // check mail-address against trashmail services (if enabled)
    if ($wp_mail_validator_options['use_trashmail_service_blacklist'] == 'yes') {
        $regexps = preg_split('/[\r\n]+/', $wp_mail_validator_options['trashmail_service_blacklist'], -1, PREG_SPLIT_NO_EMPTY);
        
        foreach ($regexps as $regexp) {
            if (preg_match('/' . $regexp . '/', $mail_address)) {
                wp_mail_validator_fend_it();
                return 'spam';
            }
        }
    }

    $mail_validator = new EMailValidator();
    $return_code = $mail_validator->validateEMailAddress($mail_address);

    switch ($return_code) {
        case UNKNOWN_SERVER:
        case INVALID_MAIL:
        case SYNTAX_INCORRECT:
            wp_mail_validator_fend_it();
            return 'spam';
        case CONNECTION_FAILED:
            // timeout while connecting to mail-server
            if ($wp_mail_validator_options['ignore_failed_connection'] == 'no') {
                wp_mail_validator_fend_it();
                return 'spam';
            }
            return $approved;
        case REQUEST_REJECTED:
            // host could be identified - but he rejected any request
            if ($wp_mail_validator_options['ignore_request_rejected'] == 'no') {
                wp_mail_validator_fend_it();
                return 'spam';
            }
            return $approved;
        case VALID_MAIL:
            // host could be identified and he accepted and he approved
            // the mail address
            return $approved;
        case SYNTAX_CORRECT:
            // mail address syntax correct - but the host server
            // did not repsonse in time
            if ($wp_mail_validator_options['accept_correct_syntax_on_server_timeout'] != 'yes') {
                wp_mail_validator_fend_it();
                return 'spam';
            }
            return $approved;
        default:
            return $approved;
    }
}

// <-- database update function -->

function wp_mail_validator_fend_it()
{
    global $wp_mail_validator_options;

    $wp_mail_validator_options['eaten_spam'] = ($wp_mail_validator_options['eaten_spam'] + 1);
    update_option('wp_mail_validator_options', $wp_mail_validator_options);
}

// <-- theme functions / statistics -->

function wp_mail_validator_info_label($string_before = "", $string_after = "")
{
    global $text_domain;

    $label = $string_before . __('Protected by', $text_domain) . ': <a href="https://github.com/kimpenhaus/wp-mail-validator" title="WP-Mail-Validator" target="_blank">WP-Mail-Validator</a> - <strong>%s</strong> ' . __('spam attacks fended', $text_domain) . '!' . $string_after;
    return sprintf($label, wp_mail_validator_fended_spam_attack_count());
}

function wp_mail_validator_fended_spam_attack_count()
{
    global $wp_mail_validator_options;
    return $wp_mail_validator_options['eaten_spam'];
}

function wp_mail_validator_version()
{
    $plugin = get_plugin_data( __FILE__ );
    return $plugin['Version'];
}

// <-- admin menu option page -->

function wp_mail_validator_add_options_page()
{
    add_options_page('WP-Mail-Validator', 'WP-Mail-Validator', 'edit_pages', basename(__FILE__, ".php"), 'wp_mail_validator_options_page');
}

function wp_mail_validator_options_page()
{
    global $text_domain;
    global $wp_mail_validator_options;
    global $is_windows;
    global $trashmail_service_blacklist_file;
        
    if (isset($_POST['wp_mail_validator_options_update_type'])) {
        $wp_mail_validator_updated_options = $wp_mail_validator_options;
        $update_notice = '';

        if ($_POST['wp_mail_validator_options_update_type'] === 'update') {
            foreach ($_POST as $key => $value) {
                if ($key !== 'wp_mail_validator_options_update_type' && $key !== 'submit') {
                    $wp_mail_validator_updated_options[$key] = $value;
                }
            }
            $update_notice = __('WP-Mail-Validator options updated', $text_domain);
        } elseif ($_POST['wp_mail_validator_options_update_type'] === 'restore_trashmail_blacklist') {
            $wp_mail_validator_updated_options['trashmail_service_blacklist'] = file_get_contents($trashmail_service_blacklist_file);
            $update_notice = __('WP-Mail-Validator trashmail service blacklist restored', $text_domain);
        }

        update_option('wp_mail_validator_options', $wp_mail_validator_updated_options);
        $wp_mail_validator_options = get_option('wp_mail_validator_options');
    ?>
        <div id="setting-error-settings_updated" class="updated settings-error notice is-dismissible"> 
            <p>
                <strong><?php echo $update_notice ?>.</strong>
            </p>
            <button type="button" class="notice-dismiss">
                <span class="screen-reader-text"><?php echo __('Dismiss this notice', $text_domain) ?>.</span>
            </button>
        </div>';
    <?php
    }
    ?>
        <div class="wrap">
            <h1><?php echo __('Settings', $text_domain) ?></h1>
            <form name="wp_mail_validator_options" method="post">
                <input type="hidden" name="wp_mail_validator_options_update_type" value="update" />
                <h2><?php echo __('Comment validation', $text_domain) ?></h2>
                <table width="100%" cellspacing="2" cellpadding="5" class="form-table">
    <?php
    if ($is_windows) {
    ?>
                    <tr>
                        <th scope="row"><?php echo__('Default-gateway IP', $text_domain) ?>:</th>
                        <td>
                            <input name="default_gateway" type="text" id="default_gateway" value="<?php echo $wp_mail_validator_options['default_gateway'] ?>" maxlength="15" size="40" />
                            <br /><?php echo __('Leave blank to use the default gateway', $text_domain) ?>.
                        </td>
                    </tr>
    <?php
    }
    ?>
                    <tr>
                        <th scope="row"><?php echo __('Accept on connection failures', $text_domain) ?>:</th>
                        <td>
                            <label>
                                <input name="ignore_failed_connection" type="radio" value="yes" <?php if ($wp_mail_validator_options['ignore_failed_connection'] == 'yes') { echo 'checked="checked" '; } ?>/>
                                <?php echo __('Yes', $text_domain) ?>
                            </label>
                            <label>
                                <input name="ignore_failed_connection" type="radio" value="no"<?php if ($wp_mail_validator_options['ignore_failed_connection'] == 'no') { echo 'checked="checked" '; } ?>/>
                                <?php echo __('No', $text_domain) ?>
                            </label>
                            <p class="description">
                                <?php echo __('Choose to ignore connection failures with mail servers while validating mail-addresses', $text_domain) ?>.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo __('Accept on rejected requests', $text_domain) ?>:</th>
                        <td>
                            <label>
                                <input name="ignore_request_rejected" type="radio" value="yes" <?php if ($wp_mail_validator_options['ignore_request_rejected'] == 'yes') { echo 'checked="checked" '; } ?>/>
                                <?php echo __('Yes', $text_domain) ?>
                            </label>
                            <label>
                                <input name="ignore_request_rejected" type="radio" value="no" <?php if ($wp_mail_validator_options['ignore_request_rejected'] == 'no') { echo 'checked="checked" '; } ?>/>
                                <?php echo __('No', $text_domain) ?>
                            </label>
                            <p class="description">
                                <?php echo __('Choose to ignore rejected request from mail servers while validating mail-addresses', $text_domain) ?>.
                            </p>
                       </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo __('Accept syntactically correct mail-addresses', $text_domain) ?>:</th>
                        <td>
                            <label>
                                <input name="accept_correct_syntax_on_server_timeout" type="radio" value="yes" <?php if ($wp_mail_validator_options['accept_correct_syntax_on_server_timeout'] == 'yes') { echo 'checked="checked" '; } ?>/>
                                <?php echo __('Yes', $text_domain) ?>
                            </label>
                            <label>
                                <input name="accept_correct_syntax_on_server_timeout" type="radio" value="no" <?php if ($wp_mail_validator_options['accept_correct_syntax_on_server_timeout'] == 'no') { echo 'checked="checked" '; } ?>/>
                                <?php echo __('No', $text_domain) ?>
                            </label>
                            <p class="description">
                                <?php echo __('Choose if syntactically correct mail-addresses can pass when the mail server did not respond in time', $text_domain) ?>.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo __('Reject mail-adresses from trashmail services', $text_domain) ?>:</th>
                        <td>
                            <label>
                                <input name="use_trashmail_service_blacklist" type="radio" value="yes" <?php
    if ($wp_mail_validator_options['use_trashmail_service_blacklist'] == 'yes') {
        echo 'checked="checked" ';
    }
    echo '/> ' . __('Yes', $text_domain) . '</label>
				<label><input name="use_trashmail_service_blacklist" type="radio" value="no" ';
    if ($wp_mail_validator_options['use_trashmail_service_blacklist'] == 'no') {
        echo 'checked="checked" ';
    }
    echo ' /> ' . __('No', $text_domain) . '</label>
    <p class="description">' . __('Choose to reject mail-addresses from trashmail services <strong>(single entry per line)</strong>', $text_domain) . '.</p>
                </td>
            </tr>
            <tr>
				<th scope="row">&nbsp;</th>
				<td>
                <label><textarea id="trashmail_service_blacklist" name="trashmail_service_blacklist" rows="15" cols="40">' . $wp_mail_validator_options['trashmail_service_blacklist'] . '</textarea></label>
                <p class="description"><span id="trashmail_service_blacklist_line_count">0</span> ' . __('Entries', $text_domain) . '</p>
                <p class="submit"><input class="button button-primary" type="submit" id="trashmail_service_blacklist_restore" name="submit" value="' . __('Restore default blacklist', $text_domain) . '" /></p>
				</td>
            </tr>
			<tr>
				<th scope="row">' . __('Reject mail-adresses from user-defined blacklist', $text_domain) . ':</th>
				<td>
				<label><input name="use_user_defined_blacklist" type="radio" value="yes" ';
    if ($wp_mail_validator_options['use_user_defined_blacklist'] == 'yes') {
        echo 'checked="checked" ';
    }
    echo '/> ' . __('Yes', $text_domain) . '</label>
				<label><input name="use_user_defined_blacklist" type="radio" value="no" ';
    if ($wp_mail_validator_options['use_user_defined_blacklist'] == 'no') {
        echo 'checked="checked" ';
    }
    echo ' /> ' . __('No', $text_domain) . '</label>
                <p class="description">' . __('Choose to reject mail-addresses from a user-defined blacklist <strong>(single entry per line)</strong>', $text_domain) . '.</p>
                </td>
			</tr>
            <tr>
				<th scope="row">&nbsp;</th>
				<td>
                <label><textarea id="user_defined_blacklist" name="user_defined_blacklist" rows="15" cols="40">' . $wp_mail_validator_options['user_defined_blacklist'] . '</textarea></label>
                <p class="description"><span id="user_defined_blacklist_line_count">0</span> ' . __('Entries', $text_domain) . '</p>
				</td>
            </tr>
        </table>
        <h2>' . __('Ping validation', $text_domain) . '</h2>
        <table width="100%" cellspacing="2" cellpadding="5" class="form-table">
        <tr>
            <th scope="row">' . __('Validate pingbacks', $text_domain) . ':</th>
            <td>
            <label><input name="accept_pingbacks" type="radio" value="yes" ';
if ($wp_mail_validator_options['accept_pingbacks'] == 'yes') {
    echo 'checked="checked" ';
}
echo '/> ' . __('Yes', $text_domain) . '</label>
            <label><input name="accept_pingbacks" type="radio" value="no" ';
if ($wp_mail_validator_options['accept_pingbacks'] == 'no') {
    echo 'checked="checked" ';
}
echo ' /> ' . __('No', $text_domain) . '</label><p class="description">' . __('Choose to accept Pingbacks <strong>(Pingbacks might be a security risk, because they\'re not carrying a mail-address to validate)</strong>', $text_domain) . '.</p>
            </td>
        </tr>
        <tr>
            <th scope="row">' . __('Validate trackbacks', $text_domain) . ':</th>
            <td>
            <label><input name="accept_trackbacks" type="radio" value="yes" ';
if ($wp_mail_validator_options['accept_trackbacks'] == 'yes') {
    echo 'checked="checked" ';
}
echo '/> ' . __('Yes', $text_domain) . '</label>
            <label><input name="accept_trackbacks" type="radio" value="no" ';
if ($wp_mail_validator_options['accept_trackbacks'] == 'no') {
    echo 'checked="checked" ';
}
echo ' /> ' . __('No', $text_domain) . '</label><p class="description">' . __('Choose to accept Trackbacks <strong>(Trackbacks might be a security risk, because they\'re not carrying a mail-address to validate)</strong>', $text_domain) . '.</p>
            </td>
        </tr>
        </table>'; 

        echo '<h2>' . __('Registrants validation', $text_domain) . '</h2>
        <table width="100%" cellspacing="2" cellpadding="5" class="form-table">
        <tr>
            <th scope="row">' . __('Validate user-registrations', $text_domain) . ':</th>
            <td>
            <label><input name="check_registrations" type="radio" value="yes" ';
if ($wp_mail_validator_options['check_registrations'] == 'yes') {
    echo 'checked="checked" ';
}
echo '/> ' . __('Yes', $text_domain) . '</label>
            <label><input name="check_registrations" type="radio" value="no" ';
if ($wp_mail_validator_options['check_registrations'] == 'no') {
    echo 'checked="checked" ';
}
echo ' /> ' . __('No', $text_domain) . '</label><p class="description">' . __('Choose to validate registrants mail-address', $text_domain) . '.</p>
            </td>
        </tr>
        </table>
	<p class="submit"><input class="button button-primary" type="submit" id="options_update" name="submit" value="' . __('Save Changes', $text_domain) . '" /></p>
    </form>
    </div>
    <div class="wrap">
    <h1>' . __('Statistics', $text_domain) . '</h1>
    <div class="card">
        <p>' . sprintf(__('Version', $text_domain) . ': <strong>%s</strong>', wp_mail_validator_version()) . '&nbsp;|
        ' . sprintf(__('Spam attacks fended', $text_domain) . ': <strong>%s</strong>', wp_mail_validator_fended_spam_attack_count()) . '</p>
        <p><a href="https://github.com/kimpenhaus/wp-mail-validator/wiki">' . __('Documentation', $text_domain) . '</a>&nbsp;|
        <a href="https://github.com/kimpenhaus/wp-mail-validator/issues">' . __('Issue Tracker', $text_domain) . '</a></p>
    </div>
    </div>
    ';
}

function wp_mail_validator_enque_scripts($hook)
{
    if ('settings_page_wp-mail-validator' != $hook) {
        return;
    }

    wp_enqueue_script('jquery.mask', plugin_dir_url(__FILE__) . 'scripts/jquery.mask.min.js', array(), '1.14.15');
    wp_enqueue_script('wp.mail.validator', plugin_dir_url(__FILE__) . 'scripts/wp.mail.validator.min.js', array(), '1.0.0');
}

// <-- plugin installation on activation -->

function wp_mail_validator_install()
{
    global $wpdb;
    global $wp_mail_validator_options;

    // migration of existing data in older versions
    $table_name = $wpdb->prefix . "mail_validator";

    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name) {
        $sql = "SELECT eaten FROM " . $table_name . " LIMIT 1;";
        $count = $wpdb->get_var($sql);

        $wp_mail_validator_options['eaten_spam'] = $count;
        update_option('wp_mail_validator_options', $wp_mail_validator_options);

        $sql = "DROP TABLE IF EXISTS " . $table_name . ";";
        $wpdb->query($sql);
    }
}

// <-- hooks -->
register_activation_hook( __FILE__, 'wp_mail_validator_install');
add_action( 'init', 'wp_mail_validator_init' );
?>
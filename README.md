=== WP-Mail-Validator ===

Contributors: kimpenhaus
Tags: comments, registrations, spam, anti-spam, mail, email, validation, check, security, blacklist, mx-record, trashmail
Stable tag: 0.5.2
Tested up to: 5.2.1
Requires at least: 5.2.0
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.en.html

== Description ==

WP-Mail-Validator is an anti-spam plugin. It provides mail-address validation in 5 ways:

1. syntax of mail-addresses
2. mailserver host
3. mx-record of mailserver
4. user-defined blacklist
5. trashmail services

== Installation ==

1. Upload and unzip folder to `wp-content/plugins/`
2. Activate the plugin through the `Plugins` menu in WordPress

== Theme-Modification ==

WP-Mail-Validator comes with 3 theme functions that can be used:

1. `wp_mail_validator_info_label()`: shows a protected by info label
2. `wp_mail_validator_version()`: shows the current plugin version
3. `wp_mail_validator_fended_spam_attack_count()`: shows the count of spam attackes fended

== Changelog ==

= 0.5.2 =
* fixed misspelling

= 0.5.1 =
* added links to wiki and issue tracker
* tiny reorganisation of settings page

= 0.5 =
* updated to WordPress v5.2.1
* added trashmail service blacklist
* added registration validation

= 0.4 =
* added optional rejection of pingbacks
* added optional rejection of trackbacks

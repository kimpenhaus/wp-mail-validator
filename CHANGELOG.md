# Changelog

All notable changes to Mail Validator (formerly WP-Mail-Validator). The plugin page on WordPress.org shows the same entries in `readme.txt`.

## [0.8.0](https://github.com/kimpenhaus/mail-validator/compare/0.7.3...0.8.0) (2026-11-18)


### New

* **integrations:** check WooCommerce accounts, multisite sign-ups and Contact Form 7 forms ([#18](https://github.com/kimpenhaus/mail-validator/issues/18)) ([ccb358c](https://github.com/kimpenhaus/mail-validator/commit/ccb358c27ff25055321801c276943f0dfe5d3778))
* run the mailbox check for comments in the background ([#17](https://github.com/kimpenhaus/mail-validator/issues/17)) ([3e9f442](https://github.com/kimpenhaus/mail-validator/commit/3e9f442c15c9d1523ce84a3d9984fb91da119f48))

## 0.7.3 (2026-11-15)

* Fixed: the short description on the plugin page was cut off

## 0.7.2 (2026-11-15)

* Improved: clearer texts on the settings page, in messages and on the plugin page, ready for translation – e.g. "blocklist" instead of "blacklist", "Accept pingbacks" instead of "Validate pingbacks", plural forms for numbers
* Changed: new wording for the registration error and for the "protected by" label of the theme function
* Changed: the GitHub repository moved to github.com/kimpenhaus/mail-validator

## 0.7.1 (2026-11-15)

* Changed: new name "Mail Validator – Disposable & Fake Address Blocker" (formerly WP-Mail-Validator); the settings are now under *Settings → Mail Validator*
* Improved: the settings menu entry can be translated

## 0.7.0 (2026-11-14)

* Fixed: fatal error on PHP 8
* Fixed: the bundled trashmail list was never loaded
* Fixed: addresses with long top-level domains (`.info`, `.online`, …) or `+` were rejected as spam
* Security: settings page protected against CSRF, restricted to administrators, all output escaped
* New: bundled list of more than 10,000 disposable mail domains, updated with every release. It replaces the editable trashmail list; entries you had added are moved to your blacklist
* New: option to turn off the mailbox check (port 25)
* Improved: mailbox check only rejects on an explicit "recipient unknown" (5.1.1); greylisting, policy blocks and catch-all servers no longer cause false positives
* Improved: mailbox check is skipped automatically if the host blocks port 25, caches its results and takes seconds instead of minutes when a server does not answer
* Removed: the three "accept on connection failure / rejected request / timeout" settings – inconclusive results now always pass
* Improved: blacklist entries are matched case-insensitively; invalid entries are reported when saving
* Removed: Windows-specific default-gateway setting (no longer needed)
* Requires WordPress 6.0 and PHP 7.4
* License changed from GPLv3 to GPLv2 or later, like WordPress itself

## 0.6.5 (2020-04-13)

* added WP 5.4 as tested version

## 0.6.4 (2020-01-04)

* added WP 5.3.2 as tested version

## 0.6.3 (2019-12-02)

* added WP 5.3 as tested version

## 0.6.2 (2019-09-20)

* version fix

## 0.6.1 (2019-09-20)

* added line count to blacklist and trashmail service
* added WP 5.2.3 as tested version

## 0.6.0 (2019-07-17)

* changed behaviour to move comments to spam moderation rather then deny posting

## 0.5.2 (2019-05-30)

* fixed misspelling

## 0.5.1 (2019-05-30)

* added links to wiki and issue tracker
* tiny reorganisation of settings page

## 0.5

* updated to WordPress v5.2.1
* added trashmail service blacklist
* added registration validation

## 0.4

* added optional rejection of pingbacks
* added optional rejection of trackbacks

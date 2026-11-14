=== WP-Mail-Validator ===
Contributors: kimpenhaus
Tags: anti-spam, comments, email validation, disposable email, registration
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.7.0
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Stops comment and registration spam by validating mail-addresses: syntax, mail server, mailbox, disposable mail services and your own blacklist.

== Description ==

WP-Mail-Validator checks the mail-address of everyone who comments on your blog (and, optionally, registers). Comments with an address that fails a check are moved to spam for moderation, registrations are refused.

Checks, in this order:

1. **Your blacklist** – your own list of regular expressions, e.g. `@spam\.example$`.
2. **Disposable mail services** – more than 10,000 known throw-away domains (mailinator, 10minutemail, …), updated with every plugin release.
3. **Syntax** – the address must be well-formed.
4. **Mail server** – the domain must have a mail server (MX record) or at least an address record.
5. **Mailbox** (optional) – asks the mail server whether the mailbox exists.

Comments of logged-in users are never checked. Pingbacks and trackbacks carry no mail-address; you can choose to accept or drop them.

= Theme functions =

* `wp_mail_validator_info_label( $before, $after )` – a "protected by" label with the number of fended spam attacks
* `wp_mail_validator_fended_spam_attack_count()` – the number of fended spam attacks
* `wp_mail_validator_version()` – the plugin version

= External services =

To validate an address, the plugin connects to the mail infrastructure of the **commenter's** domain:

* **DNS** – it looks up the MX, A and AAAA records of the domain, using your server's resolver.
* **SMTP** (only if "Verify mailbox on the mail server" is on) – it connects to the domain's mail server on port 25 and asks whether it accepts mail for the address. The mail server receives your site's host name and the address being checked. No mail is sent.

No data is sent to the plugin author or any other third party.

== Installation ==

1. Install the plugin via *Plugins → Add New* and activate it.
2. Review the settings under *Settings → WP-Mail-Validator*.

Comment checks only run if *Settings → Discussion → "Comment author must fill out name and email"* is enabled.

== Frequently Asked Questions ==

= Valid comments end up in spam – why? =

Most likely your host blocks outgoing connections on port 25, so the mailbox cannot be verified. Turn off *Verify mailbox on the mail server*, or set *Accept syntactically correct mail-addresses* to *Yes*.

= Where does the list of disposable domains come from? =

From the community-maintained [disposable-email-domains](https://github.com/disposable-email-domains/disposable-email-domains) project (CC0). Please report missing or wrongly listed domains there.

= Can I use my own list of disposable domains? =

Yes. Enter it in the trashmail textarea (one regular expression per line); it replaces the bundled list. *Restore default blacklist* switches back to the bundled list.

== Changelog ==

= 0.7.0 =
* Fixed: fatal error on PHP 8
* Fixed: the bundled trashmail list was never loaded
* Fixed: addresses with long top-level domains (`.info`, `.online`, …) or `+` were rejected as spam
* Security: settings page protected against CSRF, restricted to administrators, all output escaped
* New: bundled list of more than 10,000 disposable mail domains, updated with every release
* New: option to turn off the mailbox check (port 25)
* Improved: mailbox check takes seconds instead of minutes when a mail server does not answer, speaks proper SMTP and treats temporary errors (greylisting) as inconclusive
* Improved: blacklist entries are matched case-insensitively; invalid entries are reported when saving
* Removed: Windows-specific default-gateway setting (no longer needed)
* Requires WordPress 6.0 and PHP 7.4

= 0.6.5 =
* added WP 5.4 as tested version

= 0.6.4 =
* added WP 5.3.2 as tested version

= 0.6.3 =
* added WP 5.3 as tested version

= 0.6.2 =
* version fix

= 0.6.1 =
* added line count to blacklist and trashmail service
* added WP 5.2.3 as tested version

= 0.6.0 =
* changed behaviour to move comments to spam moderation rather then deny posting

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

== Upgrade Notice ==

= 0.7.0 =
Fixes a fatal error on PHP 8 and secures the settings page, which is now restricted to administrators. Requires PHP 7.4.

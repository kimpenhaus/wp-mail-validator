=== Mail Validator – Disposable & Fake Address Blocker ===
Contributors: kimpenhaus
Tags: anti-spam, comments, email validation, disposable email, registration
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.7.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Stops spam from disposable and fake email addresses in comments, registrations and contact forms: checks syntax, mail server, mailbox and blocklist.

== Description ==

Mail Validator (formerly WP-Mail-Validator) checks the email address of everyone who comments on your site and, if you like, of everyone who registers or sends a Contact Form 7 form. A comment with an address that fails a check is moved to spam for moderation; a registration is refused, and a form asks for a different address.

Checks, in this order:

1. **Your blocklist** – your own list of regular expressions, for example `@spam\.example$`.
2. **Disposable email services** – more than 10,000 known throw-away domains (mailinator, 10minutemail, …), updated with every plugin release.
3. **Syntax** – the address must be well-formed.
4. **Mail server** – the domain must have a mail server (MX record) or at least an address record.
5. **Mailbox** – asks the mail server whether the mailbox exists. Only an explicit "recipient unknown" counts as spam; servers that accept any address (catch-all), greylist or refuse to answer never cause false positives. Results are cached, and the check is skipped automatically if your host blocks port 25. By default it runs in the background, so commenters never wait for a slow mail server.

Comments of logged-in users are never checked. Pingbacks and trackbacks carry no email address; you can choose to accept them or move them to the trash.

= Works with =

* **WooCommerce** – customer accounts created on the "My account" page or during checkout (with *Check registrations*)
* **Multisite** – sign-ups on the network (with *Check registrations*)
* **Contact Form 7** – the email fields of all forms (with *Check Contact Form 7*)
* **Your own forms** – see `wp_mail_validator_is_valid_email()` below

= Functions for themes and plugins =

* `wp_mail_validator_is_valid_email( $email )` – whether the address passes the checks, with your settings. Rejected addresses are counted. Also available as a filter that is safe to use when the plugin is not active: `apply_filters( 'wp_mail_validator_is_valid_email', true, $email )`
* `wp_mail_validator_info_label( $before, $after )` – a "protected by" label with the number of blocked spam attacks
* `wp_mail_validator_fended_spam_attack_count()` – the number of blocked spam attacks
* `wp_mail_validator_version()` – the plugin version

= External services =

To validate an address – of a commenter, a new user or the sender of a contact form – the plugin connects to the mail infrastructure of the **address's** domain:

* **DNS** – it looks up the MX, A and AAAA records of the domain, using your server's resolver.
* **SMTP** (only if "Check the mailbox" is on) – it connects to the domain's mail server on port 25 and asks whether it accepts email for the address and for a random address at the same domain (to detect servers that accept anything). The mail server receives your site's host name and the addresses being checked. No email is sent.
* **Port test** (only if "Check the mailbox" is on) – at most once a day it opens a connection to the mail server of gmail.com on port 25 and closes it right after the greeting, to find out whether your host allows outgoing SMTP connections. No data is transmitted.

No data is sent to the plugin author or any other third party.

== Installation ==

1. Install the plugin via *Plugins → Add New* and activate it.
2. Review the settings under *Settings → Mail Validator*.

Comment checks only run if *Settings → Discussion → "Comment author must fill out name and email"* is enabled. Registrations and Contact Form 7 forms are checked once you turn that on in the settings.

== Frequently Asked Questions ==

= Does the mailbox check work on my host? =

The settings page tells you. Many hosting providers block outgoing connections on port 25; the plugin detects that and skips the mailbox check, all other checks keep working.

= Why is a comment pending for a moment? =

If the mailbox of an address has not been checked recently, the comment is held as pending and the mailbox is checked by WP-Cron right after the comment was sent – usually within seconds, on the next page view. If the mailbox exists, the comment gets the status it would have had anyway (approved or pending), and you are notified only then. If it does not exist, the comment goes to spam. Registrations are always checked immediately. If WP-Cron is disabled on your site, make sure a server cron job runs it, or turn off *Check the mailbox in the background*.

= Which providers can the mailbox check verify? =

Only those that answer honestly, e.g. Gmail. Outlook, Yahoo and catch-all domains accept any address or refuse to answer during the check, so their addresses always pass it.

= Where does the list of disposable domains come from? =

From the community-maintained [disposable-email-domains](https://github.com/disposable-email-domains/disposable-email-domains) project (CC0). Please report missing or wrongly listed domains there.

= How do I block additional domains? =

Turn on *Use your own blocklist* in the settings and add one regular expression per line, for example `@spam\.example$`. Entries you had added to the trashmail list of earlier versions were moved there automatically.

== Changelog ==

= 0.7.3 =
* Fixed: the short description on the plugin page was cut off

= 0.7.2 =
* Improved: clearer texts on the settings page, in messages and on the plugin page, ready for translation – e.g. "blocklist" instead of "blacklist", "Accept pingbacks" instead of "Validate pingbacks", plural forms for numbers
* Changed: new wording for the registration error and for the "protected by" label of the theme function
* Changed: the GitHub repository moved to github.com/kimpenhaus/mail-validator

= 0.7.1 =
* Changed: new name "Mail Validator – Disposable & Fake Address Blocker" (formerly WP-Mail-Validator); the settings are now under *Settings → Mail Validator*
* Improved: the settings menu entry can be translated

= 0.7.0 =
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

= 0.7.1 =
WP-Mail-Validator is now called Mail Validator. Settings and behaviour are unchanged.

= 0.7.0 =
Fixes a fatal error on PHP 8 and secures the settings page, which is now restricted to administrators. Requires PHP 7.4.

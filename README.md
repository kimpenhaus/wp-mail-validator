# Mail Validator

[![WordPress.org version](https://img.shields.io/wordpress/plugin/v/wp-mail-validator?label=wordpress.org)](https://wordpress.org/plugins/wp-mail-validator/)
[![Tested up to](https://img.shields.io/wordpress/plugin/tested/wp-mail-validator)](https://wordpress.org/plugins/wp-mail-validator/)
[![PHP](https://img.shields.io/wordpress/plugin/required-php/wp-mail-validator)](https://wordpress.org/plugins/wp-mail-validator/)
[![CI](https://github.com/kimpenhaus/mail-validator/actions/workflows/ci.yml/badge.svg)](https://github.com/kimpenhaus/mail-validator/actions/workflows/ci.yml)
[![License: GPL v2 or later](https://img.shields.io/badge/license-GPL--2.0--or--later-blue)](LICENSE)

A WordPress anti-spam plugin that checks the email address of commenters and registrants. Comments that fail are moved to spam, registrations are refused. Formerly WP-Mail-Validator.

**Install it from [WordPress.org](https://wordpress.org/plugins/wp-mail-validator/).** This repository is where it is developed.

## What it checks

| # | Check | Details |
|---|-------|---------|
| 1 | Your blocklist | Regular expressions, one per line, matched case-insensitively |
| 2 | Disposable mail services | 10,000+ domains from [disposable-email-domains](https://github.com/disposable-email-domains/disposable-email-domains), updated monthly |
| 3 | Syntax | WordPress' `is_email()` |
| 4 | Mail server | MX record, or A/AAAA record as fallback ([RFC 5321](https://www.rfc-editor.org/rfc/rfc5321#section-5.1)); null MX is rejected |
| 5 | Mailbox *(optional)* | SMTP `RCPT TO` on port 25, no mail is sent. Rejects only on `5.1.1`/`5.1.10` (recipient unknown); detects catch-all servers, caches results, skips itself when port 25 is blocked. For comments it runs in the background via WP-Cron by default: the comment is held as pending until the check is done |

Comments of logged-in users are never checked. Pingbacks and trackbacks can be accepted or dropped.

## Theme functions

```php
echo wp_mail_validator_info_label( '<p>', '</p>' ); // <p>Protected by <a …>Mail Validator</a>: <strong>42</strong> spam attacks blocked</p>
echo wp_mail_validator_fended_spam_attack_count();  // 42
echo wp_mail_validator_version();                   // the plugin version
```

## Development

Requirements: PHP 7.4+ and Composer – or just Docker.

```bash
composer install
composer test        # PHPUnit unit tests (Brain Monkey, no WordPress needed)
composer lint        # WordPress Coding Standards + PHP compatibility
composer lint:fix    # fix what can be fixed automatically
```

Without a local PHP:

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 install
docker run --rm -v "$PWD":/app -w /app php:8.4-cli vendor/bin/phpunit
```

| File | Purpose |
|------|---------|
| `wp-mail-validator.php` | Hooks, options, settings page |
| `includes/class-wp-mail-validator-checker.php` | Syntax, DNS and SMTP checks |
| `data/disposable-email-domains.txt` | Bundled disposable domain list |
| `readme.txt` | Plugin page on WordPress.org – keep it in sync with releases |
| `.distignore` | Files that are **not** shipped to WordPress.org |

CI runs the tests on PHP 7.4 – 8.4, the coding standards and the official [Plugin Check](https://wordpress.org/plugins/plugin-check/) on every pull request.

## Releasing

Releases are made by [release-please](https://github.com/googleapis/release-please) from the pull request titles ([Conventional Commits](https://www.conventionalcommits.org/)): `feat:` → new minor version (while below 1.0), `fix:` / `perf:` → patch, `docs:`, `chore:`, `ci:`, … → no release.

1. release-please keeps a pull request **"chore: release x.y.z"** open. It bumps the version in the plugin header, `WP_MAIL_VALIDATOR_VERSION` and the manifest, and adds the entry to `CHANGELOG.md`; a workflow copies that entry and the `Stable tag` into `readme.txt` (`.github/scripts/sync-readme.py`). Edit the changelog in that pull request if you like – `readme.txt` follows.
2. Merge it: release-please creates the tag and the GitHub release.
3. Approve the deployment in the *Actions* tab. The workflow deploys to WordPress.org (SVN) and attaches the zip to the release.

CI checks that all version numbers agree (`.github/scripts/check-versions.sh`). The upgrade notice in `readme.txt` is still written by hand, in the release pull request.

Banners, icons and screenshots for the plugin page go into `.wordpress-org/` (`banner-1544x500.png`, `icon-256x256.png`, `screenshot-1.png`, …); the deploy workflow uploads them to SVN `assets/`.

## Contributing

Issues and pull requests are welcome – see [CONTRIBUTING.md](CONTRIBUTING.md). Security issues: see [SECURITY.md](SECURITY.md).

## License

[GPL-2.0-or-later](LICENSE), like WordPress itself. Versions up to 0.6.5 were released under GPLv3. The bundled disposable domain list is [CC0](https://github.com/disposable-email-domains/disposable-email-domains/blob/main/LICENSE.txt).

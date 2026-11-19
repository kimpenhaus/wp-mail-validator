# Contributing

Thanks for helping to improve Mail Validator!

- **Bugs & ideas:** open an [issue](https://github.com/kimpenhaus/mail-validator/issues/new/choose).
- **Usage questions:** use the [WordPress.org support forum](https://wordpress.org/support/plugin/wp-mail-validator/).
- **Security issues:** see [SECURITY.md](SECURITY.md) – please report privately.

## Pull requests

1. Fork the repository and create a branch from the default branch.
   Name the pull request in the [Conventional Commits](https://www.conventionalcommits.org/) style
   with a scope (`feat(integrations): …`, `fix(checker): …`, `docs(readme): …`; the scopes are listed in
   `.github/workflows/pr-title.yml`) and a summary starting with a lowercase letter, without a final period:
   its title becomes the changelog entry. A check fails if it does not. Repairs to CI, tests, tooling or docs
   use `ci`, `test`, `chore` or `docs` with the scope of the part they concern (`test(checker): …`), never
   `fix`, so they stay out of the changelog. A revert made with GitHub's button is renamed to
   `revert(<scope>): …`.
2. Keep changes focused – one topic per pull request.
3. Follow the [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/):
   sanitize all input, escape all output, use nonces and capability checks for admin actions.
4. Describe how you tested the change (WordPress and PHP version).

## Releases

Releases are made by the maintainer with release-please: merging its release pull request publishes a GitHub release, which deploys the plugin to WordPress.org after manual approval.

#!/usr/bin/env bash
# Checks that the plugin header, WP_MAIL_VALIDATOR_VERSION, the Stable tag in readme.txt and the
# release-please manifest name the same version, and, if given, that it equals $1 (the release tag).
set -euo pipefail
cd "$(dirname "$0")/../.."

manifest=$(sed -n 's/^ *"\.": *"\([^"]*\)".*/\1/p' .release-please-manifest.json)
header=$(sed -n 's/^ \* Version: *//p' wp-mail-validator.php)
constant=$(sed -n "s/^define( 'WP_MAIL_VALIDATOR_VERSION', '\([^']*\)' );.*/\1/p" wp-mail-validator.php)
stable=$(sed -n 's/^Stable tag: *//p' readme.txt)
expected=${1:-$manifest}

echo "manifest=$manifest header=$header constant=$constant stable=$stable expected=$expected"

for version in "$manifest" "$header" "$constant" "$stable"; do
	if [ "$version" != "$expected" ]; then
		echo "::error::Version numbers differ; release-please keeps them in sync (see release-please-config.json and .github/scripts/sync-readme.py)."
		exit 1
	fi
done

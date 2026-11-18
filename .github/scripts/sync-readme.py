#!/usr/bin/env python3
"""Brings readme.txt in line with the release that release-please prepares.

WordPress.org reads the version and the changelog from readme.txt, which cannot
carry release-please's version markers. This script sets "Stable tag" to the
version in .release-please-manifest.json and copies that version's section of
CHANGELOG.md into the "== Changelog ==" section of readme.txt.

It is idempotent: running it again on an up-to-date readme.txt changes nothing.
Exit code 0 in both cases; it prints whether readme.txt was changed.
"""

import json
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]


def changelog_section(changelog, version):
    """Lines of the CHANGELOG.md section for a version, without its heading."""
    heading = re.compile(r"^## \[?" + re.escape(version) + r"\]?[ (]")
    lines = changelog.splitlines()

    for start, line in enumerate(lines):
        if heading.match(line):
            end = next((i for i in range(start + 1, len(lines)) if lines[i].startswith("## ")), len(lines))
            return lines[start + 1 : end]

    sys.exit(f"CHANGELOG.md has no section for {version}")


def readme_entry(section):
    """Converts a release-please CHANGELOG.md section to readme.txt lines.

    "### Fixed" followed by "* **scope:** something ([#12](…)) ([abc1234](…))"
    becomes "* Fixed: something (#12)", in the style of the older entries. Other
    text is kept as it is.
    """
    entry = []
    kind = None

    for line in section:
        line = line.rstrip()

        if line.startswith("### "):
            kind = line[4:].strip()
            continue

        bullet = re.match(r"^[*-] (.*)$", line)
        if bullet:
            text = bullet.group(1)
            text = re.sub(r"\s*\(\[[0-9a-f]{7,40}\]\([^)]*\)\)", "", text)  # commit links
            text = re.sub(r"\[(#\d+)\]\([^)]*\)", r"\1", text)  # PR links -> #12
            text = re.sub(r"^\*\*[^*]+:\*\* ", "", text)  # scope
            entry.append(f"* {kind}: {text}" if kind else f"* {text}")
        elif line or (entry and entry[-1]):
            entry.append(line)

    while entry and not entry[-1]:
        entry.pop()

    # No blank lines between the bullets of different sections.
    return [
        line
        for i, line in enumerate(entry)
        if line or not (entry[i - 1].startswith("* ") and i + 1 < len(entry) and entry[i + 1].startswith("* "))
    ]


def sync(readme, changelog, version):
    readme = re.sub(r"(?m)^Stable tag:.*$", f"Stable tag: {version}", readme, count=1)

    entry = f"= {version} =\n" + "\n".join(readme_entry(changelog_section(changelog, version))) + "\n"
    marker = "== Changelog ==\n\n"

    if marker not in readme:
        sys.exit("readme.txt has no '== Changelog ==' section")

    head, rest = readme.split(marker, 1)
    existing = re.match(r"= " + re.escape(version) + r" =\n.*?(?=\n= |\n== |\Z)", rest, re.S)
    if existing:
        rest = rest[existing.end() :].lstrip("\n")

    return head + marker + entry + "\n" + rest


def main():
    version = json.loads((ROOT / ".release-please-manifest.json").read_text())["."]
    readme_path = ROOT / "readme.txt"
    readme = readme_path.read_text()
    updated = sync(readme, (ROOT / "CHANGELOG.md").read_text(), version)

    if updated == readme:
        print(f"readme.txt is up to date for {version}")
    else:
        readme_path.write_text(updated)
        print(f"readme.txt updated for {version}")


if __name__ == "__main__":
    main()

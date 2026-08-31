#!/usr/bin/env python3
"""Parse Subversion status XML without relying on its column output."""

from __future__ import annotations

import argparse
import os
import sys
import xml.etree.ElementTree as ET


UNSAFE_ITEMS = {
    "conflicted",
    "incomplete",
    "missing",
    "obstructed",
    "unversioned",
}


def relative_trunk_path(path: str, working_copy: str) -> str:
    normalized = os.path.normpath(path)
    if os.path.isabs(normalized):
        normalized = os.path.relpath(normalized, working_copy)

    if normalized == "trunk" or normalized.startswith(f"trunk{os.sep}"):
        return normalized

    raise ValueError(f"status path is outside trunk: {path}")


def missing_roots(root: ET.Element, working_copy: str) -> list[str]:
    missing: list[str] = []
    for entry in root.findall(".//entry"):
        status = entry.find("wc-status")
        if status is None or status.get("item") != "missing":
            continue
        missing.append(relative_trunk_path(entry.get("path", ""), working_copy))

    selected: list[str] = []
    for path in sorted(set(missing), key=lambda value: (value.count(os.sep), value)):
        if any(path == parent or path.startswith(f"{parent}{os.sep}") for parent in selected):
            continue
        selected.append(path)

    return selected


def validate(root: ET.Element) -> list[str]:
    failures: list[str] = []
    for entry in root.findall(".//entry"):
        status = entry.find("wc-status")
        if status is None:
            continue
        item = status.get("item", "unknown")
        props = status.get("props", "none")
        tree_conflicted = status.get("tree-conflicted", "false") == "true"
        if item in UNSAFE_ITEMS or props == "conflicted" or tree_conflicted:
            failures.append(
                f"{entry.get('path', '')}: item={item}, props={props}, "
                f"tree-conflicted={str(tree_conflicted).lower()}"
            )
    return failures


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("mode", choices=("missing-roots", "validate"))
    parser.add_argument("--working-copy", default=os.getcwd())
    args = parser.parse_args()

    try:
        root = ET.parse(sys.stdin.buffer).getroot()
        if args.mode == "missing-roots":
            for path in missing_roots(root, os.path.abspath(args.working_copy)):
                sys.stdout.buffer.write(os.fsencode(path) + b"\0")
            return 0

        failures = validate(root)
        if failures:
            print("Unsafe Subversion working-copy state:", file=sys.stderr)
            for failure in failures:
                print(f"- {failure}", file=sys.stderr)
            return 1
        return 0
    except (ET.ParseError, OSError, ValueError) as error:
        print(f"Could not parse Subversion status: {error}", file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())

#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Compare Nextcloud's actual router registrations with pre-refactor contracts.

Usage: occ router:list <apps...> --index --output=json |
  python3 check-route-contracts.py routes-expected.json <apps...>
"""
import json
import pathlib
import sys

if len(sys.argv) < 3:
    raise SystemExit("Usage: check-route-contracts.py expected.json app [app...]")

manifest = json.loads(pathlib.Path(sys.argv[1]).read_text())
apps = set(sys.argv[2:])
if not apps or apps - set(manifest):
    raise SystemExit(f"Unknown or empty app set: {sorted(apps)}")

expected_rows = [row for app in apps for row in manifest[app]]
actual_rows = json.load(sys.stdin)
if not isinstance(actual_rows, list):
    raise SystemExit("Router output is not an array; cannot claim routes exist")

def flatten(rows):
    tuples = []
    for row in rows:
        if not isinstance(row, dict) or not all(
            isinstance(row.get(k), str) for k in ("route", "request", "path")
        ):
            raise SystemExit(f"Invalid router contract row: {row!r}")
        tuples.append((row["route"], row["request"], row["path"]))
    if len(set(tuples)) != len(tuples):
        raise SystemExit("Router result includes duplicate route signatures")
    return set(tuples)

expected = flatten(expected_rows)
actual = flatten(actual_rows)
missing, extra = expected - actual, actual - expected
if missing or extra:
    print("ERROR: Nextcloud route registration changed", file=sys.stderr)
    for label, entries in [("Missing", missing), ("Unexpected", extra)]:
        for entry in sorted(entries)[:20]:
            print(f"{label}: {entry}", file=sys.stderr)
    raise SystemExit(1)

print(f"PASS: Nextcloud resolved exactly {len(expected)} legacy route contracts"
      f" for {', '.join(sorted(apps))} (names, HTTP methods and URLs)")

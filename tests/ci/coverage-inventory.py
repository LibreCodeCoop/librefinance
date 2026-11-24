#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Report checked-out coverage inventory; never infer financial qualification."""
import json
from pathlib import Path
import re
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[2]
manifest = ET.parse(ROOT / "appinfo/info.xml").getroot()
routes = json.loads((ROOT / "tests/ci/routes-expected.json").read_text())
openapi = json.loads((ROOT / "openapi.json").read_text())
version = manifest.findtext("version")
if version != openapi["info"]["version"]:
    raise SystemExit("Manifest and OpenAPI versions disagree")
methods = {"get", "post", "put", "patch", "delete", "head", "options", "trace"}
operations = sum(len(methods.intersection(path)) for path in openapi["paths"].values())
core_api_routes = sum("/api/" in row["path"] for row in routes["librefinance"])
if operations != core_api_routes:
    raise SystemExit("OpenAPI operation count and core API route snapshot disagree")
matrix = ROOT / "docs/FUNCTIONAL-COVERAGE.md"
links = re.findall(r"\]\(([^)]+)\)", matrix.read_text())
missing = [
    link for link in links
    if not link.startswith(("https://", "#"))
    and not (matrix.parent / link.split("#", 1)[0]).is_file()
]
if missing:
    raise SystemExit("Missing matrix evidence paths: " + ", ".join(missing))
print(json.dumps({
    "manifest_version": version,
    "nextcloud": manifest.find("dependencies/nextcloud").attrib,
    "php": manifest.find("dependencies/php").attrib,
    "route_snapshot": {app: len(rows) for app, rows in routes.items()},
    "openapi_operations": operations,
    "core_migrations": sorted(p.name for p in (ROOT / "lib/Migration").glob("Version*.php")),
    "matrix_local_evidence_links": len([link for link in links if not link.startswith(("https://", "#"))]),
    "qualification": "not assessed; inspect execution evidence on the exact revision",
}, indent=2))

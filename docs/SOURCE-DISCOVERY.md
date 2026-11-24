<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Read-only source discovery

The source inventory contract is independent of a destination accounting
schema and complements (does not replace) the core's destination import
comparison. It can establish a bounded inventory of an adapter's **accessible
snapshot**, not financial equivalence, inaccessible resources or attestation
that a remote provider actually returned all its data.

## Adapter contract

An authorized integration supplies a `DiscoverySource` implementation to
`DiscoveryInventory::collect()`. There is no publicly callable endpoint,
credential store, provider activation or network transport in this component.
The adapter must have a separately verified read-only authorization scope and
finite transport timeout. Do not pass an administrator credential, source
URL or unfiltered source record body into the collector.

The adapter describes the source namespace and version, UTC cut-off
(`YYYY-MM-DDTHH:MM:SSZ`), opaque snapshot token, feature flags and resource
capabilities. It lists accessible workspaces and the records of each resource
through bounded cursor pages, always bound to that same snapshot. A successful
page has an explicit terminal null cursor or a continuation; it may supply a
source-reported total, which must remain consistent. No offset heuristic or
empty response can substitute for the explicit terminal marker.

Records contain opaque stable IDs, optional typed references to other resource
IDs within the same workspace, and optional lowercase SHA-256 artifact digest.
Do not transport personal names, descriptions, credentials, attachments or
financial values. Unknown named extension resources remain in the manifest with
`mappingRequired: true`. A capability absent from the standard resource
catalog is not interpreted as an empty collection.

## Manifest and completeness

The `librefinance.discovery.v1` manifest includes source version, cut-off,
feature and capability map, workspace-scoped totals, observed counts, optional
reported total, integrity digests, and reference statistics. A complete
collection's digest is independent of page boundaries and based on sorted
record IDs, references and optional artifact digests. It is not a digest of
financial values or source content.

| Collection status | Meaning |
| --- | --- |
| complete | Explicit terminal page with at least one unique observed record, no detected inconsistencies |
| empty | Explicit terminal page with zero observed records |
| missing_capability | Adapter did not expose the resource capability |
| unsupported | Adapter or source explicitly does not support the resource |
| excluded | Operator-approved adapter scope explicitly omits the collection; collector does not enumerate it, and cannot claim complete source coverage |
| access_denied | Current source authorization cannot enumerate the collection |
| timeout / unavailable | Transport did not complete; exceptions are sanitized |
| truncated | Nonterminating cursor, count mismatch, partial page or collection budget exhaustion |
| invalid_response / snapshot_changed | Invalid or inconsistent response; do not treat as complete |

Incomplete collections have `count: null`, `sha256: null` and an observed count
for diagnosis, not evidence of a successful empty export. A child reference is
unresolved only when the referenced collection is complete and the target is
absent; if the target collection is incomplete or unknown, it is unverified.
Either prevents a complete manifest. The manifest always declares
`sourceAttested: false` and `scope: accessible_workspaces_only`.

The collector accepts at most 100 source capability declarations plus standard
missing-resource entries, 100 feature flags, 100 workspaces, 250 records per
page, 5,000 total page requests, 20,000 total observed records (including
workspace IDs), 16 references per record and 40,000 total references. Exceeding
a traversal budget is explicitly incomplete rather than silently successful.
A larger source needs a reviewed resumable inventory procedure; do not raise
limits until memory, snapshot lifetime and authorization are validated.

## Operator verification

Run the synthetic fixture without network access:

```sh
php tests/import/discovery-inventory.php
```

For an approved future adapter, obtain the manifest from the authorized source
scope using its supported read-only snapshot mechanism. Keep real manifests
private (workspace identifiers and counts may be confidential). Check all
collection statuses, unresolved and unverified references, and unknown
extension mappings. Compare independently established source totals and
balances with the destination inventory at the same cut-off. An incomplete
manifest, even with zero observed rows, is not approval to proceed with a
financial cutover. No live connector or cutover is enabled here.

## Portable financial content

The separate [neutral financial interchange contract](ADR-0006-neutral-financial-interchange.md)
defines bounded, integrity-checked manifests, chunks and attachments for
authorized financial exports. Discovery totals are not financial content or
authorization to import.

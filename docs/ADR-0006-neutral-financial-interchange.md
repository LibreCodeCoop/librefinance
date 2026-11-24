<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# ADR 0006: Source-neutral financial interchange

**Status:** accepted for the offline interchange contract. Destination
mappings, source connectors and cutover authorization are separate work.

## Context and decision

The discovery manifest identifies resources accessible to a source adapter
without retrieving financial content. The existing normalized import API
imports a limited set of records. Neither is a portable archive of all
historical financial facts. Binding the ledger schema to another system's
tables would obscure ownership, currency precision and audit semantics.

Define a bounded, versioned, source-neutral interchange bundle. Exporters
provide the exact bytes of a manifest, ordered record chunks and declared
attachments. A pure offline inspector verifies integrity, structure,
completeness and reference scope before any destination planning. The
inspector does not call the network, write a database or a file, instantiate
a connector, apply a mapping, post a transaction, or grant access.

The versioned JSON Schema is
[financial-interchange-v1.schema.json](../schemas/financial-interchange-v1.schema.json).
Complete synthetic examples, including attachments, are under
[fixtures/interchange-v1](../tests/import/fixtures/interchange-v1/). The schema
validates each manifest or chunk's structure without a remote service. The
PHP inspector additionally enforces cross-document integrity, uniqueness,
dependency order, byte limits and reference scope. Neither structural
validation alone nor a self-consistent bundle attests source provenance.

## Wire contract

- Manifest format: `librefinance.interchange.v1`. Chunk format:
  `librefinance.interchange.chunk.v1`. Unknown versions are rejected with
  `unsupported_interchange_format` (manifest) or a chunk scope/format error.
  The existing normalized import API and its format are unchanged. Do not
  route an interchange bundle through that API or silently downgrade it.
- Source identity is the namespace (`system`) plus a stable opaque
  `instanceId`; `version`, `snapshotId` and UTC `cutoff` describe the export.
  The authorized operator must establish the instance identity independently.
  Neither source identity nor an opaque snapshot ID is a login credential.
- The manifest declares each accessible workspace and its resource
  collections. `complete` has an exact positive count, `empty` has zero,
  and all unavailable, denied, unsupported, invalid, changed or truncated
  states have a null count. A missing declaration is not an empty resource.
  Compare the declaration with independently approved inventory scope.
  A complete result means complete **relative to the declared scope**, not
  proof of complete source financial history.
- Each chunk descriptor includes an opaque ID, workspace, resource, count,
  raw byte length and lowercase SHA-256. The body repeats its scope and ID
  and contains ordered records. A record identity consists of source
  namespace, instance, workspace, resource and source record ID. A version
  identifies that record revision; the same identity must not occur twice
  in one snapshot, irrespective of version. Identical IDs in separate
  workspaces/resources or source instances are independent.
- Historical records carry explicit `active`, `archived` or `deleted` state,
  opaque version, and creation/update timestamps in UTC seconds or null
  when unknown. Dates cannot be invalid, reverse chronological order or
  postdate the snapshot. Source-specific structured fields can be retained
  without interpreting them as destination columns; floats, unsafe integer
  magnitudes and credential-shaped field names are rejected.
- Monetary values are a role, signed canonical base-10 integer **string**
  in minor units (up to 38 digits), three-letter currency code and scale
  0–9. For example `"12345"` at scale 3 represents 12.345 units; scale
  zero represents whole units. There is no conversion, rounding or
  binary floating-point arithmetic. Currency code and scale compatibility
  with a destination chart must be validated at mapping time.
- A typed reference identifies another record by workspace, resource and
  ID, plus role and relationship type. Both endpoints must be in the same
  workspace; a parent must precede its child in manifest chunk order and
  record order. Other references may point forward. Unknown resource
  names remain explicit, not implicitly mapped. A complete bundle
  cannot have a missing referenced record; incomplete bytes or an unknown
  collection prevent a claim of reference completeness.
- Attachments are opaque byte strings by declared artifact ID, with
  workspace, media type, length and SHA-256. Each must be referenced by
  a record in the same workspace. The inspector does not interpret,
  execute, extract or attest the file, MIME type or any fiscal signature.
  Export only approved historical evidence, not keys or tokens.

Transport JSON is compact UTF-8 with no BOM, trailing newline or extraneous
whitespace. Use literal Unicode and slashes, JSON escapes for control
characters, unique object keys, decimal integers and no floating-point
numbers. PHP's compact encoder (unescaped Unicode, slashes and line
terminators) defines the accepted byte representation. Independent exporters
can serialize using equivalent settings; preserve and hash the exact bytes
provided. Raw-byte integrity and record content comparison are different
checks: record digests use recursively sorted object keys (array order
remains significant) plus referenced attachment digests. A changed
attachment is a changed historical record even when its ID is stable.

## Offline inspection and restart

`BundleValidator::inspect($manifestBytes, $chunksById, $artifactsById,
$checkpoint, $previous)` validates one bounded snapshot. The returned
checkpoint contains the manifest byte hash and verified/missing chunk
and attachment IDs. Resuming requires the same manifest and actual
resupplied bytes; a previous checkpoint is never trusted in place of bytes.
No partial result exposes a complete record index. Wrong scope, repeated
identity, altered bytes, missing parent in a complete bundle and invalid
amounts cause sanitized `DomainException` codes. No source content is
included in errors.

A trusted previous complete index from the same source instance, with an
equal or earlier cutoff, may be compared. Same identity, version and
content hash is unchanged; a changed version or hash is a conflict to
review. An identity absent from a newly complete declared collection is
reported separately; it is not a deletion instruction. Do not silently
overwrite or delete posted facts, or replay historical payments as new
payments. A complete inspection always returns `sourceAttested: false`,
`applyAuthorized: false`, and `mappingRequired: true`. The conflict
counter is independent of transport completeness.

The inspector bounds manifest bytes to 512 KiB, chunks to 1 MiB each,
artifacts to 4 MiB each, total declared bytes to 16 MiB, 100 workspaces and
100 collections per workspace, 256 chunks and 256 artifacts, 250 records
per chunk, 10,000 records and 40,000 references per bundle. Record fields
have bounded nesting, list lengths, property counts and string bytes.
The caller must bound inputs before reading them into memory; large source
histories require a separately reviewed segmented snapshot/mapping plan,
not unbounded reads or weaker validation.

## Mapping boundary

| Source kind | Required destination decision |
| --- | --- |
| Master data / configuration | Map scoped stable identities and explicit configuration, never credentials or live authority. |
| Bank evidence | Preserve statement facts and match/reconcile; it is not a ledger posting. |
| Accounting entry | Preserve posted historical facts and balance evidence. No direct overwrite or implicit reposting. |
| Obligation / commercial document | Preserve document, counterparty and settlement links and historical status; no automatic settlement. |
| Settlement | Preserve its identity and links. Never initiate a payment or repeat a posted settlement. |
| Fiscal authorization | Preserve historical authorization metadata and approved evidence. Do not generate, authorize, cancel or resend a fiscal document. |
| Source-only historical fact | Preserve the portable record and declare the missing mapping. Never turn it into a financial transaction by guessing. |

A destination-specific plan must establish authorized workspace/company
bindings, resource mapping, exact balances, dependency order, idempotency,
posted-fact immutability, conflict disposition and cutover approval. This
ADR does not establish any of those mappings. Keep actual bundles private.
Credential-shaped field checks are defense in depth, not secret discovery:
exporters must exclude secrets, sensitive unrelated data and unauthorized
attachments before creating any bundle.

## Verification

Run the offline inspector against synthetic examples:

```sh
php tests/import/interchange-bundle.php
```

The supported database integration matrix also executes these assertions in
an installed Nextcloud and continues to run the pre-existing normalized
import regression suite. A Draft 2020-12 JSON Schema validator can check
each JSON document independently; the PHP suite checks cross-document and
negative cases that cannot be expressed by the structural schema.

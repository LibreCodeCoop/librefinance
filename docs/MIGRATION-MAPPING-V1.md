<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Neutral mapping vocabulary v1

Identifier: `librefinance.mapping.v1`. This is an exchange-level vocabulary,
not a public write API, destination adapter, grant of permissions or proof
of financial equivalence. It complements [ADR 0006](ADR-0006-neutral-financial-interchange.md)
and the [coverage matrix](FUNCTIONAL-COVERAGE.md). Ordinary imports do not
require a funding, personnel, fiscal or other optional domain.

## Stable source identity and explicit destination decisions

Every mapping is bound to the source system, instance, workspace, resource,
record identity, version and verified content hash. A separately authorized
destination workspace and (where applicable) company are selected before a
write-capable mapping can exist. Display labels, currency names, tax IDs,
Nextcloud groups and coincident local numeric IDs are not mapping authority.

The versioned vocabulary below describes source records and relation roles.
An exporter must preserve source fields when their meaning is unknown. A
consumer must declare an explicit supported mapping version and state:
supported, preserve-only, unavailable, excluded, conflicting or denied. Only
a future verified destination planner may authorize a supported mapping;
every row here is non-mutating. Missing or ambiguous relations require a
diagnostic, not automatic creation of accounts, projects or counterparties.

| Resource | Source record semantics | Required relationships and destination owner |
| --- | --- | --- |
| companies, contacts, items, categories, accounts, bank_accounts, taxes | Scoped catalog identities and original state | Accounting/catalog core; bank identity and local chart require independent validation |
| currencies, exchange rates | Original amount, currency, scale, date, rate source and immutable version | #44; preserve exact strings and rate metadata, no implicit conversion |
| invoices, invoice_lines, bills, bill_lines | Original commercial document, taxes, discounts, state and lines | #46; parent document, party, items, tax rules; no issuance |
| obligations, settlements, payments | Planned amount, due date, explicit historical paid amount, outstanding balance and original status | #46; `obligation`, `settlement`, `reverses` references, linked accounting records and original payment identity; never execute a payment |
| journal_entries, journal_lines | Original posted fact, accounting period, debit/credit and reversal links | #45; account IDs, journal parent, dimensions and linked adjustment, immutable posted facts |
| bank_statements, bank_statement_lines, transfers, reconciliations | Source evidence, opening/closing amounts, paired transfers and reviewed relationships | #47; account, original evidence, linked journals/payments; not inferred postings |
| dimensions | Stable scoped definition, parent and revision | #45; project, unit, purpose, department, funding or other classification |
| funding_sources | Source identity, original currency and restricted/own/external classification | #48; versioned source metadata, not a bank account |
| budget_lines, budget_revisions | Original approved amount, effective dates, version, increase/decrease and reason | #48; `funding_source`, `project`, `revises`; distinguish approved budget from actual cash |
| commitments | Original reserved amount and status; release/consumption history | #48; `obligation`, budget line and settlement links; preserve history, not reserve again |
| funding_allocations | Original signed allocated amount and currency/scale per allocation record | #48; `settlement` or commitment, `budget_line`, `reverses`; never infer a split from a label |
| custom_fields | Definition/version, original value and subject | #45/#67; scoped subject identity and approved definition; no guessed schema |
| employees, departments | Authorized historical workforce metadata without credentials or privilege grants | #64; preserve-only until the personnel domain validates mappings |
| reports, dashboard_settings | Original report/filter configuration and source presentation | #48/#67; preserve semantics without pretending historical calculations are qualified |
| attachments, fiscal_authorizations, fiscal_artifacts | Exact bytes, hash, original authority metadata and artifact type | #49/#61; explicit owning workspace and scoped historical record; never issue, cancel, transmit or activate signing keys |
| extension.restricted_funding | Versioned agreement, eligibility, review/appeal and report context | #74/#76; optional preserve-only records and links to core-owned budget, commitment and evidence; no mandatory extension |
| other extensions | Source-only records including archived/deleted state | #49/#66; unknown versions/relationships remain explicit, no inferred write |

For mixed-source allocations use one identity per allocation, one exact
signed amount and currency/scale, and explicit relation roles. A change in
content, relation or attachment hash is a conflict even if the source
version did not change. A reversal must link the original payment/allocation.
No currency conversion or accounting validation follows merely from a
complete transport. A later planner must reconcile original, functional
and outstanding amounts, including sign and rounding policy, against
authoritative source and destination controls.

## Operational bulk entry is different from migration

Both workflows need stable identity, bounded non-mutating preview,
row-level diagnostics, resumable processing and audit. A historical import
must retain original evidence, status and linkage and compare source/destination
balances at a consistent cutoff. Operational bulk entry proposes **new**
authorized domain operations. It must validate current accounts, roles,
currency, period, approval, allocation and budget controls atomically before
posting. An expense row is not proof of payment or reconciliation. Use
existing domain services and their idempotency keys, not direct table writes.

The existing normalized import endpoint remains compatible and has its own
preview/confirm boundary. Neutral snapshots are not accepted by that endpoint
as operational batches. A complete snapshot, repack or diagnostic is never
cut-over readiness.

## Lossless transport proof

`BundleRepacker::repack()` revalidates a complete bundle, changes chunk
boundaries/IDs, preserves the ordered source record content and exact
attachment bytes, and compares the resulting identity/content index with
the original. Missing or excluded bytes, invalid or duplicate identities,
unverified references and unsupported formats prevent repacking. It does
not resolve prior-source conflicts, authenticate provenance or install any
destination records.

The independent synthetic fixture in
[interchange-roundtrip.php](../tests/import/interchange-roundtrip.php) proves:

- BRL 1,000.00 obligation, 600.00 settlement, 100.00 reversal: net settlement
  500.00, original obligation unchanged. Grant allocation 420.00 minus 70.00
  = 350.00; own allocation 180.00 minus 30.00 = 150.00.
- Separate KWD 1.000 full settlement (three decimal places), never added to
  BRL. Budget source/revision, dimension, employee, custom field, fiscal
  reference, archived/deleted extension and binary evidence survive
  repartition and revalidation.
- Missing file/record, excluded/inaccessible collection, changed allocation
  with valid transport hashes, duplicate identity and unresolved budget line
  remain distinct. No implicit account, project or budget creation.

These are transport fixtures, not claims that the destination already
implements these economic functions.

## Scoped control-total comparison

The offline ControlTotalComparator compares independently computed, already
authorized control-total sets against a separately supplied plan. The plan
declares source/destination workspace pairs, currency, precision, required
control names and a common UTC cutoff. Values are canonical signed minor-unit
strings: missing evidence is not an explicit zero. The result is matched
only for the exact declared controls, different for unequal complete
evidence, or unverified for missing, duplicate, unexpected, unsupported or
cutoff-mismatched evidence. No amount is summed across books or currencies.

This helper does not compute the source or destination totals, authenticate
provenance, map accounts, import records or establish migration readiness.
The owning domain contracts must still define independently reproducible
control totals and their sign conventions.

## Handoff to historical migration (#66)

Review the actual authorized source inventory against this vocabulary and
the destination coverage matrix at an agreed cutoff. The supported existing
normalized import covers only its documented resource families. Currency,
period/approval, settlement history, dimensions, budget, evidence,
optional-domain and other missing destination contracts are independent
implementation prerequisites only for source scopes that need them. Do not
add a blanket dependency or claim universal product parity from a source
that has no records in those domains. A later source-specific mapping must
document unsupported fields, verified-empty vs unknown, original-to-target
identity graph, preview, replay/rollback, counts/hashes and independent
financial reconciliation before any cutover. No credentials or live data
are required for this handoff.

### Contact profile mapping (v1)

Map from an authorized source only after resolving its stable contact identity
to the destination workspace/contact ID. Preserve the source contact name,
kind, email and tax identifier using the existing catalog contract. Optional
profile fields are contactPerson, phone, website, billingAddress and
shippingAddress; address objects carry line1, line2, city, region, postalCode
and explicit ISO alpha-2 country. Preserve Unicode, punctuation and leading
zeros (postal code is text). A missing country stays missing; a source
single-address field may only map to a documented address role, not both.
Do not infer legal residence, tax jurisdiction or source identity from text.
An absent optional field on update is not permission to clear it. Explicit
null clears the named field/address. Archive through active=false, without
removing the contact ID or rewriting commercial evidence. The synthetic
ContactProfileTest and contact-profile integration fixture exercise a distinct
billing/shipping pair, missing values, similar names, partial changes,
archive and preserved issued evidence. A source-specific adapter remains
out of scope.

<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Domains and external integrations — v0.18 (development)

The original six optional finance apps were consolidated into **one core
Nextcloud app plus one independent NFS-e integration**. These boundaries are determined
by who owns financial data and by independent compliance/release requirements.

| Runtime owner | Functions | Physical data |
| --- | --- | --- |
| LibreFinance core | Ledger, workspaces, catalog, accounts and permissions | `lf_*` |
| LibreFinance core | Bank CSV import, pending statements, transfers, reconciliation | `lfb_*` |
| LibreFinance core | Payables, receivables, partial settlements | `lfob_*` |
| LibreFinance core | Commercial invoices, generic tax rules and allocations | `lfi_*` |
| LibreFinance core | Accounting reports and saved report templates | `lfr_saved_reports` |
| LibreFinance core | Manager-approved import/migration, source mapping and connector read previews | `lfm_*` |
| Independent NFS-e app | Brazilian NFS-e preparation only, not live fiscal issuance | `lfn_drafts` |

The former module names remain *logical feature keys* used by
`FinanceApi::requireModule()`, the access flags in `lf_module_flags` and
the single canonical Vue shell. They are **not** required as separate
Nextcloud installations. The retired separate banking, invoicing, obligations and reporting
repositories are not runtime dependencies. Legacy Import data is covered by a pinned synthetic historical-schema fixture
in core CI; installing the old Import app is not required for new installs.

## Built-in normalized data imports

The three lfm_* tables belong to the core and remain non-destructive on upgrade.
The manager-only import engine accepts explicitly supplied normalized JSON for
companies, contacts, categories, accounts, pending bank movements, transfers
and read-only inventory reconciliation. Existing source IDs, hashes, bank
references and audit history are retained. Source balances are snapshots,
not posted ledger transactions.

Direct Akaunting connector and live Akaunting-to-LibreFinance migration are
OUT OF SCOPE for incorporation. No remote credentials or API endpoints are
accepted by this feature. Provider discovery, pagination and mapping will
be reviewed in a separate change.

Before upgrading an installation with the independent Import app, make and
verify a complete database backup. Disable but NEVER UNINSTALL the old app:
uninstall hooks could delete historical data. Upgrade the core, verify record
counts and references, and only then remove old app code from the runtime.
Keep a verified archive for rollback. Tests cover historical table preservation
on SQLite, MariaDB and PostgreSQL in disposable fixtures. Third-party data
completeness and financial equivalence remain unverified.

The bounded, read-only source discovery contract is described in
[SOURCE-DISCOVERY.md](SOURCE-DISCOVERY.md). It is not a live source connector
or a replacement for the destination inventory comparison.

## Server-side module contract

Only NFS-e registers an independent `ModuleDescriptor` instance
through `RegisterModulesEvent` and OCP event listeners. Their PHP endpoints
check the current user, permissions and per-space feature flag. The five
trusted built-in domain IDs are registered by `ModuleService` directly and
their controller routes belong to `librefinance`. An external app cannot
claim a built-in feature ID, inject HTML/JavaScript into the main app, or
bypass authorization by hiding navigation links.

The banking provider contract `Domain/Banking/Providers/BankProviderInterface`
is experimental and independent of importing CSV statements. It receives only
a credential *reference*. Automatic bank synchronization, secure bank tokens
and live institution transports are NOT implemented. Sicoob-specific sample
code stays in the historical banking repo pending explicit extraction and
review; it is not loaded as a production integration.

## Atomic financial model

An internally issued commercial invoice creates one linked financial
obligation, with a unique relationship in `lf_invoice_obligation_links`.
A validated ledger allocation is the **only** permitted path to pay that
linked obligation. Updating the invoice payment projection and creating its
obligation settlement happen in the **same SQL transaction**. The obligation
settlement retains the journal reference. Direct settlement or cancellation
of an invoice-linked obligation returns 409, preserving the accounting audit
trail and blocking duplicate payment paths.

Invoices issued **before** this version are not auto-linked by number or
contact: their identity and pre-existing payments need a reconciliation
procedure, not a guessed data migration. A new commercial invoice is not
a government-issued fiscal document.

## Upgrade from the former four separate finance modules

Before upgrading an existing development installation, record the exact
seven-app SHAs and take a **verified, restorable** database snapshot.
Temporarily stop financial writes. Disable, **do not uninstall**, the former
Banking, Invoicing, Obligations and Reports apps; uninstall handlers may remove
historic financial tables. Upgrade core while keeping the former Import app disabled, run
`occ upgrade` and compare row counts, per-space balances, foreign references
and the installed route contract with the snapshot. The core retains historic
table names and does not infer or recreate old invoice payment relationships.
If an upgrade check fails, restore the matched database and original app
versions, not a partial schema. An issued invoice without a verified
obligation link must be reconciled before payment allocation.

For schema ownership and testing details, see [schema](SCHEMA.md) and
[testing](TESTING.md).

## Extension development requirements

Keep migration a manager-only built-in feature and fiscal integrations separately
installed, secure, reviewable and enabled deliberately by administrators. A workspace manager
may toggle availability **for that workspace** but cannot install arbitrary
PHP code. Data writes must use a transaction-scoped bank account reservation
`FinanceApi::reserveBankAccountsForWrite()`, and must not import
Nextcloud-private APIs from `OC\` classes as a compatibility contract.

The UI still uses a fixed map of approved screens. Versioned safe third-party
UI extension points, l10n (including English), multi-currency conversion,
live banking, fiscal homologation and accounting certification are **future
work**, not delivered by the consolidation.

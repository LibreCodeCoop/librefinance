<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Domains and external integrations — v0.17 (development)

The original six optional finance apps were consolidated into **one core
Nextcloud app plus two optional integrations**. These boundaries are determined
by who owns financial data and by independent compliance/release requirements.

| Runtime owner | Functions | Physical data |
| --- | --- | --- |
| LibreFinance core | Ledger, workspaces, catalog, accounts and permissions | `lf_*` |
| LibreFinance core | Bank CSV import, pending statements, transfers, reconciliation | `lfb_*` |
| LibreFinance core | Payables, receivables, partial settlements | `lfob_*` |
| LibreFinance core | Commercial invoices, generic tax rules and allocations | `lfi_*` |
| LibreFinance core | Accounting reports and saved report templates | `lfr_saved_reports` |
| Independent Import app | Approved preview and migration of normalized data | `lfm_*` |
| Independent NFS-e app | Brazilian NFS-e preparation only, not live fiscal issuance | `lfn_drafts` |

The former module names remain *logical feature keys* used by
`FinanceApi::requireModule()`, the access flags in `lf_module_flags` and
the single canonical Vue shell. They are **not** required as separate
Nextcloud installations. Their historical Git repositories are kept for
review/traceability, not as active runtime dependencies. No repository is
deleted or archived as a side effect of this work.

## Server-side module contract

Only Import and NFS-e register independent `ModuleDescriptor` instances
through `RegisterModulesEvent` and OCP event listeners. Their PHP endpoints
check the current user, permissions and per-space feature flag. The four
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
historic financial tables. Upgrade the core and Import app together, run
`occ upgrade` and compare row counts, per-space balances, foreign references
and the installed route contract with the snapshot. The core retains historic
table names and does not infer or recreate old invoice payment relationships.
If an upgrade check fails, restore the matched database and original app
versions, not a partial schema. An issued invoice without a verified
obligation link must be reconciled before payment allocation.

For schema ownership and testing details, see [schema](SCHEMA.md) and
[testing](TESTING.md).

## Extension development requirements

Keep imports and fiscal issuance separate, secure, reviewable and disabled
until explicitly enabled by a Nextcloud administrator. A workspace manager
may toggle availability **for that workspace** but cannot install arbitrary
PHP code. Data writes must use a transaction-scoped bank account reservation
`FinanceApi::reserveBankAccountsForWrite()`, and must not import
Nextcloud-private APIs from `OC\` classes as a compatibility contract.

The UI still uses a fixed map of approved screens. Versioned safe third-party
UI extension points, l10n (including English), multi-currency conversion,
live banking, fiscal homologation and accounting certification are **future
work**, not delivered by the consolidation.

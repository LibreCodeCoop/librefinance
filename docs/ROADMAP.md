<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# LibreFinance development roadmap

This document records user-visible gaps without claiming that an unimplemented feature or a prototype is production-ready. Only NFS-e remains separately installed. Banking, obligations, invoicing, reporting and import belong to the core; former modules are retained only for historical upgrade compatibility. This roadmap does not authorize enabling any external financial integration or publishing a release.

## Foundation (blocking production use)

1. Establish signed DCO-compliant, reviewable source history and organization-managed workflows.
2. Run an **integrated** quality matrix for the exact revisions of the core and the optional NFS-e integration; a missing required optional-module checkout must cause a failure rather than silently falling back to core-only tests; a separate core-only job verifies optionality.
3. Prove ledger and bank-account reference integrity under concurrent account deletion, posting, invoice allocation and bank transaction import, across supported databases.
4. Verify upgrades from existing schemas, authorization revocation, multi-workspace isolation, backup/recovery and PHP/Nextcloud compatibility against pinned revisions.

## International use

- Introduce Nextcloud-native translations, starting with English and Brazilian Portuguese, and extract all user-visible strings.
- Remove the BRL-only ledger restriction with a versioned currency and exchange-rate policy, preserving historical financial values precisely. No silent exchange conversion. Follow the [proposed functional and original currency contract](SCHEMA.md#proposed-functional-and-original-currency-ledger-contract-v2); qualify multi-line persistence, bank and commercial writers, import/report paths and native UI before activating any new currency. The contract itself is not ledger activation.
- Implement locale-aware dates, amounts and time zones, and properly version legal, tax and payment rules by jurisdiction.
- Separate optional country-specific integrations (such as Brazil's NFS-e) from the international core.

## Independently deliverable functionality

- Versioned third-party extension descriptor, navigation and safe user interface integration with backend-enforced permissions.
- Bank institution/account identity, connection consent, secure credentials and audited reconciliation before automated synchronization. Historical institution-specific scaffolding is not a supported runtime connector.
- Source-independent historical extraction and independently verified destination data migration, including accounts, statements, documents and reconciliation totals. The offline inventory, neutral transport and [mapping vocabulary](MIGRATION-MAPPING-V1.md) distinguish complete source records from unavailable scope, conflicts and missing destination capabilities; they do not authorize applying a bundle.
- Complete audited reconciliation of legacy issued invoice obligations, recurring commitments, credit notes and fully tested financial statements. New commercial invoices now have a linked payable/receivable payment path in one transaction.
- Formal balance sheet, financial statements and extensible saved reports with verified period/dimension semantics.
- Audited cooperative member production, period closure and allocation as an optional module, not a mandatory national specialization.
- Independent interoperability tests for bank providers, country-specific fiscal services and MCP clients.

## Native financial journeys

See the [native journey audit and component wireframes](NATIVE-FINANCE-JOURNEYS.md)
for the observed synthetic runtime baseline, priorities and implementation acceptance states.

- Preserve the selected workspace and account across movements, accounts,
  statements and linked documents; present the booked balance separately
  from unreviewed statement amounts. Keep one payment and reconciliation
  workflow, with no duplicate posting.
- Improve the CSV/OFX import and recovery journey. Keep the downloadable
  sample and format limits; introduce a bank-statement dry run only after
  a server-side validation, duplicate and authorization contract exists.
  Do not confuse this with the normalized migration preview.
- Refine named account, direction and status filters, keyboard and dialog
  focus, loading, empty, denied and recoverable error states. Preserve stable
  routes and existing server-side authorization.
- Keep one invoice creation entry point, explicit sale/purchase choice and
  linked settlement status. Use the existing reports destination for
  templates and saved reports.
- Group workspace access, invitations, policy and administration through
  native Nextcloud disclosure and navigation components. Test authorized
  feature visibility, direct API denial, translated controls and mobile
  layouts independently.

These refinements are proposals, not evidence of completed implementation.
Maintain operator instructions in `FINANCIAL-WORKFLOW.md` and
`MODULES.md`, and document any new backend contract in the API
documentation before adding a corresponding control.

Each feature requires its own observable API/UI tests, permission denials, appropriate concurrency checks and user/admin documentation. Public source publication does **not** equal release or financial certification.

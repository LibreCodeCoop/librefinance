<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# LibreFinance development roadmap

This document records user-visible gaps without claiming that an unimplemented feature or a prototype is production-ready. Only Import and NFS-e remain separately installable; banking, obligations, invoicing and reporting are built into the core. This roadmap does not authorize enabling any external financial integration or publishing a release.

## Foundation (blocking production use)

1. Establish signed DCO-compliant, reviewable source history and organization-managed workflows.
2. Run an **integrated** quality matrix for the exact revisions of the core and both external integrations; missing module checkouts must cause a failure rather than silently falling back to core-only tests.
3. Prove ledger and bank-account reference integrity under concurrent account deletion, posting, invoice allocation and bank transaction import, across supported databases.
4. Verify upgrades from existing schemas, authorization revocation, multi-workspace isolation, backup/recovery and PHP/Nextcloud compatibility against pinned revisions.

## International use

- Introduce Nextcloud-native translations, starting with English and Brazilian Portuguese, and extract all user-visible strings.
- Remove the BRL-only ledger restriction with a versioned currency and exchange-rate policy, preserving historical financial values precisely. No silent exchange conversion.
- Implement locale-aware dates, amounts and time zones, and properly version legal, tax and payment rules by jurisdiction.
- Separate optional country-specific integrations (such as Brazil's NFS-e) from the international core.

## Independently deliverable functionality

- Versioned third-party extension descriptor, navigation and safe user interface integration with backend-enforced permissions.
- Bank institution/account identity, connection consent, secure credentials and audited reconciliation before automated synchronization. The Sicoob adapter is currently read-only scaffolding without a live transport.
- Source-independent, paginated historical extractors and independently verified data migration, including accounts, statements, documents and reconciliation totals.
- Complete audited reconciliation of legacy issued invoice obligations, recurring commitments, credit notes and fully tested financial statements. New commercial invoices now have a linked payable/receivable payment path in one transaction.
- Formal balance sheet, financial statements and extensible saved reports with verified period/dimension semantics.
- Audited cooperative member production, period closure and allocation as an optional module, not a mandatory national specialization.
- Independent interoperability tests for bank providers, country-specific fiscal services and MCP clients.

Each feature requires its own observable API/UI tests, permission denials, appropriate concurrency checks and user/admin documentation. Public source publication does **not** equal release or financial certification.

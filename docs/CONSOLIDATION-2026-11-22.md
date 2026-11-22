<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Consolidation into three Nextcloud applications — 2026-11-22

## Decision and scope

The LibreFinance core owns banking and reconciliation, commercial invoicing and generic tax rules, accounts payable/receivable and operational financial reports. They no longer require four other Nextcloud applications. Two independent integrations remain: `librefinance_import` and the Brazil-specific `librefinance_nfse` (draft preparation only). No software from this repository needs LibreCode-operated servers or an independent runtime.

The implementation copies the audited business behavior into `OCA\LibreFinance\Domain\...` and PHP controllers into `OCA\LibreFinance\Controller`. It preserves tested application permissions and per-space feature IDs (`librefinance_banking`, `librefinance_invoicing`, `librefinance_obligations`, `librefinance_reports`), but uses `librefinance` as the HTTP route owner. The four historical repositories remain available for review and are not archived or deleted.

## Migration and data invariants

The old `Version000100Date20261121190000` baseline remains intact. `Version000200Date20261122120000` creates or recognizes the existing physical tables with their historical names (`lfb_*`, `lfob_*`, `lfi_*`, and `lfr_saved_reports`), then adds only the invoice-to-obligation link and nullable settlement journal reference. It does not truncate, rename, re-key or remove financial records. Existing installed apps still have their own recorded Nextcloud migrations; never assume they have not run just because this project is pre-release.

Newly issued commercial invoices create one receivable/payable with a unique `(space_id, invoice_id)` and `(space_id, obligation_id)` link. Allocations require a validated bank journal. The invoice and obligation paid balances, status and one settlement record are committed atomically; a linked obligation cannot be paid or cancelled independently. The implementation uses integer minor units and never creates a bank journal from an invoice draft.

Existing invoices already issued before consolidation have no verified link. **Do not construct the link by matching contact, reference or amount**; do not automatically assume a partial payment is a complete historic settlement. Such invoices need an explicit controlled reconciliation workflow before additional allocation is allowed. Existing unaffiliated obligations remain unchanged and can still be settled operationally. This is a release blocker for installations where legacy issued invoices exist.

## Safe upgrade procedure (development only)

1. Record the exact current SHAs of all installed finance apps, take a database backup, verify that it can be restored in a disposable instance, record counts, states and IDs per financial space, then temporarily stop financial writes.
2. Disable `librefinance_reports`, `librefinance_obligations`, `librefinance_invoicing` and `librefinance_banking` using `occ app:disable`. **Never uninstall** with `occ app:remove` or delete their tables, because uninstall hooks may be destructive.
3. Deploy the reviewed LibreFinance core build and compatible `librefinance_import` branch. Keep `librefinance_nfse` independently installed only if required. Run `occ upgrade` on the reviewed set.
4. Confirm the new migration and 31 expected core-owned tables, table/index/nullability parity, no missing or duplicated monetary rows, no unintended changes in historic journals, and that Import can stage movements via the core `BankImportApi` / `BankTransferApi`. Compare per-space record counts and financial balances before/after.
5. Verify isolation, denials, idempotency, journal claims and reservation against concurrent account deletion in PostgreSQL, SQLite and MariaDB disposable environments. Check the frontend and exact 94 route contracts when both external integrations are installed.
6. If any check fails, restore the verified database snapshot and previous seven-app code as a matched set. Do not attempt an improvised partial migration or delete finance data to make `occ upgrade` pass.

## Explicit non-goals and release gates

- No live bank synchronization, configured Sicoob transport, bank token, fiscal authority NFS-e issuance, migrated production records, accounting certification or full multi-currency support.
- Existing Sicoob adapter source remains in the historical banking repository for extraction into a separately reviewed connector, not in the global core runtime.
- No public distribution, deploy or direct push to main until independent PR review, GPG+DCO verification, organization CI, all real backend/frontend tests, multi-database clean installs, and pre-existing data upgrades pass on the combined SHAs.
- User-facing interface remains mainly pt-BR, which is a known limitation for the intended international audience. The country field must be explicit; currency policy still requires a separate implementation.

## Validation evidence

Source changes were prepared in isolated worktrees on Poland, with executable test logs saved under `/tmp/lf-core-domains-*`. A successful standalone core-only SQLite installation and original functional domain tests were observed; combined three-app database checks and upgrade verification must be recorded separately rather than presumed. Logs are disposable laboratory evidence, not an attestation about production.

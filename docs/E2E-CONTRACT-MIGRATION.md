<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# E2E coverage after finance navigation consolidation

The legacy financial agenda, separate sales/purchases menus, standalone bank
statement page and top-level field-location selector no longer exist.
The new navigation uses **Movimentações**, **Faturas**, contextual account
filters and a field-location selection inside the **Novo campo** dialog.

The obsolete checks listed below were overlapping product contracts, not
security test exclusions. Their modern tests remained in the same Playwright
matrix and passed on the earlier PR #7 Chromium run (46 successes across
three shards). Do not remove a current test merely to clear CI.

| Retired obsolete UI assertion | Current regression coverage |
| --- | --- |
| `@extensions one Nextcloud navigation switches operational views without iframe` | `simplified-finance: bank account filter and unified finance navigation` |
| `@extensions task-oriented navigation has one agenda and one management group` | `ux-refactor: management hierarchy under one management section` |
| `@extensions mixed payable/receivable list filters both directions and due dates` | `simplified-finance: planned and posted movements and server status filtering` |
| `@extensions sales and purchases share the same agenda and old URLs` | `simplified-finance: one invoice list with server-side type filter` |
| `@extensions personal workspace offers unified finance and manager tools` | `simplified-finance: personal movement workflow and available navigation` |
| `@extensions disabling invoicing preserves standalone pay/receive flow` | `simplified-finance: movement posting with optional modules disabled` |
| `@extensions dashboard uses genuine cash-flow aggregates and no duplicate entry action` | `ux-refactor: configurable overview widgets and simplified finance` |
| `@extensions first-time user can record an expense, review its effects and reconcile that exact bank item` | `simplified-finance: posted movement and inline reconciliation` |
| `@extensions cancelling a CSV import clears the selected file and does not import anything` | `ofx-and-fields: CSV native picker, cancellation and OFX import` |
| `@extensions standalone payable may have explicit administrative settlement without ledger` | `simplified-finance: planned versus posted movement without duplicate journaling` |
| `@extensions payable payment posts one cash journal and updates the existing title` | `simplified-finance: partial settlement and balance reconciliation` |
| `@extensions invoice is entered once and remains linked to the same receivable` | `simplified-finance: confirmed sale creates only one planned line` |
| `mobile layout uses responsive Nextcloud shell without horizontal overflow` | `simplified-finance: 4K/mobile financial history layout` |
| `financial agenda fills a 4K workspace and hides columns in an empty result` | `simplified-finance: responsive 4K and mobile financial history` |
| `banking hides filters and columns on empty lists` | `simplified-finance: bank evidence and account-filter workflow` |
| `@extensions commercial documents have their own route and create actions` | `simplified-finance: sale/purchase in one invoice register` |
| `empty pay and receive register shows no filters or empty table, one clear action` | `simplified-finance: empty movement state and one Register action` |
| `unified payable register shows one due account and bank evidence remains unposted` | `simplified-finance: planned/posted movements with bank evidence separate` |
| `purchases remain detailed and pay/receive stays one combined register` | `simplified-finance: invoice type selector and unified movement list` |
| `an issued sale creates one receivable without adding another work-queue invoice` | `simplified-finance: one confirmed sale, one planned line and partial settlement` |
| `pending bank evidence stays distinct from due accounts until conciliation` | `simplified-finance: pending bank import and inline reconciliation` |
| `money already paid or received uses the existing accounting journal without a due account` | `simplified-finance: posted movement without double posting` |
| `single pay receive register and separate bank account registration do not create a second dashboard` | `simplified-finance: account-filter and single dashboard navigation` |

Unique integration/security scenarios remain executable, notably cross-space
bank isolation, Nextcloud unified-search authorization, external import
coverage, authorized custom-field persistence and the company edit workflow.
Their selectors were migrated instead of removing these scenarios.

The original browser logs with the retired contracts are in GitHub Actions
run 45113695705. The purpose of this migration is to stop checking two
incompatible UIs simultaneously, while retaining the current end-to-end
functional checks. Any missing business requirement requires a new test
against the current contract, not resurrection of the old navigation.

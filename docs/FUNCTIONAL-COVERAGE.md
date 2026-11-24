<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Functional capability and qualification matrix

LibreFinance is an international, Nextcloud-native finance platform. The core
owns the authoritative books and shared financial records; separately installed
apps are reserved for justified provider, jurisdiction or specialized-domain
boundaries. This matrix is the canonical description of functional coverage,
not a count of routes, tables or issues. See [installation and upgrade](MODULES.md),
[financial workflows](FINANCIAL-WORKFLOW.md), and [test strategy](TESTING.md).

**Support levels:** **Preview** = implementation and a relevant executable test
exist; not release qualification. **Partial** = some named behavior exists,
but the stated missing capability prevents full coverage. **Planned** =
no supported implementation is asserted; the scenario specifies future
acceptance. **External** = optional integration requires real provider or
jurisdiction qualification in addition to code. No row is certified for
production accounting, fiscal or regulatory operation by this matrix.

## International core

| Workflow | Support | Owner / epic | Existing evidence | Missing behavior / acceptance scenario |
| --- | --- | --- | --- | --- |
| Personal, collective and independent organizational books | Preview | Core #11 | [spaces](../tests/integration/spaces.php), [personal](../tests/integration/personal-finance.php), [SaaS access](../tests/integration/saas-access.php) | Demonstrate clean and historical upgrades; two independently provisioned legal companies keep distinct accounts, balances and roles. |
| Group enablement, delegated administration, policies and external invitations | Partial | Core #11 | [lifecycle](../tests/integration/saas-lifecycle.php), [group deletion](../tests/integration/saas-group-delete.php), [policy](../tests/integration/saas-policy.php), [browser invitations](../tests/e2e/saas-invitations.spec.mjs) | Verify native subadmin, complete lifecycle and legacy classification, actual desktop/mobile accessibility and permission denials after revocation. |
| Chart of accounts, balanced journal posting, reversal and account balances | Preview | Core #45 | [basic ledger](../tests/integration/basic.php), [posting concurrency](../tests/integration/advanced.php), [account reference race](../tests/integration/account-deletion-race.php) | Prove period-close rules and audited opening balances; attempted duplicate or invalid posting must not change balances. |
| Exact currencies and exchange-rate history | Partial | Core #44 | [ledger](../tests/integration/basic.php), [money formatting](../tests/frontend/money.test.mjs) | Current ledger accepts BRL only. Test currency precision, dated exchange rates, balanced FX and revaluation without rewriting historic postings. |
| Legal company identity, contact and item catalogs | Preview | Core #45 | [company registry](../tests/integration/companies.php), [catalog](../tests/integration/catalog.php), [atomic legal bootstrap](../tests/e2e/saas-legal-company-bootstrap.spec.mjs) | Multiple branches and complex counterparties; legal identity must not automatically confer ledger ownership. |
| Categories, custom fields and financial dimensions | Partial | Core #45 | [categories](../tests/integration/categories.php), [field studio](../tests/integration/field-studio-v3.php), [field authorization](../tests/integration/custom-fields.php) | Add cost centers, projects, multiple dimensions and period reporting; denied custom fields must not leak through any output. |
| Native navigation, localization, dashboard, search and activity | Partial | Core #67 | [navigation](../tests/frontend/navigation.test.mjs), [UX](../tests/e2e/ux-refactor.spec.mjs), [search](../tests/integration/agenda-search-sort.php), [activity](../tests/integration/activity-feed.php) | Portuguese-first UI; translate source strings, switch locale and keyboard/mobile navigation without changing stored values. |
| Commercial invoices, generic tax rules and allocations | Partial | Core #46 | [invoicing](../tests/integration/domains/invoicing.php), [payments](../tests/integration/domains/payments.php) | Add quotes/orders, recurring invoices, credit notes and historical invoice-obligation reconciliation; test issue, partial allocation, reversal and duplicate rejection. Commercial issue is not fiscal authorization. |
| Payables, receivables and manual settlement | Partial | Core #46 | [obligations](../tests/integration/domains/obligations.php), [payments](../tests/integration/domains/payments.php), [payment methods](../tests/integration/payment-methods.php) | Complete recurring commitments, approval, payment lifecycle and historical links. Verify linked invoices cannot be settled by a duplicate route. |
| Bank accounts, CSV/OFX statement evidence and reconciliation | Partial | Core #47 | [bank identity](../tests/integration/bank-identity.php), [banking](../tests/integration/domains/banking.php), [OFX parser](../tests/unit/ofx-parser.php), [formats policy](../tests/e2e/saas-bank-import-formats.spec.mjs) | Add assisted matching, full transfer/card flows and formats beyond CSV/OFX. Imported statements stay pending until an authorized reconciliation; replay cannot create duplicates. |
| Bank balance comparison | Partial | Core #47 / #66 | [import](../tests/integration/domains/import.php), [banking](../tests/integration/domains/banking.php) | Source balance snapshots are diagnostic, not postings or certified reconciliation. Compare opening/closing balances and unmatched entries at a common cutoff. |
| Realized cash flow, trial balance, simple result, saved reports | Partial | Core #48 | [cash flow](../tests/integration/domains/reports-cash-flow.php), [saved reports](../tests/integration/domains/reports-saved.php) | Formal statements, projected cash flow, complete reporting dimensions and approved immutable snapshots. Compare totals with independently computed journal balances. |
| Funding and budget availability | Planned | Core #48 / #74 | No supported budget availability workflow asserted | Given an approved budget and simultaneous requests, verify available balance, scope, exact arithmetic and no overspend. |
| Commitments and releases | Planned | Core #48 / #74 | No dedicated budget commitment lifecycle asserted | Reserve and release committed funds idempotently, audit each transition, preserve posted accounting facts. |
| Phased disbursement | Planned | Core #74 | No restricted-funding disbursement workflow asserted | Authorize installments against approved milestones and verify partial, cancelled and final disbursement. |
| Batch operations | Partial | Core #46 / #47 | [transactional source import](../tests/integration/domains/import.php), [bank statement batch](../tests/integration/domains/banking.php) | General authorized financial batch actions are not claimed. Test per-item denials, partial failure policy and no duplicate posting on retry. |
| Evidence and document versioning | Partial | Core #49 / #74 | [company logo](../tests/integration/company-logo.php) demonstrates scoped file-like data, not a financial evidence lifecycle | Implement scoped Nextcloud Files evidence links, versions, immutable approved references and revocation. Verify historical references survive replacement. |
| Restricted-funding and agreement accountability | Planned | Core #74 | No supported grant/agreement lifecycle asserted | Track restricted resources through eligible expenses, evidence, approvals, commitment release and reporting; deny cross-funding access. |
| Source-independent import, identity and inventory diagnostics | Partial | Core #40 / #66 | [import](../tests/integration/domains/import.php), [historical schema upgrade](../tests/ci/upgrade-import.sh) | Normalized JSON supports six mapped resource families; full historical extraction, documents, journals, attachments and financial equivalence are not established. Reconcile IDs, totals, references and balances at a declared cutoff. |
| Private API, read-only MCP and consented AI | Partial | Core #49 | [OpenAPI generator](../tests/openapi/generate.php), [MCP/AI](../tests/integration/mcp-ai.php) | Versioned complete response contracts and external interoperability, real model qualification and revocation. No AI or MCP write authority is asserted. |
| Versioned extension contracts, domain events and webhooks | Partial | Core #49 | [module integration](../tests/integration/modules.php), [SaaS module policy](../tests/e2e/saas-module-policy.spec.mjs) | Native dispatcher events after committed state changes, durable delivery where necessary, scoped payloads, retry/deduplication and real Nextcloud webhook listener tests. |
| Asset, inventory and personnel financial workflows | Planned | Core financial records / optional specialized extensions #63, #64, #65 | No complete workflows asserted | Prove depreciation, stock cost and personnel/payroll accounting against the one authoritative ledger; define domain owners before extraction. |

## Optional providers, jurisdictions and verticals

| Workflow | Support | Owner / epic | Existing evidence | Missing behavior / acceptance scenario |
| --- | --- | --- | --- | --- |
| Bank connectivity and synchronization | External | Core provider contract #50; optional institution adapters #52–#58 | [provider interface](../lib/Domain/Banking/Providers/BankProviderInterface.php) is not a live transport | Consent, secure credentials, scopes, synchronization, revocation, reconciliation and provider sandbox qualification; no institution is declared connected. |
| Payment checkout and settlement | External | Core #51; optional Stripe/PayPal adapters #59–#60 | [manual payment workflow](../tests/integration/domains/payments.php), not provider checkout | Verify signed webhooks, idempotent settlement, refunds, failed authorization and provider sandbox qualification. |
| Brazilian national service invoice | External | Optional `librefinance_nfse` #61 | [module tests](../tests/integration/modules.php); [optional app](https://github.com/LibreCodeCoop/librefinance-nfse) | Draft preparation only. Actual DPS, issuance, official cancellation, signed XML, certificate handling and jurisdiction qualification are not implemented as a supported operation. |
| Brazilian statutory accounting and other jurisdiction packs | External | Optional localization #61 | No certified statutory reporting asserted | Versioned jurisdiction templates, official schema validation and authorized qualification. Core company country is not fiscal certification. |
| Cooperative membership, production, capital and allocation | Planned | Core ledger / specialized domain #62 | No supported cooperative-specific economic lifecycle asserted | Distinguish Nextcloud identity from economic membership; demonstrate audited capital, production and surplus allocation without duplicate books. |
| Regulated credit-cooperative finance | Planned | Specialized domain #71 | No regulated banking or prudential workflow asserted | Establish permitted product boundary and regulatory requirements before implementing deposits, loans or regulated reporting. |
| Optional regulatory and third-sector reporting | External | Optional jurisdiction provider #76, extension contract #49 | No qualified regulatory exporter asserted | Generate deterministic, authorized snapshots against versioned schemas; qualify each jurisdiction separately and distinguish generation from official submission. |

## Reconciliation at an epic handoff

1. Check out the exact candidate revision in a clean worktree. Run
   `python3 tests/ci/coverage-inventory.py` for the manifest, runtime boundaries,
   route/OpenAPI counts, migrations and matrix evidence paths. Run
   `php tests/openapi/generate.php --check` for the actual API contract.
2. Review the owning code, UI navigation and tests for each affected row. Update
   the existing row rather than adding a parallel capability list. New
   functionality must have a meaningful positive and negative scenario and,
   when applicable, rollback, concurrency and database evidence.
3. Record the exact CI revision, required job results and independent review in
   the relevant issue/PR. A passing aggregate, a missing/skipped job, an
   unexecuted test, or a mismatch between tested revisions is not qualification.
   Release qualification follows #70; provider or fiscal claims need additional
   evidence. This document describes supported behavior rather than run logs.
4. For a source inventory, declare the source, company/workspace, cutoff,
   filters, pagination completion and available permissions. Use three distinct
   outcomes: **verified empty** (complete authorized enumeration returned zero),
   **observed records** (complete authorized enumeration with a count), and
   **unknown/unavailable** (missing access, unsupported resource, incomplete
   pagination or extraction failure). Never convert the last outcome to zero.
   The current inventory endpoint compares supplied counts and mapped targets;
   it does not attest source access or prove financial equivalence. Exclude
   unknown counts from verified coverage and retain the unresolved gap.
5. Review [safe upgrade boundaries](MODULES.md), including all former
   separate apps, and validate historical record IDs, balances and references
   before a legacy installation is considered migrated.

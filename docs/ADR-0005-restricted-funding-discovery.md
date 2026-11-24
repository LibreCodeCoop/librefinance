<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Restricted funding and agreement accountability

**Status:** proposed at the discovery gate for #74; requirements and acceptance
examples, not a deployed domain or a frozen API. The evidence base is the
core implementation at revision `25096a5e570d5a65d763ad3d0e1b4dc3799e4b1d`,
rechecked at `c8c7de5629930c3acb461fa1f82ba941adafdf13` after the named
financial capability and independent company-ledger changes.
The decisions below must be revalidated against the completed core currency,
accounting, budget, treasury and extension contracts before implementation.

## Domain vocabulary and ownership

| Concept | Meaning | Proposed authoritative owner |
| --- | --- | --- |
| Funding source | An identified contribution or entitlement with restrictions; not a bank, payment method, or reporting classification | Agreement domain; references core financial party and receipt |
| Agreement | Versioned instrument identifying parties, validity, conditions, scope and authorized contributions | Optional funding domain |
| Work plan | Agreed purposes, milestones, limited measurable outputs and financial lines | Funding domain; reference external project/task data rather than duplicating project management |
| Budget line | Approved quantitative ceiling for a dimension, source, period, purpose and currency | Core budget service under #48; agreement references its version |
| Commitment | Amount reserved for an authorized planned obligation; not a journal or bank payment | Core budget/obligation services |
| Settlement | Authorized actual payment/receipt and allocation against an obligation | Core treasury/ledger services, including idempotency and partial allocation |
| Reporting period | Agreement-defined evidence/review interval with a separately controlled lock | Funding domain; cannot unlock a closed accounting period |
| Disallowance | Reasoned rejection of some or all of an item or allocation | Funding review; refund, reversal or write-off requires a distinct authorized core transaction |
| Contribution | Expected/received money or eligible own/co-financing contribution with evidence; noncash physical indicators are not automatically recognized as cash | Funding intent, core receipt or appropriate authorized ledger recognition |

A financial workspace is the isolation boundary. Agreement identities and
every referenced record must belong to that workspace. A company/legal party
within a workspace is not a separate ledger; use an independent workspace for
independent accounting. A funder may share a bank account with another
source: restricted availability is an allocation, never inferred from the
bank balance. Configurable instrument names, funding classes, expense
eligibility and review steps must not encode one jurisdiction's law.

## Existing core and gaps

| Contract at the evidence revision | Reuse and qualification |
| --- | --- |
| `AccessService::requireRole` and `requireFinancialCapability`, `SaasPolicyRuntimeGate`, SaaS environment/workspace membership | Authorize each financial read/write at the server; define funding-specific capabilities and item scope separately. Environment administration alone is not a grant. |
| `LedgerService::createJournal`, `reverse`, journal/account queries | Ledger remains the sole authoritative posted record. Reference identifiers, do not write core tables or change a posted fact to correct a report. |
| `PaymentWorkflowService::forInvoice`, `forObligation`, `linkExistingObligation`; existing obligations and movement reconciliation | Partial payments and reconciliation supply actual evidence. Atomic allocation/reservation release still depends on the planned core budget/treasury contract; cannot wrap an existing payment call and reserve later. |
| `ActivityFeedService::list`, existing account/obligation/document views and filters | Reuse navigation and authorized drill-down; the feed's planned/pending rows do not count as posted cash. It is not a budget availability engine. |
| `ModuleService::requireEnabled` and `requireEnabledForCapability` | Optional capability visibility; cannot substitute for operation-level authority or establish a stable external extension ABI. |
| Existing integer money and workspace currency | Reuse exact monetary values. Multi-currency rate policy, reporting consolidation, period locking, budget dimensions, immutable report snapshots and extension contracts still need #44, #45, #47, #48, #49. |
| Nextcloud Files, authentication and optional signature integration | Candidate evidence references and permission revalidation; exact storage and snapshot/signature lifecycle must be qualified. A combined PDF is a derivative, not the original signature. |

The recheck confirms the named capabilities, module descriptor minimum,
policy gate and transaction-bound payment service exist. The core still
restricts new ledgers to BRL and does not supply a budget reservation or
period-closing runtime contract. External extension capabilities and
versioned fiscal/report snapshots remain future contracts; do not treat
reserved capability names as callable APIs.

Core work must provide exact amounts, versioned budgets, atomic reservation,
release and settlement allocation, periods and report snapshots. No parallel
grant ledger, budget database, bank connector or duplicate accounting
transaction. The optional domain may own agreement/work-plan metadata,
eligibility configuration, evidence allocation references, workflow state,
review opinions and versions, and the public projection. No repository or
runtime app is created by this decision.

## Budget and accounting invariants

For each approved source/line/currency at a declared version and basis:

- Available = approved budget - outstanding eligible commitments -
  eligible executed amounts, with reversals/releases recorded exactly once.
- Outstanding commitment = reserved amount - allocated execution - authorized
  cancellation. Partial execution consumes that portion of the reservation;
  it does not consume capacity again. Cash and accrual reports have separately
  declared cutoffs; neither replaces the authoritative control balance.
- Availability, actual receipts, actual bank balance, forecast and review
  disallowance are different values. Disallowance does not undo payment.
- Apply the hard ceiling or warning selected by approved policy atomically
  to all write paths (UI, API, imports, background jobs and extension calls).
  Lock/version affected allocation rows in deterministic order; retry safely
  on conflict using the same idempotency identity. Do not use cached report
  totals as the transaction gate.
- A budget amendment records its reason, approvals, effective date and prior
  version. A decrease below already reserved/executed capacity needs rejection
  or an explicitly authorized correction workflow. No historical snapshot
  changes.
- Retain original currency and exact amount, effective rate and rounding
  policy for each converted allocation. No implicit addition of different
  currencies. An accounting-period lock and a reporting-period lock are
  independent gates; an agreement-validity exception cannot override either.

## Synthetic acceptance scenarios

Use the hand-calculated fixtures under `tests/acceptance/restricted-funding`.
All parties, amounts and evidence are synthetic. The fixture assertions
check expected arithmetic and internal fixture consistency, not a runtime implementation.
The machine-readable `workflow-cases.json` adds 15 explicit actor/workspace/
action/condition/expected-outcome records, two configuration examples,
immutable review versions, content digests and a public field allowlist.
Future runtime tests must instantiate these cases and observe actual
permissions, persistence, revocation and publication behavior.
Run `python3 tests/acceptance/restricted-funding/verify-fixtures.py` to verify
fixture consistency; the core contract CI executes this check.

1. Source A budget USD 1,000.00; B USD 400.00; own contribution EUR 300.00.
   Reserve USD 600.00 (A 450/B 150); settle USD 240.00 (A 180/B 60).
   A available 550, outstanding 270, executed 180; B available 250,
   outstanding 90, executed 60. EUR 300 remains independent. Release A 50
   and B 10, leaving A available 600 and B 260. On a separately approved
   USD reporting basis only, a fixed synthetic rate of 1.20 USD/EUR gives
   USD 360.00 for the EUR budget and USD 1,760.00 total original budgets.
   Record the exact rate and currency, never replace the EUR record.
2. A fresh A budget USD 1,000 with 800 reserved; two concurrent requests
   for USD 150 each. Under hard ceiling exactly one commits, outstanding
   becomes 950 and available 50. The other fails without journal, reserve,
   audit success or duplicate side effects; replay of the successful
   request returns the same reservation.
3. After the first partial-settlement scenario and release, A's amendment
   increases its approved budget to 1,100 and B stays 400. A available
   becomes 700; prior snapshot remains 600. Decrease A to 300 is denied
   (220 outstanding + 180 executed = 400). A closed accounting period
   rejects a posted adjustment even if report period open; a locked report
   rejects changing its evidence even if accounting period open.
4. Approve only USD 150 of A's executed USD 180; USD 30 disallowed.
   Core execution remains 180, cash movement remains 180, and review
   shows accepted 150/disallowed 30. Correction or appeal creates a new
   review version with reason, actor and changed-item diff; refunds and
   reversals are separately authorized. A late retry must not mutate the
   previous report or post a second transaction.
5. Evidence for a USD 240 payment is allocated A 180/B 60; adding another
   allocation of USD 1 fails without increasing totals. The accepted
   version retains the exact original file ID, version and content digest;
   replacing current file content must not change the approved snapshot.
   Copy/combined exports never assert new cryptographic signatures.
6. Use two neutral configurations: one annual grant with monthly reports
   and one sponsored project with milestone reports; use USD/EUR and
   different language settings, labels and review stages. Core services
   remain the same. Cross-workspace IDs, a reviewer removed mid-session,
   an expired handover, and a guessed public derivative URL are denied
   without disclosing private metadata.

## Permission and evidence boundaries

| Actor | Allowed | Denied |
| --- | --- | --- |
| Financial operator scoped to workspace and agreement | Record eligible plans/commitments through core; attach authorized evidence | Approve own independent review, rewrite postings, bypass ceiling or period gate |
| Scoped active reviewer | Read assigned evidence and record reasoned line/period opinions; authorized handover | Post/reverse money by virtue of review role, read another agreement or workspace, edit approved history |
| Authorized report approver | Approve immutable snapshot and allowed public field set | Change original file or bypass source access and reporting locks |
| Workspace financial administrator | Configure authorized funding policy/grants and inspect audit | Act across workspace; bypass required independent review |
| Environment/instance admin without finance grant | Manage relevant environment configuration only | Read balances, agreement details or private evidence |
| Public anonymous recipient | Read explicitly published, non-revoked projection and permitted redacted derivatives only | Enumerate private records, request originals, draft/identity details, stale/revoked projection or exports |

Use deny-by-default, server-side current actor/role/workspace checks on each
operation and file download. Freeze the reviewed item/evidence manifest with
version and digest, provenance and access-controlled original references;
do not expose storage paths or private identifiers publicly. Validate public
field allowlists and derivatives separately from internal report generation;
revocation must invalidate access and caches. The report projection is
not a file-system share or public link to originals.

## Native UI proposal (not implemented)

Use the existing App navigation, a single optional **Funding and agreements**
entry, `NcAppContent`, `NcAppNavigationItem`, `NcButton`, `NcDialog`,
`NcEmptyContent`, `NcSelect`, `NcTextField` and supported native Files
selection. Confirm exact component APIs against the pinned Vue version.
Reuse core financial record drill-down rather than adding parallel
Movements, Accounts or Documents pages.

```text
Agreement list -> Agreement context (workspace / version / status)
                  [Plan] [Execution] [Review] [Reports]
 Plan: funding sources | line allocations | milestones | revision history
       [Save draft] [Submit for review]
 Execution: approved | outstanding | executed | available (per currency)
       line -> authorized core obligation/payment/evidence drill-down
       [Reserve] [Release] [Record payment via core]
 Review: assigned periods | line/evidence | accepted/disallowed/reason
       [Request correction] [Handover] [Approve version]
 Reports: basis, period, version, reconciliation, evidence manifest
       [Preview] [Export] [Publish redacted projection] [Revoke]
```

Desktop: context header stays visible; line table with descriptive row
actions and explicit currency and status. Mobile: same rows become stacked
cards, no horizontal scroll prerequisite; focus order follows context,
filters, list, actions. Tab to all actions, use Enter/Space to activate,
Escape to close dialogs, restore focus to the triggering row. Loading
preserves context with a native loading state; empty shows an authorized
action, failure offers retry without duplicate reservations, denied
shows no financial counts or record labels. A conflict requires refresh and
an explicit resubmission of the updated version. Use text status and
accessible names (not color alone), Nextcloud theme tokens, locale-aware
currency/date presentation and configurable terms, including light/dark
and keyboard/mobile acceptance. No screenshots of this unimplemented UI
are represented as runtime evidence.

## Regulatory adapter boundary and planning gate

Jurisdiction-specific classification, official schemas, package
validation, signing profiles and transmission belong to an optional
adapter under #76. It consumes only authorized, versioned financial and
evidence snapshots. It cannot rewrite original classifications, send
private originals through public projections, or mark an official
submission accepted without an independently verified response. No live
transmission, fiscal claim or regulatory qualification is in scope.

The parent #74 assumptions are therefore conditional: use the existing
workspace, access, ledger and payment contracts; defer implementation
decomposition until the core currency, period, budget/reservation and
extension contracts can satisfy the fixtures atomically. The agreement
metadata, synthetic fixtures and interaction design can proceed now.
Separate report review and public disclosure into independently tested
trust boundaries. Reconcile the parent planning gate and approved issue
descriptions after the required review; this ADR makes no unverified
endpoint promise. Full integration, permission, concurrency, database,
file-version and jurisdiction-neutral configuration tests are future
implementation gates, not evidence supplied by this discovery.

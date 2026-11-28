<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Native financial journeys: audit and proposed wireframes

### Currency precision in native amount fields

The authorized workspace read contract determines the functional currency and
decimal scale. Native journal, movement, transfer, item, invoice, obligation and
settlement forms parse exact minor units with that scale; an account or document
in a different currency cannot silently use the workspace scale for a posting.
When records have their own currency metadata, display uses it; same-currency
records without separate metadata inherit the authorized workspace context.
Historical BRL records without either precision field remain base 100. Unknown,
incomplete or contradictory non-BRL metadata displays an unavailable amount and
prevents monetary submission rather than guessing a scale. Decimal input accepts
a comma or point without grouping; editing uses exact stored minor units.
Workspace changes re-resolve the current context. This does not enable a new
currency, change the server's write restrictions, change tax rates or quantities,
or change the separate optional NFS-e contract.


## Scope and observed baseline

The 2026-11-24 synthetic browser review exercised the current navigation
structure before the subsequent SaaS administration localization change.
It rendered 11 application views in desktop/light (1440 × 900) and
mobile/dark (390 × 844), plus instance administration and the CSV picker
dialog. The observed pages did not have document-level horizontal overflow
or captured page exceptions. This is a bounded rendering review, not a full
accessibility or financial qualification. The images below are actual
Nextcloud runtime captures using invented company and accounting data;
the account avatar/indicator is masked and image metadata removed. They
show the observed baseline, not an implemented future design.

Existing source locations: `App.vue`, `navigation.js`, `MovementsView.vue`,
`BankingView.vue`, `DocumentsView.vue`, `ReportsView.vue`, `AccountsView.vue`,
`ImportView.vue`, `SpacesView.vue`, `src/admin/AdminPage.vue`. Preserve the
current canonical routes and scoped data services unless a separately
justified contract change is necessary.

| Journey | Observation and prioritized gap | Priority |
| --- | --- | --- |
| Movements, incoming/outgoing | Registration and statement import are already grouped. Filters labeled only "Todas" are ambiguous; the pale status badge is difficult to read on light background. Add explicit filter names, amounts and state announcements, preserve context and avoid duplicate registration. | P1 |
| Account and bank evidence | A mobile account-statement empty state distinguishes evidence from ledger facts. Keep selected account/currency visible, show pending/reconciled evidence and restore filters when returning. Introduce comparison/preview only through an established server contract. | P1 |
| Invoice and settlement | A single invoice action and explanatory empty state exist. Show the commercial nature, amounts, state and link to the one canonical payment workflow. Distinguish partial, paid, reversed, failed and unavailable states; no fiscal issuance is implied. | P1 |
| Migration | Existing normalized preview is available, but an offline neutral bundle is not a destination import. Keep inventory/mapping, diagnostics, permission, preview and actual import distinct. | P1 |
| Reports | Mobile model list is usable but there is a long transition between model, period, saved configuration and result. Keep account/period context, identify the authoritative posted basis, distinguish empty result, load failure and unavailable report. | P2 |
| Workspace and delegation | Separate environment, book and role. Membership, policy, invitations and book creation can make a dense mobile page; disclose sections progressively. Some icon-only controls require explicit accessible names. | P1 |
| Instance administration | Nextcloud Settings is the correct surface. Effective value, source and revision already appear. Separate instance restrictions from delegated group control and financial roles; shorten repeated explanations and keep audit/recovery separate. | P1 |
| Dashboard, custom fields, modules and search | Existing shortcuts, custom fields and module list should remain scoped. New localized labels and permission-aware search should not add duplicate destinations. Hide unavailable optional actions without concealing a useful explanation to authorized administrators. | P2 |

P1 = correctness, safe operation, authorization clarity or accessibility
before expansion. P2 = next navigation/presentation refinements.
Do not prioritize visual changes over unimplemented domain contracts.

### Actual synthetic captures

| Desktop/light | Mobile/dark |
| --- | --- |
| [Movements](evidence/ux/desktop-light-finance.png) | [Bank evidence empty state](evidence/ux/mobile-dark-banking.png) |
| [CSV dialog](evidence/ux/desktop-light-csv-dialog.png) | [Report models](evidence/ux/mobile-dark-reports.png) |
| [Invoice empty state](evidence/ux/desktop-light-documents.png) | [Instance administration (desktop)](evidence/ux/desktop-light-admin.png) |

## Low-fidelity interaction wireframes

These are component/interaction proposals, not a screenshot of implemented
behavior. Reuse official Nextcloud Vue components already in the application:
`NcButton`, `NcActions`/`NcActionButton`, `NcTextField`, `NcPopover`,
`NcDialog`, `NcFilePicker`, `NcEmptyContent`, `NcNoteCard`,
`NcLoadingIcon`, `NcListItem` and `NcCheckboxRadioSwitch`. Use standard
Nextcloud theme variables, semantic table headers and descriptive native
inputs; only introduce another component after confirming its API in
the installed official library.

### A. Primary worklist and account drill-down

```text
[Existing app navigation]  [Environment / workspace / role]
                          Movements                      [Register]
                          [Import statement ▼] [Account: all ▼]
                          [Search] [Direction ▼] [State ▼] [Period]
                          [Pending to review: n] [Refresh]
                          Date | Description | Account | State | Amount | Actions
                          ... canonical linked record ...
                          [Previous] page/count [Next]

                          Selecting account:
                          Account details / Statement
                          [Account / currency] [Posted balance] [Evidence status]
                          [Back to movements with filters] [Import statement]
```

Desktop shows a semantic list/table with separate named filters; mobile
stacks the same controls and renders one record per readable row/card,
not two independently loaded datasets. Preserve page/filter state and
focus on return; invalidate the state and reload on workspace switch.
Register opens the existing authorized domain action; row actions are
the canonical actions for that record and role, not a second settlement
endpoint. Do not show evidence amounts as posted cash.

### B. Statement import and historical migration

```text
NcDialog: Import account statement
  Account [selected / required]   Format [CSV / OFX, allowed only]
  [NcFilePicker or supported native local-file control]
  [Download sample CSV]           [Cancel]
  NcNoteCard: validation / file bounds / evidence only
  [Validate and preview] -> bounded rows, duplicate/invalid diagnostics
  [Confirm evidence import]       [Cancel]

Existing Migration route:
  Scope / source inventory -> company/resource mapping -> preview
    -> progress / conflicts -> validation report
  NcNoteCard: unsupported / excluded / denied / incomplete
  [Back] [Explicit permitted operation, only when backend supports it]
```

The statement preview is a server-side dry-run contract on
`POST /imports/preview`, not a client-side promise. Both the Financial
movements and account statement dialogs use it. The user reviews exact
pending amounts, replayed IDs and conflicts, or a strict parser diagnostic.
The confirmation button is available only for a ready fingerprint, and
resubmits the same file and account with that fingerprint. Changes to the
selection, cancellation and obsolete asynchronous responses discard
the preview. A stale state or failure requires a fresh preview. The
existing evidence import remains distinct from ledger posting. Do not enable neutral historical application before
destination mapping, idempotency, reconciliation and permissions exist.
Rejected or inaccessible resources are not "0 records". Use a modal only
for bounded actions; the multi-step migration remains in its existing route.

### C. Commercial documents, payments and reporting

```text
Faturas / Invoices     [New invoice]
[Search] [Sale/Purchase] [State] [Period]
No records -> NcEmptyContent with one authorized action
Rows -> [Open document] -> linked obligation / settlement status
                         [Open canonical payment action, if allowed]
                         [History / original amount / paid / outstanding]

Reports   [Model] [Saved configuration]
[Period] [Account/dimensions when implemented] [Run report]
[NcNoteCard: basis, scope, currency, as-of date]
[Result / empty / failure / unavailable] [Save configuration]
```

One payment entry point must serve linked invoice/obligation, and the
server must reject duplicate or stale operation identities. Do not expose
a second "pay" action in both worklists that bypasses the shared workflow.
Statement review offers explainable server-side reconciliation suggestions
for the selected pending row: exact signed amount, account and a chosen
date window. The reviewer explicitly selects a candidate and then confirms
using the existing matching action. Manual selection stays available;
suggestions do not claim a journal or change balances, and uncomparable
source references are not treated as proof.

Reports distinguish posted results from projections. Mixed currencies
and unavailable dimensions cannot be silently aggregated.

### D. Workspace administration and instance policy

```text
Existing Gestões / Workspaces route
  [Environment selector] [Current workspace and role]
  [My workspaces] [Create (if authorized)]
  [Members & invitations] [Review current effective policy]
  [Preferences] [Policy audit history]

Nextcloud Settings / LibreFinance
  [Managed provisioning toggle]
  [Groups: enable / suspend / recover, scoped]
  [Instance effective policy / origin / revision]
  [Legacy ambiguity review] [Audit history]
```

Separate instance policy from environment administration, book financial
permissions and individual presentation preferences. Use section headings,
`NcListItem` and named disclosure/actions; maintain the existing route
and data ownership. A group admin does not become a financial owner.
Revocation, suspension and stale policy must cause a server denial on the
next request; reload canonical state rather than retaining stale controls.

## State and accessibility acceptance matrix for implementation

| State | Required interaction |
| --- | --- |
| Loading | `NcLoadingIcon` with announced status, controls disabled only while necessary; no stale totals attributed to a newly selected book. |
| Empty | `NcEmptyContent` with a contextual next action only when the current role permits it; verified empty is not unknown. |
| Error | `NcNoteCard` with a localized, actionable error and safe retry; no raw response or private fields. Preserve non-sensitive input when safe. |
| Permission denied / suspended | Explain the unavailable operation without leaking foreign identifiers/data; clear stale context, re-fetch permissions, no alternative bypass. |
| Optional module unavailable | Do not expose dead navigation/actions; authorized admin can inspect requirement/activation status. No hidden dependency on a provider or extension. |
| Keyboard/focus | Real semantic labels for controls, distinct accessible filter names, logical tab sequence, Enter/Space on native actions, dialog escape and focus restoration to opener, focus on relevant validation error; no color-only meaning. |
| Light/dark and mobile | Use theme tokens and test readable contrast for statuses/amounts, 390 px viewport without document overflow, no offscreen primary action, accessible responsive table/card semantics. |

Implementation must extend the existing Playwright journeys in
`tests/e2e/ux-refactor.spec.mjs`, `saas-module-policy.spec.mjs`,
`saas-invitations.spec.mjs` and the actual domain integration tests.
Add negative cases for permission loss, failed/duplicate payment,
incomplete preview, unavailable module and cross-book switch; do not
infer backend acceptance from a rendered button. Localize every new
visible source string and preserve stored values. Capture redacted,
synthetic desktop/mobile states and verify exact integrated CI revision.

This closes the audit/prototype deliverable only when reviewed and
integrated. It does not close the broader UX epic or claim completed
implementation of any proposed financial capability.

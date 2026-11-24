<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# ADR 0004: Durable SaaS environments and financial authority

- Status: **adopted architectural contract, progressively implemented in the LibreFinance core**; final integrated acceptance and any production rollout remain separate decisions under #11.
- Date: 2026-11-23
- Supersedes: the former model that equated managed Nextcloud groups with a financial ledger grant. Existing legacy governance APIs retain explicitly limited backward-compatible semantics; see the current `docs/GOVERNANCE-API.md`.
- Target: Nextcloud 36 dev, PHP >=8.3, one native LibreFinance app
- Implementation gates: #13 persistence, #14 restrictive policies, #15 delegated environment administration, #16 per-workspace financial authorization. Final integrated acceptance is tracked by #11.

## Decisions and invariants

1. An **instance** is a Nextcloud installation. A Nextcloud instance administrator may enable a group for LibreFinance and inspect SaaS configuration, but is not by that fact a financial-space owner, operator or viewer. Explicit audited break-glass recovery is a separate operation and never silently creates permanent membership.
2. An **environment** is a stable, opaque, app-owned UUID, not a Nextcloud group name, mutable GID or `lf_spaces.id`. One enabled group has at most one primary active environment binding; its identity and historic accounting records survive group suspension, deletion and authorized rebinding. A group administrator administers the environment, not every ledger inside it.
3. A **financial workspace** is a single existing `lf_spaces` ledger. One workspace belongs to exactly one environment after classification. An environment may own many workspaces. Workspace IDs and already-posted amounts never change as part of provisioning.
4. A **legal company** is `lf_companies` identity and fiscal metadata, not a ledger. Newly provisioned independently-accounted companies receive independent workspaces and an explicit legal-company binding. A personal/household/non-business workspace may have no legal company. Legacy multiple companies within one space remain shared until separately audited and intentionally migrated; **never infer a split from names, CNPJ or group membership**.
5. **Group membership is eligibility/discovery only**, not a financial grant. Financial access requires current identity, active group/environment admission (or explicit scoped external invitation), exact workspace grant, named operation capability and effective restrictive policy. Missing or conflicting context denies. Effective policy never creates access grants.
6. One user may participate in several environments, but each request resolves exactly one environment and one workspace. Group roles from distinct environments must not be unioned, and response lists must be filtered before pagination/counting. Multiple group membership does not select a default environment for financial mutations.
7. No administrator may assign a financial role higher than their current **grant authority**. A group subadministrator or delegated SaaS admin is not automatically a ledger owner. Changes to role, policy, invite and recovery are audited with actor, scope and revision.
8. Posted ledger entries are immutable; reversals remain explicit. New permissions never rewrite historical posting actor, amount, account or timestamp. Revocation is checked against the current authoritative state at the start of each protected operation, including background jobs before accessing financial data.
9. No runtime dependency on LibreSign or additional services. Use documented Nextcloud OCP APIs and portable migrations for SQLite, MariaDB and PostgreSQL.

## Identity, lifecycle and concurrency contract

```text
Instance 1--* Environment 1--* Workspace (lf_spaces)
Nextcloud Group 0..1 --1 Environment (primary active binding)
Workspace 0..1 --1 legal company identity (new independent workspace)
User *--* Environment (admission or explicit invitation)
User *--* Workspace (financial grants, separate from administration)
```

- Group lifecycle: `disabled -> enabled_pending_admin -> ready -> suspended -> ready`; `deleted` is suspended/unbound, **not hard-deleted**. A previously enabled group lacking a qualified admin returns to pending without self-promotion.
- Environment lifecycle: `provisioning -> active -> suspended -> active`. Ambiguous imported scopes are `pending_review` and not used for newly assumed ownership. Provisioning is idempotent under simultaneous requests, protected by unique group binding, transaction and explicit conflict reconciliation.
- Workspace lifecycle: existing `active/archived` semantics remain. No automatic hard delete of financial data, no cross-environment reassignment through an untrusted `spaceId`.
- Qualified environment provisioning actor: current Nextcloud subadmin of the enabled group or explicitly delegated LibreFinance environment admin. The ordinary member and instance admin lacking an explicit operating mandate are not provisioning substitutes.
- Disabled, deleted or unknown groups deny fresh financial operations and remain recoverable after authenticated administrative review.
- Require optimistic revision or equivalent concurrency guard for security-relevant policy/grant changes, with deterministic conflict on stale updates. No implicit last-writer-wins elevation.

## Authorization matrix (default behavior, subject to narrower policy)

| Actor | Manage instance group enablement | Manage own environment | Provision workspace | View a workspace ledger | Post ledger entries | Invite external actor |
| --- | --- | --- | --- | --- | --- | --- |
| Instance admin (no grants) | Allow | Deny by default | Deny | Deny | Deny | Deny |
| Enabled-group Nextcloud subadmin (no workspace grants) | Deny | Allow scoped | Allow if policy permits | Deny | Deny | Scoped admin flow only |
| Explicit delegated environment admin (no financial grants) | Deny | Allow scoped | Allow if policy permits | Deny | Deny | Scoped admin flow only |
| Ordinary group member (no workspace grants) | Deny | Deny | Deny | Deny | Deny | Deny |
| Workspace owner with current environment admission | Deny | Deny by default | Deny by default | Allow | Allow | Allow only if delegated |
| Workspace manager with current environment admission | Deny | Deny by default | Deny by default | Allow | Allowed capability-dependent | Delegated only |
| Workspace operator with current environment admission | Deny | Deny | Deny | Allow | Authorized operation only | Deny |
| Workspace accountant/auditor (financially granted) | Deny | Deny | Deny | Scoped view | Only explicitly granted capability | Deny |
| Workspace viewer | Deny | Deny | Deny | Scoped view | Deny | Deny |
| Revoked, disabled-group, or foreign-workspace actor | Deny | Deny | Deny | Deny | Deny | Deny |
| External invitation | Deny | Deny | Deny | Limited to invitation | Explicit grant only | Deny |

This table states the agreed default authority model. The implementation has `FinancialCapabilities` for named operations, a SaaS-specific gate in `AccessService` and service-owned access checks. The legacy `auditor` role is restricted to read-only operations unless a specific capability explicitly permits an action. These design rules and their negative tests must be revalidated when a domain gains a new API, background job, file export or optional integration; this table by itself is not runtime proof.

## Policy precedence

- The policy registry declares key, type, scopes, default, validator, delegation and resolution operator. Minimum scopes are instance, environment, workspace and user when explicitly permitted per key.
- `deny` / module-disable is absorbing, numeric quota uses the minimum permissible ceiling, allowed enum sets intersect. An empty intersection denies. A child can only tighten parent values, never widen them.
- Clearing a child value restores inheritance, not an allowance. Unsupported scope, invalid value, missing parent or ambiguous environment fail closed.
- Effective metadata includes the value, source scope, source identifier, blocker, editability for the authenticated actor and current revision.
- Policies restrict **what may be done**. Financial workspace grants independently answer **who may access which records**.

## Legacy upgrade and exceptional review

- Add immutable, forward-only migrations; preserve old IDs, rows, binary files, ledger balances, audit, references and plugin connectors.
- Snapshot row IDs, financial amounts, posting counts, account balances and core-bank-import-invoice-obligation-report relations before and after conversion in each supported DB.
- `lf_spaces.governance_gid` is a legacy hint, **not an authoritative environment ID**. Record provenance and review status. Do not grant new financial rights from `lf_group_grants` created automatically by prior `GovernanceService::bind()`.
- A legacy space containing several `lf_companies` remains one ledger, tagged for review; do not attempt a speculative split or automatically duplicate postings.
- Both new install and pre-existing managed/unmanaged installations require explicit compatibility tests. During rollout, legacy APIs may use an adapter; new SaaS behavior must fail closed if environment ownership is unresolved.
- Rollback involves a verified database/files snapshot and previously deployed app revisions; it is not an unsafe automatic schema downgrade.

## Reconciliation against implemented code

The following points were architectural defects at the time of the original ADR. Their current treatment is deliberately distinguished from legacy compatibility:

- `AccessService::accessibleSpaces()` still contains legacy managed-mode listing logic, including instance-administrator convenience access, **but `filterSaasListing()` removes financial workspaces without explicit SaaS grants**. `requireRole()` also applies SaaS admission and workspace-specific authorization, rather than treating Nextcloud administration as a financial role. Direct API forgery and cross-environment tests live in `tests/integration/saas-access.php`.
- `GovernanceService::bind()` still writes a legacy `viewer` group grant **for non-SaaS spaces only**. It refuses to bind a workspace already registered to a SaaS environment. New environments never use group membership as a financial ACL.
- The historical `SpaceService::create()` endpoint retains managed-mode checks for backward compatibility. Delegated SaaS administration instead provisions workspaces through `SaasWorkspaceController` and `SaasWorkspaceService` with environment, idempotency and owner checks.
- Multiple legal companies within **one legacy** `lf_spaces` remain in one ledger, awaiting explicit administrative classification. Newly created independently-accounted SaaS companies have dedicated workspaces. Existing posted journals are not automatically split or reassigned.

Policy definitions, group lifecycle, revocation and financial operations are implemented incrementally. The evidence for each slice is its verified source, negative integration tests and current CI at the combined SHA, not the ADR's status. No statement here authorizes a production deployment.

## Reassessment and remaining release gates

Review the source and test contracts after every change to #14–#16, especially optional NFS-e, banking, import, reports, MCP, AI, background work and file ownership. The CI synthetic lab covers installation, authorization and selected upgrade matrices; it is **not** proof of manual usability, complete legacy classification, performance at scale or independent human review. Those are separate acceptance decisions for the SaaS epic #11. Refer to `docs/SAAS-CONTRACTS.md`, `docs/GOVERNANCE-API.md` and `docs/TESTING.md` for the present runtime and test boundaries.

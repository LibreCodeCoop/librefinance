<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# ADR 0004: Durable SaaS environments and financial authority

- Status: proposed contract for issue #12; **not implemented**
- Date: 2026-11-23
- Supersedes: only the managed-SaaS assumptions in GOV-ERNANCE-API.md; current APIs remain as documented until implementation
- Target: Nextcloud 36 dev, PHP >=8.3, one native LibreFinance app
- Implementation gates: #13 persistence, #14 policies, #15 administration, #16 financial permissions

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

These are design requirements, **not assertions about current `AccessService` behavior**. The existing rank-based `auditor` role must not gain write access by compatibility mapping. The future capability registry must enumerate per-domain actions (accounts, journal, reconcile, import, invoices, obligations, contacts, custom fields, reports, jobs and files) rather than reuse rank comparison for all writes.

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

## Known implementation contradictions to fix later

- `AccessService::accessibleSpaces()` assigns every managed space to the instance admin as `owner`.
- `AccessService::requireRole()` returns `owner` to instance admins in managed mode.
- `GovernanceService::bind()` creates a `viewer` group grant on binding.
- `SpaceService::create()` depends on admin-only `requireCreationAllowed()`.
- `lf_companies` within a shared `lf_spaces` do not constitute independent accounting.

The existing behavior is **not** changed by this ADR. Each contradiction needs a failing negative test and a migration-compatible implementation.

## Reassessment gate

Before #13–#16 are integrated, verify the actual Nextcloud interfaces, schema constraints, all integrated domain callers, optional NFS-e drafts, background jobs, per-space files, and the API fixtures in `docs/SAAS-CONTRACTS.md`. After contracts are implemented, re-audit the downstream issues against the observed runtime rather than treating this document as an excuse to skip integration evidence.

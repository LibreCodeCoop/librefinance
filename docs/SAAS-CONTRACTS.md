<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# SaaS contract v1 — proposed, not deployed

This is the source-of-truth **design contract** for #12, pending runtime implementation in #13–#16. Current legacy endpoints remain documented in `docs/GOVERNANCE-API.md`. Paths below are **proposed** and must not be presented as existing routes.

## Proposed authenticated API

All requests must use Nextcloud's authenticated app API with CSRF protections and backend policy/ACL checks. All identifiers are validated and authorization filtered server-side; `environmentId` selection is never a grant.

| Method | Proposed route | Audience and result |
| --- | --- | --- |
| GET | `/api/saas/v1/environments` | Authenticated: only eligible environments and state, no financial records |
| POST | `/api/saas/v1/environments/{environmentId}/provision` | Qualified current group admin only; idempotent |
| GET | `/api/saas/v1/environments/{environmentId}` | Environment participant; no ledger disclosure |
| GET | `/api/saas/v1/environments/{environmentId}/workspaces` | Authorized: workspace list filtered before count/page |
| POST | `/api/saas/v1/environments/{environmentId}/workspaces` | Qualified environment admin and effective creation policy |
| GET | `/api/saas/v1/environments/{environmentId}/policies/effective` | Scoped actor; effective values only |
| PUT | `/api/saas/v1/environments/{environmentId}/policies/{key}` | Delegated policy admin with version precondition |
| GET | `/api/saas/v1/workspaces/{spaceId}/grants` | Authorized role manager, scoped to exact workspace |
| PUT | `/api/saas/v1/workspaces/{spaceId}/grants/{uid}` | Grant authority check and version precondition |
| DELETE | `/api/saas/v1/workspaces/{spaceId}/grants/{uid}` | Revocation with current authorization |
| GET | `/api/saas/v1/admin/groups` | Instance admin, pagination, enablement and pending state |
| PUT | `/api/saas/v1/admin/groups/{gid}/enablement` | Instance admin only, auditable |
| POST | `/api/saas/v1/admin/environments/{environmentId}/recover` | Exceptional recovery requiring explicit reason and audit |

Exact method names and request validation may change only with documented versioned contract review. Do not implement all of these in #12.

## Example: environment discovery (eligible but not financially authorized)

Request: `GET /api/saas/v1/environments`

```json
{
  "contractVersion": 1,
  "environments": [
    {"id": "ad0d4e8b-78f9-49e5-bf58-d34b0cbb4d33", "name": "Example Group A", "state": "active", "canManage": false},
    {"id": "c40a79aa-4b75-446e-97eb-92052e66ceef", "name": "Example Group B", "state": "enabled_pending_admin", "canManage": false}
  ],
  "nextCursor": null
}
```

This response intentionally contains no account balances, company identifiers, member lists or private financial metadata.

## Example: effective policy

```json
{
  "contractVersion": 1,
  "environmentId": "ad0d4e8b-78f9-49e5-bf58-d34b0cbb4d33",
  "key": "externalSharing.enabled",
  "effectiveValue": false,
  "sourceScope": "instance",
  "blockedBy": "instance",
  "allowedValues": [false],
  "editableByCurrentActor": false,
  "revision": 7
}
```

If an instance forbids external sharing, an environment cannot enable it regardless of role.

## Example: stale grant mutation

Request: `PUT /api/saas/v1/workspaces/42/grants/example-user` with `If-Match: "7"`.

```json
{
  "contractVersion": 1,
  "error": "revision_conflict",
  "status": 409,
  "message": "The authorization revision changed; reload the current permissions."
}
```

Do not return the current grant when the requester lacks access.

## Error and status semantics

- 401 `authentication_required`: missing Nextcloud session.
- 403 `permission_denied`: authenticated but forbidden operation, when even the resource type is permitted to be disclosed.
- 404 `resource_not_found`: unknown or foreign environment/workspace; identical response prevents enumeration.
- 409 `revision_conflict` or `provision_conflict`: stale write or conflicting identity, with no additional grant.
- 422 `invalid_policy_value`, `unsupported_scope` or `invalid_state_transition`: validated request but invalid input.
- 423 `environment_suspended`: only when the requester is already authorized to know the environment exists.

## Negative contract cases to implement

| Case | Actor | Expectation |
| --- | --- | --- |
| N01 | Nextcloud instance admin without finance grant | Cannot list/read/post workspace data |
| N02 | Member of enabled group without finance grant | Discovers environment only; no ledger view |
| N03 | Subadmin without finance grant | Can administer environment, cannot read ledger |
| N04 | Member of A requests resource from B | Denied without disclosure |
| N05 | User in A and B selects A, requests B workspace | Denied |
| N06 | Operator A1 requests write in A2 where viewer | Denied |
| N07 | User removed from group after initial read | Next API access denied |
| N08 | Delegation revoked before next request | Admin API denied |
| N09 | Parent denies feature, child attempts enable | Mutation rejected, effective value denied |
| N10 | Unrecognized policy scope or malformed key | Rejected with no stored mutation |
| N11 | Two admins provision same group simultaneously | Exactly one environment, stable ID |
| N12 | Two grant writes share stale revision | At most one accepted |
| N13 | Existing mixed-company legacy ledger | Mark pending review; leave postings unchanged |
| N14 | Group removed/deleted then recreated | No identity hijack or automatic data reassignment |
| P01 | Authorized owner in active workspace | Read and permitted post accepted |
| P02 | Explicit allowed external invitation | Only authorized workspace and operation |
| P03 | Authorized current group subadmin | Can provision existing enabled group idempotently |

## Compatibility and validation boundary

Existing `/api/spaces` identifiers, audit history, posted balances, URLs and optional module entry points must remain backward-compatible. Adoption is phased; no silent permission elevation to restore prior behavior. Before implementation completion, test SQLite, PostgreSQL and MariaDB fresh installs and seeded upgrades, actual Nextcloud session/CSRF behavior, permission-denial responses and concurrency. CI passing on the previous main SHA does not certify this proposed contract.

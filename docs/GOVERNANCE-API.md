<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Instance governance and delegated group administration

LibreFinance is one installed app with independently authorized financial
contexts. The naming is generic; financial contexts can belong to businesses,
households, organizations or collectives. A Nextcloud group is an access
boundary, not automatically a legal company.

## Default and managed modes

Default mode remains compatible with existing personal deployments:
authenticated users can create financial spaces and owners manage access.

Only an **instance administrator** may enable managed provisioning. When
enabled, ordinary members and group subadministrators cannot create contexts.
An instance administrator creates/binds each financial context to one
existing Nextcloud group. A group subadministrator (through Nextcloud's
public `OCP\Group\ISubAdmin` API) may then manage that context, including
its bank accounts, contacts, financial data and user permissions. They do
**not** gain permissions in other contexts or the global administration API.

Financial contexts with an assigned `governance_gid` require membership in
that group, a group-subadmin grant for that specific group, or a global admin.
Existing unbound contexts retain their data. When managed provisioning is
enabled, they become inaccessible to ordinary users until an instance admin
reviews and binds them to an approved group. Revoking Nextcloud
group membership immediately denies access at the server authorization layer.
An instance administrator remains a trusted infrastructure administrator and
can access all managed contexts.

Delegates cannot grant arbitrary Nextcloud groups, change the governed
group's group-role, or grant users outside the bound group. No automatic
mapping from company, group name, or CNPJ is performed.

## JSON API

All endpoints use the same Nextcloud session/CSRF and HTTP authentication
mechanisms as the other LibreFinance APIs. Neither static frontend visibility
nor a client-supplied context ID conveys authorization.

| Method | Route | Audience |
| --- | --- | --- |
| GET | `/apps/librefinance/api/governance/capabilities` | Authenticated user |
| GET | `/apps/librefinance/api/admin/governance` | Instance admin |
| PUT | `/apps/librefinance/api/admin/governance` | Instance admin |
| GET | `/apps/librefinance/api/admin/contexts` | Instance admin |
| GET | `/apps/librefinance/api/admin/groups?search=...&offset=...` | Instance admin |
| POST | `/apps/librefinance/api/admin/contexts` | Instance admin |
| PUT | `/apps/librefinance/api/admin/contexts/{spaceId}/group` | Instance admin |
| POST | `/apps/librefinance/api/admin/contexts/{spaceId}/archive` | Instance admin |
| POST | `/apps/librefinance/api/admin/contexts/{spaceId}/restore` | Instance admin |
| GET | `/apps/librefinance/api/spaces/{spaceId}/governance` | Authorized context user |
| GET | `/apps/librefinance/api/spaces/{spaceId}/members` | Context manager |
| POST | `/apps/librefinance/api/spaces/{spaceId}/members` | Context manager |
| DELETE | `/apps/librefinance/api/spaces/{spaceId}/members/{uid}` | Context manager |
| GET | `/apps/librefinance/api/spaces/{spaceId}/groups` | Context manager |
| POST | `/apps/librefinance/api/spaces/{spaceId}/groups` | Context manager, bounded |
| DELETE | `/apps/librefinance/api/spaces/{spaceId}/groups/{gid}` | Context manager, bounded |

`PUT /api/admin/governance`: `{"managed":true}`.

`POST /api/admin/contexts`:
`{"name":"Example Organization","kind":"organization","gid":"example-group","currency":"BRL"}`.

`PUT /api/admin/contexts/123/group`:
`{"gid":"example-group"}`.

All account, banking, invoice, contact and custom-field routes retain their
space-scoped APIs and existing role checks. These route contracts do not
constitute full external connector certification or formal accounting
consolidation. Admin API callers are protected against non-admin access even
when a user is a Nextcloud group subadministrator.

## Deployment and safety

The `lf_spaces.governance_gid` migration is nullable and does not delete,
move or automatically reinterpret financial data. Binding existing spaces
changes authorization, so admins must verify the intended group and its
members before binding. A delegated admin is not automatically an instance
admin. Test dynamic group membership, revoked subadmin status, cross-space
read/write, attachment access and optional modules before institutional
rollout. Only synthetic CSV/OFX fixtures belong in automated tests.

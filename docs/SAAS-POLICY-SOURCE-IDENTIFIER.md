<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# SaaS v1 policy source identifiers

The instance, environment, workspace and self-service policy endpoints include
`sourceIdentifier` on each successful policy object, including list entries
and mutation responses. This implements the source identifier promised by
[ADR 0004](ADR-0004-saas-environments-and-authority.md).

| `sourceScope` | `sourceIdentifier` |
| --- | --- |
| `default` | JSON `null` for the policy registry default |
| `instance` | The literal string `"instance"` for the single instance scope |
| `environment` | The environment's public UUID for an authorized environment manager or a current group member with workspace access. JSON `null` when workspace access comes through an external invitation. |
| `workspace` | The authorized workspace's API identifier (`spaceId`) as a decimal string |
| `user` | The authenticated actor's own Nextcloud UID on self-service policy responses |

The identifier follows the existing effective `sourceScope`. An explicit
instance value takes precedence over the registry default. If a child
restriction leaves the effective value unchanged, attribution stays with the
ancestor. When a child narrows a set, attribution follows that child,
including when the intersection is empty. The existing `blockedBy` scope
continues to describe the blocker. An external invitation reader can receive
`"sourceScope": "environment"` with `"sourceIdentifier": null` while the
environment restriction still applies.

The field is additive within contract version 1. Clients can continue to use
`sourceScope` alone and can handle an absent identifier when calling older
servers. Revisions and `inherited` describe the requested scope's local
record. Access checks and error payloads retain their existing contract.

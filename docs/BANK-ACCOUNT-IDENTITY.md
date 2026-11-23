<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Bank and cash account identities

The standard financial account is not a credential vault. Its only required
human field is a display name (and an internally generated ledger code).
When classified as a bank or cash account it supports optional institution
name/code, branch, account identifier, account type, IBAN and SWIFT/BIC.
Countries, cashboxes, credit unions, payment institutions and international
accounts do not have identical mandatory banking identifiers. All attributes
are scoped to the finance context and validated before storing.

The institution logo is optional and stored only in private, space-isolated
Nextcloud AppData, with authenticated image reads and strict PNG/JPEG/WebP
size/dimension validation. No third-party public logo lookup, SVG,
credential or tracking URL is accepted. A financial account with a logo
must have the logo removed before the account itself is permanently deleted.

The identification fields are for display and future connector contracts:
they do not connect to online banking and should not contain passwords,
one-time codes, card PANs or API secrets. Custom fields can supplement
institution-specific metadata but cannot replace the stable common fields
required to compare bank records and build interoperable integrations.

POST /api/spaces/{spaceId}/accounts accepts `bankDetails` as an optional
object on bank/cash accounts; PUT /api/spaces/{spaceId}/accounts/{accountId}
accepts partial bank details. GET /accounts returns the optional metadata.
The optional bank logo endpoints are
`POST/GET/DELETE /api/spaces/{spaceId}/accounts/{accountId}/logo`.

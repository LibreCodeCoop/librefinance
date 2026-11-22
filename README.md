<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# LibreFinance

LibreFinance is an independent, free-software financial management application for **Nextcloud**. It is designed for individuals, shared groups, cooperatives and organizations worldwide. The software does not require an account or subscription with its maintainer, LibreCode Coop, or with any incumbent accounting vendor.

> **Development preview — v0.17.0.** Not certified for production accounting, tax reporting, banking or financial migration. Its current UI is mainly Brazilian Portuguese; this is **not** yet a translated international release. The current ledger supports **BRL only**, although legal company country codes are configurable. The optional Brazilian NFS-e integration is drafts-only.

## Capabilities implemented in the preview

- Separate personal, collective and organizational financial workspaces, with membership and group grants using Nextcloud identities.
- Double-entry ledger, immutable postings, audited reversals, account balances, categories and manual payment methods.
- Contacts, products/services and configurable legal company profiles, including company logos.
- Custom fields for companies, contacts, items and journals.
- Configurable dashboard layout, with a fixed set of built-in widgets.
- Private HTTP APIs, OpenAPI 3.1, read-only MCP features and an opt-in Nextcloud TaskProcessing assistant.
- Banking, reconciliation, bills, commercial invoicing, taxation and saved reporting **inside the main LibreFinance application**.
- Two independently installable Nextcloud integrations: approved historical imports and Brazilian NFS-e **drafts only**.

Banking, obligations, invoicing and reports are now built-in feature areas with server-enforced per-space controls, not separately installed Nextcloud apps. Only Import and NFS-e are optional packages; generic extension injection into the main Vue interface is not implemented.

## What it does **not** yet do

It cannot automatically connect to a bank, process Stripe/PayPal payments, issue an authorized Brazilian NFS-e, migrate an entire legacy accounting system, or deliver formally certified financial statements. Bank statement entries remain separate from posted accounting entries until explicitly reconciled. There are no live bank API credentials or fiscal certificates in this repository.

See [functional coverage](docs/FUNCTIONAL-COVERAGE.md), [module contracts](docs/MODULES.md), [roadmap](docs/ROADMAP.md) and [test strategy](docs/TESTING.md).

## Requirements and installation

This preview targets **Nextcloud 36 development** and PHP **8.3+**; compatible stable Nextcloud releases have not yet been certified. It uses the database configured in Nextcloud and does not require any extra database, JavaScript runtime or container in production.

Install the core as `custom_apps/librefinance`. An administrator can enable it with:

```sh
php occ app:enable librefinance
```

The full financial product is installed with just `librefinance`. Import (`librefinance_import`) and Brazil-specific NFS-e (`librefinance_nfse`) are available to install separately only when needed. Workspace managers can switch built-in features on/off without installing arbitrary PHP.

For an existing development instance that has the four former module apps, first back up the full Nextcloud database, stop financial writes, **disable but do not uninstall** those four apps, then upgrade the core and compatible Import/NFS-e integrations. The core preserves old physical table names and never fabricates historical payment matches. See [module architecture and safe upgrade requirements](docs/MODULES.md).

To build the frontend in a development checkout:

```sh
npm ci --include=dev
npm test
npm run test:openapi
npm run build
```

Only build-time tooling uses Node.js. The generated application bundles are `js/main.js` and `css/main.css`.

## Development and security

[API documentation](docs/API.md) · [OpenAPI](docs/OPENAPI.md) · [Data model](docs/SCHEMA.md) · [Testing](docs/TESTING.md) · [Contributing](CONTRIBUTING.md) · [Security reports](SECURITY.md).

The test environment uses disposable, **synthetic** Nextcloud accounts and financial records. Never run destructive smoke tests against a production installation. Do not add live bank tokens, certificates, identity documents, client data or internal server credentials to source, test fixtures or issue reports.

All core code is licensed under **AGPL-3.0-or-later**. Third-party dependencies retain their respective licenses and notices; see [third-party notices](docs/THIRD-PARTY.md). LibreCode Coop is a maintainer and copyright holder, **not a mandatory service provider or tenant of the application**. Anyone may self-host and extend this software under its license.

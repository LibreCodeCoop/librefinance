<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Contributor guidance — LibreFinance

- Native Nextcloud app targeting `nextcloud/server` official `master` (current 36.0.0 dev), not any stable 34 release. PHP services and OCP contracts, Vue 3 frontend; no Blade, Laravel or proprietary financial-software dependencies.
- Original finance domain design and observable public-feature requirements only. Do not copy proprietary tables, UI, algorithms or code from financial vendors. Audit third-party notices and do not use BSL-licensed application source as a template.
- Every read/write must check membership and role server-side before selection; never rely on \`spaceId\` from the client as authorization.
- Every financial posting must remain balanced, exact, immutable after posting and auditable. Never delete posted transactions to correct amounts.
- Use \`OCP\DB\QueryBuilder\` for DB changes. Test SQLite, MariaDB and PostgreSQL; maintain immutable migrations.
- No secrets in source, logs, screenshots, issues or PR descriptions. Remote banking, NFS-e, AI, payments and signatures are optional modules with explicit authorization.
- Build and validate PHP and Vue separately. Never commit generated `js/` or `css/`; build assets for development, browser CI and the App Store archive. Keep OpenAPI versioned. A route or a green CI that skipped tests is not proof of financial correctness.
- PRs: incremental signed commits with DCO, no automatic squash; follow LibreCode repository governance.
- Issue and epic creation requires exact human approval after drafting and review. Do not publish private commercial context.

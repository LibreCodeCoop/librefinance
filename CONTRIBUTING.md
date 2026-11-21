<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Contributing to LibreFinance

LibreFinance is open to contributors worldwide. Its code must remain independent of any particular organization's Nextcloud instance, bank account, fiscal identity or internal infrastructure.

Open an issue to discuss significant architectural changes. Smaller fixes should include a regression test when possible. Use Conventional Commits, a matching `Signed-off-by:` DCO trailer (`git commit -s`), and cryptographically signed commits (`git commit -S`). Do not sign another contributor's name.

For frontend changes, use native Nextcloud components and translation facilities. For backend changes, preserve authorization per financial workspace and the immutable ledger. Install-only or frontend-only checks do not prove a financial workflow works.

See [README](README.md), [test strategy](docs/TESTING.md) and [security policy](SECURITY.md). Contributions must not include live financial records, credentials, third-party proprietary implementation code, or unredacted screenshots.

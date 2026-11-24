<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Localization

LibreFinance uses the Nextcloud language preference. English source messages
are the fallback; the Brazilian Portuguese catalog is in `l10n/pt_BR.json`
and `l10n/pt_BR.js`. The application shell, navigation/search labels and
amount-validation messages use this mechanism. Other existing screens still
need migration from literal messages; these catalogs do not imply full
translation of the application.

Use `t('librefinance', 'English source message')` from `@nextcloud/l10n` in
frontend code and the Nextcloud localization factory in PHP. Use native
plural handling (`n`) when a message depends on a count, and keep named
placeholders intact in every language. Do not assemble translated sentences
by concatenating fragments. User-provided names, stable navigation IDs,
account codes and persisted financial values must not be translated.

## Updating the catalogs

The standard Nextcloud translation tool from nextcloud/docker-ci generates
the source template and runtime catalogs. The following commands were
validated with translationtool.phar at repository revision
`a823dbf56fa0837547825f3f188827da49aee767`, PHP and GNU gettext. Obtain the
tool from the official repository; run from the application root:

```sh
php /path/to/translationtool.phar create-pot-files
msgmerge --update translationfiles/pt_BR/librefinance.po translationfiles/templates/librefinance.pot
msgfmt --check -o /dev/null translationfiles/pt_BR/librefinance.po
php /path/to/translationtool.phar convert-po-files
npm test
npm run build
```

Review extraction warnings and the resulting diff. Preserve SPDX notices in
the template, catalogs and sidecar license files; keep source references
repository-relative. Translate each new source message in the PO catalog,
review placeholders and plurals, and commit the generated runtime catalogs
alongside their sources. Do not edit generated catalogs independently.

For browser verification, change the Nextcloud account language and check
the same workspace and navigation route in both languages. The focused
Playwright test in `tests/e2e/localization-shell.spec.mjs` checks native
language selection and that changing language preserves workspace identity,
routes and keyboard navigation.

Optional apps maintain independent catalogs under their own Nextcloud app
IDs. Use native locale-aware display formatting while retaining exact
monetary amounts and canonical dates in the underlying contracts.

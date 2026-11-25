# SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later

.PHONY: appstore verify-appstore-package

# The release workflow installs the locked npm dependencies first.
appstore:
	npm run build
	python3 scripts/appstore-package.py build

verify-appstore-package:
	python3 scripts/appstore-package.py verify

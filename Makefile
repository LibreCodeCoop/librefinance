# SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later

app_name := librefinance
appstore_build_directory := build/artifacts
appstore_package_name := $(appstore_build_directory)/$(app_name).tar.gz
appstore_sources := appinfo lib templates l10n img schemas docs LICENSE LICENSES README.md SECURITY.md composer.json REUSE.toml openapi.json

# The shared LibreCode publisher builds the frontend, calls this target,
# then signs, validates and publishes the archive. No release secrets here.
.PHONY: appstore
appstore:
	test -s js/main.js
	test -s js/admin.js
	test -s css/main.css
	test -s css/admin.css
	test -z "$$(git ls-files -- js css)"
	mkdir -p $(appstore_build_directory)
	git ls-files -z -- $(appstore_sources) > $(appstore_build_directory)/source-files
	tar --sort=name --mtime=@0 --owner=0 --group=0 --numeric-owner \
		--mode='u+rwX,go+rX,go-w' --transform='s,^,$(app_name)/,' \
		-czf $(appstore_package_name) --no-recursion --null \
		-T $(appstore_build_directory)/source-files --recursion js css
	rm $(appstore_build_directory)/source-files

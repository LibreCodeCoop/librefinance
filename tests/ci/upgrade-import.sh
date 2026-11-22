#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Destructive ONLY inside uniquely named, disposable Nextcloud DB volumes.
# Retain historical Import schema and rows without needing the retired code.
set -euo pipefail
umask 077

root="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$root"
: "${LF_NEXTCLOUD_SOURCE:?An official checked-out Nextcloud server is required}"
: "${LF_UPGRADE_OLD_CORE_SOURCE:?Pinned pre-consolidation core source is required}"
for p in "$LF_NEXTCLOUD_SOURCE" "$LF_UPGRADE_OLD_CORE_SOURCE"; do
  test -d "$p"
  test -s "$p/version.php" || test -s "$p/appinfo/info.xml"
done
test -s "$root/appinfo/info.xml"

export LF_TEST_DATABASE="${LF_TEST_DATABASE:-pgsql}"
case "$LF_TEST_DATABASE" in
  pgsql|sqlite|mariadb) ;;
  *) echo 'Unsupported upgrade database engine' >&2; exit 2 ;;
esac
export LF_TEST_PASSWORD="$(openssl rand -hex 20)"
export LF_TEST_DB_ROOT_PASSWORD="$(openssl rand -hex 20)"
export LF_TEST_ADMIN_PASSWORD="$(openssl rand -hex 20)"
export LF_TEST_USER_PASS="$(openssl rand -hex 20)"
export LF_UPGRADE_CONFIG_DIR="$(mktemp -d /tmp/lf-import-upgrade-config.XXXXXX)"
export COMPOSE_PROJECT_NAME="lf-import-upgrade-$$"
export LF_UPGRADE_CORE_SOURCE="$LF_UPGRADE_OLD_CORE_SOURCE"
export LF_UPGRADE_SEED_SOURCE="$root/tests/ci/upgrade-seed.php"
export LF_UPGRADE_SNAPSHOT_SOURCE="$root/tests/ci/upgrade-snapshot.php"
export LF_UPGRADE_SCHEMA_SOURCE="$root/tests/ci/legacy-import-schema.php"

compose() {
  if [ "$LF_TEST_DATABASE" = 'mariadb' ]; then
    docker compose --profile mariadb -f tests/ci/compose.yml -f tests/ci/compose-upgrade-import.yml "$@"
  else
    docker compose -f tests/ci/compose.yml -f tests/ci/compose-upgrade-import.yml "$@"
  fi
}
cleanup() {
  rc=$?
  trap - EXIT INT TERM
  if [ "$rc" -ne 0 ]; then
    echo "FAIL: synthetic Import upgrade; inspect the preceding first error." >&2
    compose ps || true
  fi
  compose exec -T --user root nextcloud chown -R "$(id -u):$(id -g)" /var/www/html/config >/dev/null 2>&1 || true
  compose down --volumes --remove-orphans >/dev/null 2>&1 || true
  rm -rf -- "$LF_UPGRADE_CONFIG_DIR"
  exit "$rc"
}
trap cleanup EXIT INT TERM

# The official Nextcloud checkout is mounted read-only. Docker cannot create
# nested volume mountpoints inside it: pre-create them on the host checkout.
# This mirrors smoke.sh and is required on fresh GitHub Actions checkouts.
mkdir -p "$LF_NEXTCLOUD_SOURCE/data" "$LF_NEXTCLOUD_SOURCE/custom_apps/librefinance"
test -d "$LF_NEXTCLOUD_SOURCE/data"
test -d "$LF_NEXTCLOUD_SOURCE/custom_apps/librefinance"

compose up -d --wait
compose exec -T --user root nextcloud chown -R www-data:www-data /var/www/html/config /var/www/html/data
if [ "$LF_TEST_DATABASE" = 'pgsql' ]; then
  compose exec -T --user www-data nextcloud php occ maintenance:install \
    --database pgsql --database-host db --database-name nextcloud \
    --database-user nextcloud --database-pass "$LF_TEST_PASSWORD" \
    --admin-user synthetictestadmin --admin-pass "$LF_TEST_ADMIN_PASSWORD" \
    --data-dir /var/www/html/data
elif [ "$LF_TEST_DATABASE" = 'mariadb' ]; then
  compose exec -T --user www-data nextcloud php occ maintenance:install \
    --database mysql --database-host mariadb --database-name nextcloud \
    --database-user nextcloud --database-pass "$LF_TEST_PASSWORD" \
    --admin-user synthetictestadmin --admin-pass "$LF_TEST_ADMIN_PASSWORD" \
    --data-dir /var/www/html/data
else
  compose exec -T --user www-data nextcloud php occ maintenance:install \
    --database sqlite --database-name nextcloud \
    --admin-user synthetictestadmin --admin-pass "$LF_TEST_ADMIN_PASSWORD" \
    --data-dir /var/www/html/data
fi

compose exec -T --user www-data nextcloud php -r '
require "/var/www/html/config/config.php";
$CONFIG["appstoreenabled"] = false;
$CONFIG["apps_paths"] = [
    ["path" => "/var/www/html/apps", "url" => "/apps", "writable" => false],
    ["path" => "/var/www/html/custom_apps", "url" => "/custom_apps", "writable" => true],
];
file_put_contents("/var/www/html/config/config.php",
    "<?php\n\$CONFIG = " . var_export($CONFIG, true) . ";\n");
'
compose exec -T --user www-data nextcloud php occ app:enable librefinance
compose exec -T --user www-data nextcloud php occ upgrade
compose exec -T -e OC_PASS="$LF_TEST_USER_PASS" --user www-data nextcloud \
  php occ user:add --password-from-env lfmaintestA

if fixture_out="$(compose exec -T -e LF_UPGRADE_ACTION=seed \
  -e LF_TEST_USER_PASS="$LF_TEST_USER_PASS" \
  -e LF_BASE_URL="http://127.0.0.1/index.php/apps/librefinance/api" \
  nextcloud php /tmp/upgrade-import-seed.php 2>&1)"; then
  printf '%s\n' "$fixture_out"
else
  printf '%s\n' "$fixture_out" >&2
  echo 'FAIL: old Import fixture rejected or aborted' >&2
  exit 1
fi
printf '%s\n' "$fixture_out"
space_id="$(printf '%s\n' "$fixture_out" | sed -n 's/^SPACE_ID=\([1-9][0-9]*\)$/\1/p')"
test -n "$space_id"
compose exec -T -e LF_UPGRADE_SPACE_ID="$space_id" nextcloud \
  php /tmp/legacy-import-schema.php

snapshot() {
  compose exec -T -e LF_UPGRADE_SPACE_ID="$space_id" nextcloud \
    php /tmp/upgrade-import-snapshot.php
}
if ! before="$(snapshot)"; then
  echo 'FAIL: unable to capture before-upgrade snapshot' >&2
  exit 1
fi
if [ -z "$before" ]; then
  echo 'FAIL: snapshot returned no records (Nextcloud CLI bootstrap may have exited)' >&2
  exit 1
fi
echo "DIAGNOSTIC: pre-upgrade snapshot labels: $(printf '%s\n' "$before" | cut -d'|' -f1 | sort | uniq -c | tr '\n' ' ')"
test "$(printf '%s\n' "$before" | grep -c '^mapping|')" -eq 2
test "$(printf '%s\n' "$before" | grep -c '^audit|')" -eq 1
test "$(printf '%s\n' "$before" | grep -c '^balance|')" -eq 1
test "$(printf '%s\n' "$before" | grep -c '^contact|')" -eq 1
test "$(printf '%s\n' "$before" | grep -c '^account|')" -eq 1
echo 'PASS: seeded pre-migration Import schema, audit, source balance and references'

# The historical app code is absent from the runtime. Never uninstall it from an existing instance.
export LF_UPGRADE_CORE_SOURCE="$root"
compose up -d --no-deps --force-recreate nextcloud
compose exec -T --user www-data nextcloud php occ upgrade

if ! after="$(snapshot)"; then
  echo 'FAIL: unable to capture after-upgrade snapshot' >&2
  exit 1
fi
if [ "$before" != "$after" ]; then
  echo 'FAIL: changed source mappings, origin balance, audit or foreign reference' >&2
  diff -u <(printf '%s\n' "$before") <(printf '%s\n' "$after") || true
  exit 1
fi
echo 'PASS: exact old Import rows and referenced contacts/accounts survived core upgrade'

# New core API must read the already mapped records without old app enabled.
compose exec -T -e LF_UPGRADE_ACTION=verify \
  -e LF_TEST_USER_PASS="$LF_TEST_USER_PASS" \
  -e LF_UPGRADE_SPACE_ID="$space_id" \
  -e LF_BASE_URL="http://127.0.0.1/index.php/apps/librefinance/api" \
  nextcloud php /tmp/upgrade-import-seed.php

echo 'PASS: core import engine reads legacy rows without the retired app installed'
echo 'ALL CHECKS PASSED: 3 (source snapshot, exact upgrade comparison, core read)'

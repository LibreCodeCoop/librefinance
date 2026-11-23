#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
set -euo pipefail

cd "$(dirname "$0")/../.."
project_root="$PWD"
# The matrix shares a mutable Nextcloud checkout and a single config directory.
# Running two smoke jobs against this checkout can corrupt each other's lab.
# Fail fast instead of resetting another process's synthetic database.
mkdir -p tests/ci/.runtime-config
if ! command -v flock >/dev/null 2>&1; then
  echo 'LibreFinance smoke requires flock (util-linux) for exclusive lab access' >&2
  exit 2
fi
exec 9>tests/ci/.runtime-config/.smoke.lock
if ! flock -n 9; then
  echo 'LibreFinance smoke already running in this checkout; no containers were touched' >&2
  exit 75
fi
export LF_TEST_PASSWORD="$(openssl rand -hex 24)"
export LF_TEST_ADMIN_PASSWORD="$(openssl rand -hex 24)"
export LF_TEST_DB_ROOT_PASSWORD="$(openssl rand -hex 24)"
export LF_TEST_USER_PASS="$(openssl rand -hex 24)"
export LF_TEST_DATABASE="${LF_TEST_DATABASE:-pgsql}"
case "$LF_TEST_DATABASE" in
  pgsql|sqlite|mariadb) ;;
  *) echo 'Unsupported CI DB backend' >&2; exit 2 ;;
esac
export COMPOSE_PROJECT_NAME="lf-domains-${LF_TEST_DATABASE}"
export LF_WITH_EXTENSIONS=0
if [ -n "$(printenv LF_NFSE_SOURCE || true)" ]; then
  export LF_NFSE_SOURCE="$(realpath "$LF_NFSE_SOURCE")"
  test -f "$LF_NFSE_SOURCE/appinfo/info.xml"
  test -s "$LF_NFSE_SOURCE/js/main.js"
  export LF_WITH_EXTENSIONS=1
fi

export LF_NEXTCLOUD_SOURCE="${LF_NEXTCLOUD_SOURCE:-$project_root/.ci-nextcloud-server}"
if [ ! -f "$LF_NEXTCLOUD_SOURCE/version.php" ]; then
  git clone --depth 1 --branch master --recurse-submodules --shallow-submodules \
    https://github.com/nextcloud/server.git "$LF_NEXTCLOUD_SOURCE"
fi
if [ ! -f "$LF_NEXTCLOUD_SOURCE/3rdparty/autoload.php" ]; then
  git -C "$LF_NEXTCLOUD_SOURCE" submodule update --init --depth 1 3rdparty
fi
echo "Testing nextcloud/server development HEAD: $(git -C "$LF_NEXTCLOUD_SOURCE" rev-parse HEAD)"
grep -F '$OC_VersionString' "$LF_NEXTCLOUD_SOURCE/version.php"

mkdir -p "$LF_NEXTCLOUD_SOURCE/data" "$LF_NEXTCLOUD_SOURCE/custom_apps/librefinance"
if [ "$LF_WITH_EXTENSIONS" = 1 ]; then
  mkdir -p "$LF_NEXTCLOUD_SOURCE/custom_apps/librefinance_nfse"
fi
chmod 755 "$LF_NEXTCLOUD_SOURCE/custom_apps" "$LF_NEXTCLOUD_SOURCE/custom_apps/librefinance"
mkdir -p tests/ci/.runtime-config
# A previous interrupted container may leave the bind-mounted config files
# owned by www-data or root. Fail before starting Docker, not during install.
if [ ! -w tests/ci/.runtime-config ]; then
  echo "Synthetic lab config directory is not writable; restore ownership before retry." >&2
  exit 2
fi
for target in config.sample.php .htaccess CAN_INSTALL; do
  if [ -e "tests/ci/.runtime-config/$target" ] && [ ! -w "tests/ci/.runtime-config/$target" ]; then
    echo "Synthetic lab config file is not writable: $target" >&2
    exit 2
  fi
done
cp "$LF_NEXTCLOUD_SOURCE/config/config.sample.php" \
   "$LF_NEXTCLOUD_SOURCE/config/.htaccess" \
   "$LF_NEXTCLOUD_SOURCE/config/CAN_INSTALL" tests/ci/.runtime-config/

compose() {
  local -a args=(-f tests/ci/compose.yml)
  if [ "$LF_WITH_EXTENSIONS" = 1 ]; then
    args+=(-f tests/ci/compose-extensions.yml)
  fi
  if [ "$LF_TEST_DATABASE" = 'mariadb' ]; then
    docker compose --profile mariadb "${args[@]}" "$@"
  else
    docker compose "${args[@]}" "$@"
  fi
}
cleanup() {
  rc=$?
  if [ "$rc" -ne 0 ]; then
    # Capture container state before destroying the disposable lab. A transient
    # connection refusal cannot be diagnosed from HTTP assertions alone.
    echo 'DIAGNOSTICS: disposable lab containers before cleanup'
    compose ps -a || true
    for service in nextcloud db mariadb; do
      container_id="$(compose ps -q "$service" 2>/dev/null || true)"
      if [ -n "$container_id" ]; then
        docker inspect --format \
          'DIAGNOSTICS: {{.Name}} state={{.State.Status}} exit={{.State.ExitCode}} oom={{.State.OOMKilled}} restart={{.RestartCount}}' \
          "$container_id" || true
      fi
    done
    compose logs --tail 45 nextcloud db || true
    # Synthetic CI database only; preserve application exception diagnostics.
    compose exec -T nextcloud php -r '
      $f = "/var/www/html/data/nextcloud.log";
      if (is_file($f)) {
        foreach (array_slice(file($f), -8) as $line) {
          $e = json_decode($line, true);
          if (is_array($e)) {
            echo "NC-ERROR ", ($e["message"] ?? ""), " | ",
              ($e["exception"]["Exception"] ?? ""), ": ",
              ($e["exception"]["Message"] ?? ""), PHP_EOL;
            foreach (array_slice($e["exception"]["Trace"] ?? [], 0, 3) as $t) {
              echo "  at ", ($t["file"] ?? ""), ":", ($t["line"] ?? ""), PHP_EOL;
            }
          }
        }
      }
    ' || true
  fi
  # Nextcloud runs as www-data inside the container. Restore host ownership
  # before removing the only mutable CI config file on the checkout.
  compose exec -T --user root nextcloud chown -R "$(id -u):$(id -g)" /var/www/html/config || true
  compose down --volumes --remove-orphans || true
  rm -f tests/ci/.runtime-config/config.php || true
  exit "$rc"
}
trap cleanup EXIT
rm -f tests/ci/.runtime-config/config.php

compose config --quiet
compose up -d --wait
compose exec -T --user root nextcloud chown -R www-data:www-data /var/www/html/config /var/www/html/data
if [ "$LF_TEST_DATABASE" = "pgsql" ]; then
  compose exec -T --user www-data nextcloud php occ maintenance:install \
    --database pgsql --database-host db --database-name nextcloud \
    --database-user nextcloud --database-pass "$LF_TEST_PASSWORD" \
    --admin-user synthetictestadmin --admin-pass "$LF_TEST_ADMIN_PASSWORD" \
    --data-dir /var/www/html/data
elif [ "$LF_TEST_DATABASE" = "mariadb" ]; then
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

# Source is read-only: register core and optional NFS-e without changing server checkout.
compose exec -T --user www-data nextcloud php -r '
require "/var/www/html/config/config.php";
$CONFIG["appstoreenabled"] = false;
$CONFIG["apps_paths"] = [
  ["path" => "/var/www/html/apps", "url" => "/apps", "writable" => false],
  ["path" => "/var/www/html/custom_apps", "url" => "/custom_apps", "writable" => true],
];
file_put_contents("/var/www/html/config/config.php", "<?php\n\$CONFIG = " . var_export($CONFIG, true) . ";\n");
'
compose exec -T --user www-data nextcloud php occ app:enable librefinance
compose exec -T nextcloud php /var/www/html/custom_apps/librefinance/tests/import/source-system.php
if [ "$LF_WITH_EXTENSIONS" = 1 ]; then
  compose exec -T --user www-data nextcloud php occ app:enable librefinance_nfse
  # Source-backed OpenAPI gate across core + all separately installed apps.
  compose exec -T nextcloud php /var/www/html/custom_apps/librefinance/tests/openapi/generate.php --check
fi
# Nextcloud may detect newer appinfo versions after installation; complete
# schema/version migrations before browser access instead of exposing the
# "App update required" maintenance screen during functional tests.
compose exec -T --user www-data nextcloud php occ upgrade
# Assert the exact core and optional external route contracts against Nextcloud's real route resolver;
# linting attributes or checking only one successful URL is insufficient.
route_apps=(librefinance)
if [ "$LF_WITH_EXTENSIONS" = 1 ]; then
  route_apps+=(librefinance_nfse)
fi
compose exec -T --user www-data nextcloud php occ router:list "${route_apps[@]}" --index --output=json \
  | python3 tests/ci/check-route-contracts.py tests/ci/routes-expected.json "${route_apps[@]}"
compose exec -T --user www-data nextcloud php occ status | grep -E 'installed: true|versionstring:'
if [ "$LF_TEST_DATABASE" = "pgsql" ]; then
  count="$(compose exec -T db psql -U nextcloud -d nextcloud -Atc \
    "SELECT COUNT(*) FROM pg_tables WHERE schemaname = 'public' AND LEFT(tablename, 6) = 'oc_lf_';" | tr -d '\r')"
elif [ "$LF_TEST_DATABASE" = "mariadb" ]; then
  count="$(compose exec -T -e MYSQL_PWD="$LF_TEST_PASSWORD" mariadb \
    mariadb -u nextcloud -D nextcloud -N -B -e \
    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='nextcloud' AND LEFT(table_name, 6) = 'oc_lf_';" | tr -d '\r')"
else
  count="$(compose exec -T --user www-data nextcloud php -r '
    foreach (glob("/var/www/html/data/*.db") as $file) {
      $pdo = new PDO("sqlite:" . $file);
      $query = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type=? AND SUBSTR(name, 1, 6) = ?");
      $query->execute(["table", "oc_lf_"]);
      echo $query->fetchColumn();
    }
  ' | tr -d '\r')"
fi
test "$count" -eq 17

# Imported provenance tables use the lfm_ physical prefix, not lf_.
# Verify the three durable origin tables independently in every engine.
if [ "$LF_TEST_DATABASE" = "pgsql" ]; then
  import_tables="$(compose exec -T db psql -U nextcloud -d nextcloud -Atc \
    "SELECT COUNT(*) FROM pg_tables WHERE schemaname='public' AND tablename IN ('oc_lfm_records','oc_lfm_audit','oc_lfm_liquid_origins');" | tr -d '\r')"
elif [ "$LF_TEST_DATABASE" = "mariadb" ]; then
  import_tables="$(compose exec -T -e MYSQL_PWD="$LF_TEST_PASSWORD" mariadb \
    mariadb -u nextcloud -D nextcloud -N -B -e \
    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='nextcloud' AND table_name IN ('oc_lfm_records','oc_lfm_audit','oc_lfm_liquid_origins');" | tr -d '\r')"
else
  import_tables="$(compose exec -T --user www-data nextcloud php -r '
    foreach (glob("/var/www/html/data/*.db") as $file) {
      $pdo = new PDO("sqlite:" . $file);
      $q = $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='\''table'\'' AND name IN ('\''oc_lfm_records'\'','\''oc_lfm_audit'\'','\''oc_lfm_liquid_origins'\'')");
      echo $q->fetchColumn();
    }
  ' | tr -d '\r')"
fi
test "$import_tables" -eq 3
echo "PASS: Three core-owned source identity/audit/balance-snapshot import tables."

http_status="$(compose exec -T nextcloud curl -sS --max-time 12 -o /dev/null -w '%{http_code}' http://127.0.0.1/status.php)"
test "$http_status" = 200
echo "PASS: Nextcloud Server master install, LibreFinance activation, seventeen core tables and HTTP 200."

# Real two-user authorization, accounting and concurrency checks on this exact master checkout.
for test_user in lfmaintestA lfmaintestB; do
  compose exec -T -e OC_PASS="$LF_TEST_USER_PASS" --user www-data nextcloud \
    php occ user:add --password-from-env "$test_user"
done
compose exec -T --user www-data nextcloud php occ group:add lf_synth_finance_group
compose exec -T --user www-data nextcloud php occ group:adduser lf_synth_finance_group lfmaintestB
# Browser diagnostics can run independently for fast feedback on layout and
# CSRF. Official CI always runs full API+Behat+browser suites.
# Nextcloud CLI fatal handlers may exit successfully after a test abort. Require
# a real completion marker and no failure marker from every integration suite.
assert_integration_complete() {
  local script="$1"
  local output
  if ! output="$(compose exec -T -e LF_TEST_USER_PASS="$LF_TEST_USER_PASS"     -e LF_BASE_URL="http://127.0.0.1/index.php/apps/librefinance/api" nextcloud     php "$script" 2>&1)"; then
    printf '%s\n' "$output" >&2
    echo "FAIL: PHP integration $script exited nonzero" >&2
    exit 1
  fi
  printf '%s\n' "$output"
  if grep -Eq '^FAIL([ :]|$)' <<< "$output"     || ! grep -Eq '^ALL CHECKS PASSED: [1-9][0-9]*$' <<< "$output"; then
    echo "FAIL: PHP integration $script had failed or missing assertions" >&2
    exit 1
  fi
}

if [ "$(printenv LF_BROWSER_DEBUG_ONLY || echo 0)" != 1 ]; then
# Run native PHP integration suites inside the same Nextcloud PHP runtime.
for script in basic personal-finance agenda-search-sort spaces advanced governance catalog categories companies custom-fields field-studio-v3 ux-enhancements company-logo payment-methods; do
  assert_integration_complete "/var/www/html/custom_apps/librefinance/tests/integration/$script.php"
done
echo "PASS: Native PHP authorization, ledger, group access and concurrency tests on Nextcloud Server master."
# A malformed OFX statement must be rejected before any bank account write.
ofx_unit="$(compose exec -T --user www-data nextcloud php   /var/www/html/custom_apps/librefinance/tests/unit/ofx-parser.php)"
printf '%s\n' "$ofx_unit"
grep -Eq '^OFX parser: 19 positive and negative assertions passed$' <<< "$ofx_unit" || {
  echo 'FAIL: OFX parser unit assertions did not complete' >&2
  exit 1
}
for domain_test in banking obligations invoicing payments reports-cash-flow reports-saved import; do
  assert_integration_complete "/var/www/html/custom_apps/librefinance/tests/integration/domains/$domain_test.php"
done
echo "PASS: Native banking, obligations, invoicing, reporting and core-import integration suites."

if [ "${LF_RUN_BEHAT:-1}" = 1 ]; then
  test -x tests/behat/vendor/bin/behat || {
    echo "Behat dependencies missing. Run composer install in tests/behat before the smoke suite." >&2
    exit 2
  }
  for shard in 1/2 2/2; do
    echo "Running real Behat API scenarios, shard $shard"
    compose exec -T -e LF_TEST_USER_PASS="$LF_TEST_USER_PASS" \
      -e LF_BASE_URL="http://127.0.0.1/index.php/apps/librefinance/api" nextcloud \
      sh /var/www/html/custom_apps/librefinance/tests/behat/run-shards.sh "$shard"
  done
  echo "PASS: Both Behat API shards completed, without skipped scenarios."
fi
if [ "$LF_WITH_EXTENSIONS" = 1 ]; then
  for script in account-deletion account-deletion-race modules mcp-ai; do
    assert_integration_complete "/var/www/html/custom_apps/librefinance/tests/integration/$script.php"
  done
  assert_integration_complete /var/www/html/custom_apps/librefinance_nfse/tests/integration.php
  echo "PASS: Independently installed NFS-e draft app passed its PHP suite."
fi
else
  echo "SKIPPED: Native PHP and Behat suites, browser-only local diagnostics explicitly requested."
fi

if [ "${LF_RUN_PLAYWRIGHT:-0}" = 1 ]; then
  test -x node_modules/.bin/playwright || {
    echo "Missing Playwright dependency: run npm ci --include=dev" >&2
    exit 2
  }
  endpoint="$(compose port nextcloud 80)"
  port="${endpoint##*:}"
  case "$port" in
    ''|*[!0-9]*) echo "Invalid local browser test port" >&2; exit 2 ;;
  esac
  compose exec -T --user www-data nextcloud php occ config:system:set trusted_domains 1 \
    --value="127.0.0.1:$port" >/dev/null
  if [ -n "${LF_PLAYWRIGHT_SHARD:-}" ]; then
    shards="$LF_PLAYWRIGHT_SHARD"
  else
    shards="1/2 2/2"
  fi
  extra_args=()
  if [ "${LF_E2E_CORE_ONLY:-0}" = 1 ]; then
    # The extension integration test requires separately installed apps.
    extra_args=(--grep-invert @extensions)
  fi
  # Optional focused local E2E selection. CI leaves it unset to test all paths.
  if [ -n "${LF_E2E_GREP:-}" ]; then
    extra_args+=(--grep "$LF_E2E_GREP")
  fi
  for shard in $shards; do
    echo "Running real Chromium Playwright shard $shard (synthetic test users)"
    LF_E2E_URL="http://127.0.0.1:$port" LF_TEST_USER_PASS="$LF_TEST_USER_PASS" \
      LF_E2E_SHARD="${shard/\//-}" npx playwright test --shard="$shard" "${extra_args[@]}"
  done
  echo "PASS: Both Chromium Playwright shards completed."
fi

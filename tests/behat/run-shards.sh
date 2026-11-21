#!/bin/sh
# SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
set -eu
cd "$(dirname "$0")"
test -x vendor/bin/behat || { echo 'Run composer install before Behat' >&2; exit 2; }
case "${1:-all}" in
  1/2) exec vendor/bin/behat --config=behat.yml --tags=@shard1 --format=progress ;;
  2/2) exec vendor/bin/behat --config=behat.yml --tags=@shard2 --format=progress ;;
  all) vendor/bin/behat --config=behat.yml --tags=@shard1 --format=progress
       vendor/bin/behat --config=behat.yml --tags=@shard2 --format=progress ;;
  *) echo 'Expected shard 1/2, 2/2 or all' >&2; exit 2 ;;
esac

#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"

run_suite() {
    local suite_name="$1"
    local suite_file="$REPO_ROOT/tests/unit/$suite_name"
    local expected_regex="$2"

    if [ ! -f "$suite_file" ]; then
        echo "FAIL: $suite_name (missing file)" >&2
        exit 1
    fi

    local output
    local exit_code=0
    output=$(php "$suite_file" 2>&1) || exit_code=$?

    if [ $exit_code -ne 0 ]; then
        echo "FAIL: $suite_name (nonzero exit: $exit_code)" >&2
        echo "$output" >&2
        exit 1
    fi

    if grep -Eq '^FAIL([[:space:]]|:|$)' <<< "$output"; then
        printf 'FAIL: %s (reported failure)\n%s\n' "$suite_name" "$output" >&2
        exit 1
    fi

    if ! grep -Eq "$expected_regex" <<< "$output"; then
        printf 'FAIL: %s (missing expected summary)\n%s\n' "$suite_name" "$output" >&2
        exit 1
    fi

    printf '%s\n' "$output"
}

run_suite "currency-conversion.php" '^ALL CHECKS PASSED: [1-9][0-9]*$'
run_suite "currency-rate-quote.php" '^Currency rate quote: [1-9][0-9]* assertions passed$'
run_suite "module-descriptor.php" '^Module descriptor: [1-9][0-9]* assertions passed$'
run_suite "ofx-parser.php" '^OFX parser: 19 positive and negative assertions passed$'
run_suite "saas-financial-capabilities.php" '^SaaS financial capability matrix: [1-9][0-9]* named allow/deny assertions passed$'
run_suite "saas-policy.php" '^SaaS policy resolver: [1-9][0-9]* named restrictive positive and negative assertions passed$'

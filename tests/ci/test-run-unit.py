#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later

"""Exercise the standalone unit gate with disposable, real PHP fixtures."""

import json
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest


RUNNER = Path(__file__).resolve().with_name("run-unit.sh")
SUMMARIES = {
    "currency-conversion.php": "ALL CHECKS PASSED: 30",
    "currency-rate-quote.php": "Currency rate quote: 21 assertions passed",
    "module-descriptor.php": "Module descriptor: 11 assertions passed",
    "ofx-parser.php": "OFX parser: 19 positive and negative assertions passed",
    "saas-financial-capabilities.php": "SaaS financial capability matrix: 415 named allow/deny assertions passed",
    "saas-policy.php": "SaaS policy resolver: 67 named restrictive positive and negative assertions passed",
}


def php_output(message):
    return "echo " + json.dumps(message) + " . PHP_EOL;\n"


class UnitGateTest(unittest.TestCase):
    def run_fixture(self, suite=None, body=None, missing=False):
        with tempfile.TemporaryDirectory(prefix="lf-unit-gate-") as directory:
            root = Path(directory)
            runner = root / "tests/ci/run-unit.sh"
            units = root / "tests/unit"
            runner.parent.mkdir(parents=True)
            units.mkdir()
            shutil.copyfile(RUNNER, runner)
            for name, summary in SUMMARIES.items():
                (units / name).write_text(
                    "<?php\n" + php_output(summary), encoding="utf-8"
                )
            if suite is not None:
                path = units / suite
                if missing:
                    path.unlink()
                else:
                    path.write_text("<?php\n" + body, encoding="utf-8")
            unrelated = root / "unrelated"
            unrelated.mkdir()
            return subprocess.run(
                ["bash", str(runner)], cwd=unrelated,
                capture_output=True, text=True, timeout=30, check=False,
            )

    def assert_rejected(self, suite, result):
        self.assertNotEqual(result.returncode, 0, result.stdout)
        self.assertIn("FAIL: " + suite, result.stderr)

    def test_valid_suites(self):
        result = self.run_fixture()
        self.assertEqual(result.returncode, 0, result.stderr)
        for summary in SUMMARIES.values():
            self.assertIn(summary, result.stdout.splitlines())

    def test_each_required_file(self):
        for suite in SUMMARIES:
            with self.subTest(suite=suite):
                self.assert_rejected(
                    suite, self.run_fixture(suite, missing=True)
                )

    def test_each_required_completion(self):
        for suite in SUMMARIES:
            with self.subTest(suite=suite):
                self.assert_rejected(
                    suite, self.run_fixture(suite, php_output("No tests ran"))
                )

    def test_nonzero_exit_despite_summary(self):
        suite = "currency-conversion.php"
        self.assert_rejected(
            suite, self.run_fixture(suite, php_output(SUMMARIES[suite]) + "exit(17);\n")
        )

    def test_reported_failure_despite_summary(self):
        suite = "currency-conversion.php"
        failures = {
            "stdout": php_output("FAIL: synthetic regression"),
            "stderr": "fwrite(STDERR, 'FAIL synthetic regression' . PHP_EOL);\n",
        }
        for stream, failure in failures.items():
            with self.subTest(stream=stream):
                self.assert_rejected(
                    suite, self.run_fixture(suite, php_output(SUMMARIES[suite]) + failure)
                )

    def test_invalid_completion(self):
        suite = "currency-conversion.php"
        invalid = {
            "zero": "ALL CHECKS PASSED: 0",
            "prefix": "prefix " + SUMMARIES[suite],
            "different suite": SUMMARIES["module-descriptor.php"],
        }
        for name, summary in invalid.items():
            with self.subTest(case=name):
                self.assert_rejected(suite, self.run_fixture(suite, php_output(summary)))


if __name__ == "__main__":
    unittest.main()

#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later

import importlib.util
from pathlib import Path
import unittest

spec = importlib.util.spec_from_file_location("report_timing", Path(__file__).with_name("report-timing.py"))
report = importlib.util.module_from_spec(spec)
spec.loader.exec_module(report)


class TimingTest(unittest.TestCase):
    def test_completed_run_and_steps(self):
        run = {
            "databaseId": 1, "attempt": 2, "headSha": "abc",
            "status": "completed", "conclusion": "failure",
            "createdAt": "2026-01-01T00:00:00Z", "startedAt": "2026-01-01T00:00:05Z",
            "updatedAt": "2026-01-01T00:02:00Z",
            "jobs": [{
                "name": "Integration", "status": "completed", "conclusion": "failure",
                "startedAt": "2026-01-01T00:00:10Z", "completedAt": "2026-01-01T00:01:50Z",
                "steps": [{
                    "name": "Install and test core, including built-in importer",
                    "status": "completed", "conclusion": "failure",
                    "startedAt": "2026-01-01T00:00:30Z", "completedAt": "2026-01-01T00:01:40Z",
                }],
            }],
        }
        result = report.summarize(run)
        self.assertEqual(result["attempt"], 2)
        self.assertEqual(result["conclusion"], "failure")
        self.assertEqual(result["run_start_delay_seconds"], 5)
        self.assertEqual(result["observed_run_elapsed_seconds"], 120)
        self.assertEqual(result["longest_recorded_job"]["seconds"], 100)
        self.assertEqual(result["jobs"][0]["setup_before_work_seconds"], 20)
        self.assertEqual(result["jobs"][0]["longest_recorded_step"]["seconds"], 70)

    def test_incomplete_or_unmeasurable_is_not_zero_or_success(self):
        result = report.summarize({
            "status": "in_progress",
            "jobs": [{"status": "in_progress", "startedAt": "2026-01-01T00:00:00Z",
                      "completedAt": "0001-01-01T00:00:00Z"},
                     {"status": "completed", "conclusion": "skipped"}],
        })
        self.assertIsNone(result["observed_run_elapsed_seconds"])
        self.assertIsNone(result["longest_recorded_job"])
        self.assertIsNone(result["jobs"][0]["seconds"])
        self.assertEqual(result["jobs"][1]["conclusion"], "skipped")
        self.assertIsNone(report.elapsed("2026-01-01T00:00:01Z", "2026-01-01T00:00:00Z"))
        self.assertIsNone(report.elapsed("2026-01-01T00:00:00", "2026-01-01T00:00:01"))


if __name__ == "__main__":
    unittest.main()

#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later

import importlib.util
from pathlib import Path
import unittest

spec = importlib.util.spec_from_file_location("report_timing", Path(__file__).with_name("report-timing.py"))
report = importlib.util.module_from_spec(spec)
spec.loader.exec_module(report)


def completed_job(start, end, **fields):
    return {"status": "completed", "startedAt": start, "completedAt": end, **fields}


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
        self.assertEqual(result["jobs"][0]["post_work_tail_seconds"], 10)

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
        self.assertEqual(result["job_parallelism"]["status"], "run_incomplete")
        self.assertIsNone(result["job_parallelism"]["peak_parallel_jobs"])

    def test_overlapping_jobs(self):
        result = report.summarize({"status": "completed", "jobs": [
            completed_job("2026-01-01T00:00:04Z", "2026-01-01T00:00:15Z"),
            completed_job("2026-01-01T01:00:00+01:00", "2026-01-01T00:00:10Z"),
            completed_job("2026-01-01T00:00:06Z", "2026-01-01T00:00:08Z"),
        ]})
        self.assertEqual(result["job_parallelism"], {
            "status": "measured", "measured_jobs": 3, "skipped_jobs": 0,
            "unmeasurable_jobs": 0, "job_span_seconds": 15,
            "sum_job_runtime_seconds": 23, "busy_seconds": 15, "overlap_seconds": 6,
            "peak_parallel_jobs": 3, "average_parallel_jobs": 1.533,
        })

    def test_sequential_jobs_with_touching_endpoints_and_gap(self):
        result = report.summarize({"status": "completed", "jobs": [
            completed_job("2026-01-01T00:00:00Z", "2026-01-01T00:00:05Z"),
            completed_job("2026-01-01T00:00:05Z", "2026-01-01T00:00:10Z"),
            completed_job("2026-01-01T00:00:15Z", "2026-01-01T00:00:20Z"),
        ]})["job_parallelism"]
        self.assertEqual(result["job_span_seconds"], 20)
        self.assertEqual(result["sum_job_runtime_seconds"], 15)
        self.assertEqual(result["busy_seconds"], 15)
        self.assertEqual(result["overlap_seconds"], 0)
        self.assertEqual(result["peak_parallel_jobs"], 1)
        self.assertEqual(result["average_parallel_jobs"], 0.75)

    def test_partial_coverage_ignores_skipped_and_invalid_intervals(self):
        result = report.summarize({"status": "completed", "jobs": [
            completed_job("2026-01-01T00:00:05Z", "2026-01-01T00:00:10Z"),
            completed_job("2026-01-01T00:00:00Z", "2026-01-01T00:00:20Z", conclusion="skipped"),
            completed_job("malformed", "2026-01-01T00:00:20Z"),
            completed_job("2026-01-01T00:00:00Z", None),
            completed_job("2026-01-01T00:00:00Z", "0001-01-01T00:00:00Z"),
            completed_job("2026-01-01T00:00:20Z", "2026-01-01T00:00:00Z"),
            {"status": "in_progress", "startedAt": "2026-01-01T00:00:00Z"},
        ]})["job_parallelism"]
        self.assertEqual(result, {
            "status": "partial", "measured_jobs": 1, "skipped_jobs": 1,
            "unmeasurable_jobs": 5, "job_span_seconds": 5,
            "sum_job_runtime_seconds": 5, "busy_seconds": 5, "overlap_seconds": 0,
            "peak_parallel_jobs": 1, "average_parallel_jobs": 1,
        })

    def test_run_without_measurable_jobs(self):
        for fields in ({}, {"jobs": None}, {"jobs": []},
                       {"jobs": [completed_job(None, None, conclusion="skipped")]}):
            with self.subTest(fields=fields):
                result = report.summarize({"status": "completed", **fields})["job_parallelism"]
                self.assertEqual(result["status"], "no_measurable_jobs")
                self.assertEqual(result["measured_jobs"], 0)
                for metric in ("job_span_seconds", "sum_job_runtime_seconds", "busy_seconds",
                               "overlap_seconds", "peak_parallel_jobs", "average_parallel_jobs"):
                    self.assertIsNone(result[metric])

    def test_zero_duration_is_measured_without_contributing_to_parallelism(self):
        instant = "2026-01-01T00:00:05Z"
        job = completed_job(instant, instant)
        run = {"status": "completed", "jobs": [job]}
        result = report.summarize(run)
        self.assertEqual(result["jobs"][0]["seconds"], 0)
        self.assertEqual(result["job_parallelism"], {
            "status": "measured", "measured_jobs": 1, "skipped_jobs": 0,
            "unmeasurable_jobs": 0, "job_span_seconds": 0,
            "sum_job_runtime_seconds": 0, "busy_seconds": 0, "overlap_seconds": 0,
            "peak_parallel_jobs": 0, "average_parallel_jobs": None,
        })
        run["jobs"].append(completed_job("2026-01-01T00:00:00Z", "2026-01-01T00:00:10Z"))
        self.assertEqual(report.summarize(run)["job_parallelism"]["peak_parallel_jobs"], 1)
        run["status"] = "in_progress"
        result = report.summarize(run)["job_parallelism"]
        self.assertEqual(result["status"], "run_incomplete")
        self.assertIsNone(result["sum_job_runtime_seconds"])

    def test_parallelism_uses_unrounded_intervals(self):
        job = completed_job("2026-01-01T00:00:00Z", "2026-01-01T00:00:00.0004Z")
        result = report.summarize({"status": "completed", "jobs": [job, job.copy()]})
        self.assertEqual(result["jobs"][0]["seconds"], 0)
        self.assertEqual(result["job_parallelism"]["sum_job_runtime_seconds"], 0.001)
        self.assertEqual(result["job_parallelism"]["peak_parallel_jobs"], 2)
        self.assertEqual(result["job_parallelism"]["average_parallel_jobs"], 2)

    def test_tail_uses_last_known_work_step(self):
        job = completed_job("2026-01-01T00:00:00Z", "2026-01-01T00:00:30Z", steps=[
            completed_job("2026-01-01T00:00:05Z", "2026-01-01T00:00:10Z",
                          name="Verify original source IDs, references and balances survive"),
            completed_job("2026-01-01T00:00:15Z", "2026-01-01T00:00:30Z",
                          name="Verify legacy accounting rows and grants survive SaaS upgrade"),
        ])
        run = {"status": "completed", "jobs": [job]}
        result = report.summarize(run)["jobs"][0]
        self.assertEqual(result["setup_before_work_seconds"], 5)
        self.assertEqual(result["post_work_tail_seconds"], 0)

        last_step = job["steps"][-1]
        for overrides in ({"conclusion": "skipped"}, {"status": "in_progress"},
                          {"completedAt": None}, {"completedAt": "2026-01-01T00:00:31Z"},
                          {"startedAt": "2025-12-31T23:59:59Z"},
                          {"completedAt": "2026-01-01T00:00:14Z"}):
            with self.subTest(overrides=overrides):
                job["steps"][-1] = {**last_step, **overrides}
                result = report.summarize(run)["jobs"][0]
                self.assertEqual(result["setup_before_work_seconds"], 5)
                self.assertIsNone(result["post_work_tail_seconds"])

        job["steps"] = []
        self.assertIsNone(report.summarize(run)["jobs"][0]["post_work_tail_seconds"])


if __name__ == "__main__":
    unittest.main()

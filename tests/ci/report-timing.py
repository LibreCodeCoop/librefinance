#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Summarize observed GitHub Actions timestamps; never a merge or release gate."""

import datetime
import json
import sys


WORK_STEPS = {
    "Run complete synthetic core and external-app integration",
    "Install and test core, including built-in importer",
    "Run isolated browser shard against installed Nextcloud",
    "Verify original source IDs, references and balances survive",
}


def elapsed(start, end):
    """Missing, zero, naive or inconsistent timestamps are not measurements."""
    try:
        first = datetime.datetime.fromisoformat(start.replace("Z", "+00:00"))
        last = datetime.datetime.fromisoformat(end.replace("Z", "+00:00"))
        if first.year == 1 or last.year == 1 or not first.tzinfo or not last.tzinfo:
            return None
        seconds = (last - first).total_seconds()
        return round(seconds, 3) if seconds >= 0 else None
    except (AttributeError, TypeError, ValueError, OverflowError):
        return None


def summarize(run):
    jobs = []
    for job in run.get("jobs", []):
        complete = job.get("status") == "completed"
        duration = elapsed(job.get("startedAt"), job.get("completedAt")) if complete else None
        work = next((step for step in job.get("steps", [])
                     if step.get("name") in WORK_STEPS), None)
        steps = [
            {"name": step.get("name"), "conclusion": step.get("conclusion"),
             "seconds": elapsed(step.get("startedAt"), step.get("completedAt"))
             if step.get("status") == "completed" else None}
            for step in job.get("steps", [])
        ]
        measured = [step for step in steps if step["seconds"] is not None]
        jobs.append({
            "name": job.get("name"), "status": job.get("status"),
            "conclusion": job.get("conclusion"), "seconds": duration,
            "setup_before_work_seconds": (
                elapsed(job.get("startedAt"), work.get("startedAt")) if work else None
            ),
            "longest_recorded_step": max(measured, key=lambda s: s["seconds"], default=None),
        })
    completed = [job for job in jobs if job["seconds"] is not None]
    terminal = run.get("status") == "completed"
    return {
        "run_id": run.get("databaseId"), "attempt": run.get("attempt"),
        "head_sha": run.get("headSha"), "status": run.get("status"),
        "conclusion": run.get("conclusion"),
        "run_start_delay_seconds": elapsed(run.get("createdAt"), run.get("startedAt")),
        "observed_run_elapsed_seconds": (
            elapsed(run.get("createdAt"), run.get("updatedAt")) if terminal else None
        ),
        "longest_recorded_job": max(completed, key=lambda j: j["seconds"], default=None),
        "jobs": jobs,
    }


if __name__ == "__main__":
    print(json.dumps(summarize(json.load(sys.stdin)), indent=2, ensure_ascii=False))

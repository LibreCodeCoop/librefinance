#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Summarize observed GitHub Actions timestamps; never a merge or release gate."""

import datetime
import json
import sys
from collections import defaultdict


WORK_STEPS = {
    "Run complete synthetic core and external-app integration",
    "Install and test core, including built-in importer",
    "Run isolated browser shard against installed Nextcloud",
    "Verify original source IDs, references and balances survive",
    "Verify legacy accounting rows and grants survive SaaS upgrade",
}


def timestamp(value):
    try:
        parsed = datetime.datetime.fromisoformat(value.replace("Z", "+00:00"))
    except (AttributeError, TypeError, ValueError, OverflowError):
        return None
    return parsed if parsed.year != 1 and parsed.tzinfo is not None else None


def interval(start, end):
    first, last = timestamp(start), timestamp(end)
    if first is not None and last is not None and first <= last:
        return first, last
    return None


def elapsed(start, end):
    measured = interval(start, end)
    return round((measured[1] - measured[0]).total_seconds(), 3) if measured else None


def post_work_tail(job_interval, work):
    if job_interval is None:
        return None
    if work.get("status") != "completed" or work.get("conclusion") == "skipped":
        return None
    work_interval = interval(work.get("startedAt"), work.get("completedAt"))
    if work_interval is None:
        return None
    job_start, job_end = job_interval
    work_start, work_end = work_interval
    if job_start <= work_start <= work_end <= job_end:
        return round((job_end - work_end).total_seconds(), 3)
    return None


def job_parallelism(terminal, intervals, skipped, unmeasurable):
    """Summarize concurrency across measured intervals of a completed run."""
    result = {
        "status": "run_incomplete",
        "measured_jobs": len(intervals),
        "skipped_jobs": skipped,
        "unmeasurable_jobs": unmeasurable,
        "job_span_seconds": None,
        "sum_job_runtime_seconds": None,
        "busy_seconds": None,
        "overlap_seconds": None,
        "peak_parallel_jobs": None,
        "average_parallel_jobs": None,
    }
    if not terminal:
        return result
    if not intervals:
        result["status"] = "no_measurable_jobs"
        return result

    first_start = min(start for start, _ in intervals)
    last_end = max(end for _, end in intervals)
    span = (last_end - first_start).total_seconds()
    runtime = sum((end - start).total_seconds() for start, end in intervals)

    # Group endpoints so a job ending as another starts adds no overlap.
    events = defaultdict(int)
    for start, end in intervals:
        events[start] += 1
        events[end] -= 1

    active = peak = 0
    busy = overlap = datetime.timedelta()
    previous = first_start
    for instant, change in sorted(events.items()):
        segment = instant - previous
        if active > 0:
            busy += segment
        if active > 1:
            overlap += segment
        active += change
        peak = max(peak, active)
        previous = instant

    result.update(
        status="partial" if unmeasurable else "measured",
        job_span_seconds=round(span, 3),
        sum_job_runtime_seconds=round(runtime, 3),
        busy_seconds=round(busy.total_seconds(), 3),
        overlap_seconds=round(overlap.total_seconds(), 3),
        peak_parallel_jobs=peak,
        average_parallel_jobs=round(runtime / span, 3) if span else None,
    )
    return result


def summarize(run):
    jobs = []
    intervals = []
    skipped = 0
    for job in run.get("jobs") or []:
        complete = job.get("status") == "completed"
        job_interval = interval(job.get("startedAt"), job.get("completedAt")) if complete else None
        duration = None
        if job_interval is not None:
            start, end = job_interval
            duration = round((end - start).total_seconds(), 3)
        job_skipped = job.get("conclusion") == "skipped"
        if job_skipped:
            skipped += 1
        elif job_interval is not None:
            intervals.append(job_interval)

        recorded_steps = job.get("steps") or []
        work_steps = [step for step in recorded_steps if step.get("name") in WORK_STEPS]
        steps = [
            {"name": step.get("name"), "conclusion": step.get("conclusion"),
             "seconds": elapsed(step.get("startedAt"), step.get("completedAt"))
             if step.get("status") == "completed" else None}
            for step in recorded_steps
        ]
        measured = [step for step in steps if step["seconds"] is not None]
        jobs.append({
            "name": job.get("name"), "status": job.get("status"),
            "conclusion": job.get("conclusion"), "seconds": duration,
            "setup_before_work_seconds": (
                elapsed(job.get("startedAt"), work_steps[0].get("startedAt")) if work_steps else None
            ),
            "post_work_tail_seconds": (
                post_work_tail(job_interval, work_steps[-1]) if work_steps and not job_skipped else None
            ),
            "longest_recorded_step": max(measured, key=lambda s: s["seconds"], default=None),
        })
    completed = [job for job in jobs if job["seconds"] is not None]
    terminal = run.get("status") == "completed"
    unmeasurable = len(jobs) - skipped - len(intervals)
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
        "job_parallelism": job_parallelism(terminal, intervals, skipped, unmeasurable),
    }


if __name__ == "__main__":
    print(json.dumps(summarize(json.load(sys.stdin)), indent=2, ensure_ascii=False))

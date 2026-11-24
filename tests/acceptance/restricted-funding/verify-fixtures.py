#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Check hand-calculated acceptance examples, not production behavior."""
from dataclasses import dataclass
from fractions import Fraction
import hashlib
import json
from pathlib import Path

@dataclass(frozen=True)
class Line:
    currency: str
    approved: int
    outstanding: int
    executed: int

    @property
    def available(self):
        return self.approved - self.outstanding - self.executed

    def settle(self, amount):
        assert 0 <= amount <= self.outstanding
        return Line(self.currency, self.approved, self.outstanding - amount,
                    self.executed + amount)

    def release(self, amount):
        assert 0 <= amount <= self.outstanding
        return Line(self.currency, self.approved, self.outstanding - amount,
                    self.executed)

    def amend(self, amount):
        assert amount >= self.outstanding + self.executed
        return Line(self.currency, amount, self.outstanding, self.executed)

    def reserve(self, amount):
        assert 0 <= amount <= self.available
        return Line(self.currency, self.approved, self.outstanding + amount,
                    self.executed)

a = Line("USD", 100_000, 45_000, 0)
b = Line("USD", 40_000, 15_000, 0)
own = Line("EUR", 30_000, 0, 0)
a, b = a.settle(18_000), b.settle(6_000)
assert (a.available, a.outstanding, a.executed) == (55_000, 27_000, 18_000)
assert (b.available, b.outstanding, b.executed) == (25_000, 9_000, 6_000)
assert own.available == 30_000
a, b = a.release(5_000), b.release(1_000)
assert (a.available, b.available) == (60_000, 26_000)
converted = Fraction(own.approved) * Fraction(6, 5)
assert converted == 36_000
assert a.approved + b.approved + converted == 176_000
assert own.currency == "EUR" and own.approved == 30_000

# Expected serialization outcome, not proof of actual database concurrency.
race = Line("USD", 100_000, 80_000, 0).reserve(15_000)
assert race.outstanding == 95_000 and race.available == 5_000
try:
    race.reserve(15_000)
except AssertionError:
    pass
else:
    raise AssertionError("second concurrent reservation would exceed ceiling")

amended = a.amend(110_000)
assert amended.available == 70_000 and a.available == 60_000
try:
    a.amend(30_000)
except AssertionError:
    pass
else:
    raise AssertionError("amendment below consumed capacity")

accepted, disallowed = 15_000, 3_000
assert accepted + disallowed == a.executed == 18_000
assert a.available == 60_000
payment = 24_000
assert a.executed + b.executed == payment
assert a.executed + b.executed + 100 > payment
print("PASS: hand-calculated multi-source, currency, settlement, reservation, amendment and review fixtures")


# Machine-readable future runtime scenarios. The assertions here validate
# the fixture's consistency only; they do not simulate permissions or
# certify any funding-domain implementation.
fixture = json.loads(Path(__file__).with_name("workflow-cases.json").read_text())
assert fixture["schema"] == "librefinance.restricted-funding.acceptance.v1"
assert fixture["qualification"] == "synthetic_expected_outcomes_only"
assert len({c["workspace"] for c in fixture["configurations"]}) == 2
assert len({c["locale"] for c in fixture["configurations"]}) == 2
assert len({c["functionalCurrency"] for c in fixture["configurations"]}) == 2
assert fixture["configurations"][0]["reviewStages"] != fixture["configurations"][1]["reviewStages"]
payment_fixture = fixture["payment"]
assert sum(payment_fixture["allocations"].values()) == payment_fixture["amountMinor"] == payment
line = payment_fixture["lineA"]
assert (line["approvedMinor"], line["outstandingMinor"], line["executedMinor"], line["availableMinor"]) == (a.approved, a.outstanding, a.executed, a.available)
assert payment_fixture["allocations"]["source-a"] == a.executed
assert payment_fixture["allocations"]["source-b"] == b.executed

evidence = fixture["evidence"]
for version in evidence["versions"].values():
    assert hashlib.sha256(version["content"].encode()).hexdigest() == version["sha256"]
assert evidence["approvedVersion"] == "v1"
assert evidence["versions"]["v1"]["sha256"] != evidence["versions"]["v2"]["sha256"]
assert fixture["publication"]["approved"]["evidenceSha256"] == evidence["versions"]["v1"]["sha256"]
for review in fixture["reviewVersions"]:
    assert review["acceptedMinor"] + review["disallowedMinor"] == a.executed
first, second = fixture["reviewVersions"]
assert second["supersedes"] == first["id"]
assert first["actor"] != second["actor"]
assert first["acceptedMinor"] == accepted and first["disallowedMinor"] == disallowed
assert second["acceptedMinor"] - first["acceptedMinor"] == 2000

publication = fixture["publication"]
allowed = set(publication["allowedFields"])
assert set(publication["expectedPublicFields"]) == allowed
assert allowed < set(publication["approved"])
assert {key: publication["approved"][key] for key in allowed} == publication["expectedPublicFields"]
assert {"recipientUid", "originalFileId", "evidenceSha256", "reviewNotes"}.isdisjoint(allowed)
cases = fixture["cases"]
assert len({case["id"] for case in cases}) == len(cases) == 15
assert all(case["actor"] and case["workspace"] and case["action"] and case["condition"] for case in cases)
assert all(case["expected"] in {"allowed", "denied", "redacted"} for case in cases)
assert all(case["ledgerDeltaMinor"] == 0 for case in cases)
print("PASS: 15 explicit workflow expectation records; fixture arithmetic, versions, digests and redaction consistency only")

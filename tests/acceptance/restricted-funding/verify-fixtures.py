#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Check hand-calculated acceptance examples, not production behavior."""
from dataclasses import dataclass
from fractions import Fraction

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

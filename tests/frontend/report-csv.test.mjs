/* SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors */
/* SPDX-License-Identifier: AGPL-3.0-or-later */

import test from 'node:test';
import assert from 'node:assert/strict';
import { cashFlowCsv } from '../../src/reportCsv.js';

const validBaseResult = {
  currency: 'USD',
  bankAccountId: 101,
  from: '2026-01-01',
  through: '2026-03-31',
  basis: 'realized_bank_postings',
  isForecast: false,
  openingMinor: 1000,
  netMinor: 500,
  closingMinor: 1500,
  months: [
    { month: '2026-01', netMinor: 200 },
    { month: '2026-02', netMinor: 300 },
    { month: '2026-03', netMinor: 0 }
  ]
};

test('hand-computed positive/negative/zero monthly rows and selected account', () => {
  const result = {
    ...validBaseResult,
    openingMinor: -100,
    netMinor: 50,
    closingMinor: -50,
    months: [
      { month: '2026-01', netMinor: -20 },
      { month: '2026-02', netMinor: 70 },
      { month: '2026-03', netMinor: 0 }
    ]
  };

  const csv = cashFlowCsv(result);
  const lines = csv.split('\r\n');
  assert.equal(lines[0], 'currency,bankAccountId,from,through,metric,month,amountMinor');
  assert.equal(lines[1], 'USD,101,2026-01-01,2026-03-31,opening,,-100');
  assert.equal(lines[2], 'USD,101,2026-01-01,2026-03-31,net,,50');
  assert.equal(lines[3], 'USD,101,2026-01-01,2026-03-31,closing,,-50');
  assert.equal(lines[4], 'USD,101,2026-01-01,2026-03-31,monthly_net,2026-01,-20');
  assert.equal(lines[5], 'USD,101,2026-01-01,2026-03-31,monthly_net,2026-02,70');
  assert.equal(lines[6], 'USD,101,2026-01-01,2026-03-31,monthly_net,2026-03,0');
  assert.equal(lines[7], '');
});

test('empty months net zero', () => {
  const result = {
    ...validBaseResult,
    openingMinor: 500,
    netMinor: 0,
    closingMinor: 500,
    months: []
  };
  const csv = cashFlowCsv(result);
  assert.ok(csv.endsWith('\r\n'));
});

test('malformed currency', () => {
  const result = { ...validBaseResult, currency: 'US' };
  assert.throws(() => cashFlowCsv(result), /Invalid currency ISO code/);
});

test('malformed date', () => {
  const result = { ...validBaseResult, from: '2026/01/01' };
  assert.throws(() => cashFlowCsv(result), /Invalid canonical date format/);
});

test('malformed month', () => {
  const result = {
    ...validBaseResult,
    months: [{ month: '2026/01', netMinor: 0 }]
  };
  assert.throws(() => cashFlowCsv(result), /Invalid month format/);
});

test('duplicate or unordered months', () => {
  const result1 = {
    ...validBaseResult,
    months: [
      { month: '2026-02', netMinor: 100 },
      { month: '2026-01', netMinor: 400 }
    ]
  };
  assert.throws(() => cashFlowCsv(result1), /Months must be unique and strictly ascending/);

  const result2 = {
    ...validBaseResult,
    months: [
      { month: '2026-01', netMinor: 100 },
      { month: '2026-01', netMinor: 400 }
    ]
  };
  assert.throws(() => cashFlowCsv(result2), /Months must be unique and strictly ascending/);
});

test('noninteger and mismatched totals', () => {
  const resultBadOpening = { ...validBaseResult, openingMinor: 100.5 };
  assert.throws(() => cashFlowCsv(resultBadOpening), /Invalid safe integer minor units/);

  const resultMismatch = { ...validBaseResult, openingMinor: 1000, netMinor: 500, closingMinor: 2000 };
  assert.throws(() => cashFlowCsv(resultMismatch), /Opening plus net must equal closing/);

  const resultNetMismatch = {
    ...validBaseResult,
    netMinor: 500,
    months: [
      { month: '2026-01', netMinor: 100 },
      { month: '2026-02', netMinor: 100 }
    ]
  };
  assert.throws(() => cashFlowCsv(resultNetMismatch), /Sum of monthly net must equal total net/);
});

test('reject impossible and reversed reporting dates and out-of-period months', () => {
  for (const from of ['0000-01-01', '2026-02-30', '2026-01-01\n', '2026-04-01']) {
    assert.throws(() => cashFlowCsv({ ...validBaseResult, from }));
  }
  for (const month of ['2026-00', '2026-13', '2025-12', '2026-04']) {
    assert.throws(() => cashFlowCsv({ ...validBaseResult, months: [{ month, netMinor: 500 }] }));
  }
  assert.throws(() => cashFlowCsv({ ...validBaseResult, bankAccountId: Number.MAX_SAFE_INTEGER + 1 }));
  assert.throws(() => cashFlowCsv({ ...validBaseResult, currency: 'USD\n' }));
});

// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import test from 'node:test'
import assert from 'node:assert/strict'
import { previewMinorUnits } from '../../src/exchangeRateSelection.js'

test('signed historical previews use exact stored precision, including zero', () => {
  for (const [value, precision, expected] of [
    ['1000', 0, 1000], ['-1000', 0, -1000], ['0', 0, 0],
    ['-0,000', 3, 0], ['0.01', 2, 1], ['-0,001', 3, -1],
    ['12,345', 3, 12345], [' 7.2 ', 2, 720],
    ['9000000000000', 0, 9000000000000],
    ['-9000000000.000', 3, -9000000000000],
  ]) assert.equal(previewMinorUnits(value, precision), expected, value)
})

test('invalid, overflowing or over-precise previews cannot send a rounded amount', () => {
  for (const [value, precision] of [
    ['', 2], ['-', 2], ['1e3', 2], ['1.234,56', 2], ['+1', 2],
    ['1.0', 0], ['0.000', 2], ['1.2345', 3], ['0.001', 2],
    ['9000000000001', 0], ['9000000000.001', 3],
    ['1', null], ['1', '2'], ['1', -1], ['1', 4],
  ]) assert.equal(previewMinorUnits(value, precision), null, value)
})

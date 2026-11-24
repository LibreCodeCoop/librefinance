// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import test from 'node:test'
import assert from 'node:assert/strict'
import { formatLedgerDate } from '../../src/date.js'

test('calendar dates are localized without shifting the financial day', () => {
  for (const tz of ['Pacific/Honolulu', 'America/Sao_Paulo', 'Pacific/Kiritimati']) {
    const previous = process.env.TZ
    try {
      process.env.TZ = tz
      assert.equal(formatLedgerDate('2024-02-29', 'pt_BR'), '29/02/2024')
      assert.equal(formatLedgerDate('2024-02-29', 'en_US'), '02/29/2024')
      assert.equal(formatLedgerDate('2026-01-01', 'pt_BR'), '01/01/2026')
    } finally {
      if (previous === undefined) delete process.env.TZ
      else process.env.TZ = previous
    }
  }
})
test('missing and invalid dates are never silently normalized', () => {
  assert.equal(formatLedgerDate(null, 'pt_BR'), '')
  assert.equal(formatLedgerDate('2026-02-30', 'pt_BR'), '2026-02-30')
  assert.equal(formatLedgerDate('2026-13-01', 'pt_BR'), '2026-13-01')
  assert.equal(formatLedgerDate('2026-01-01T23:00:00Z', 'pt_BR'), '2026-01-01T23:00:00Z')
})

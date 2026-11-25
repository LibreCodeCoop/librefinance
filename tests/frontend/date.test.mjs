// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import test from 'node:test'
import assert from 'node:assert/strict'
import { spawn } from 'node:child_process'
import { once } from 'node:events'
import { canonicalCalendarDate, formatLedgerDate, parseNativeCalendarDate } from '../../src/date.js'

const dateModule = new URL('../../src/date.js', import.meta.url).href
const validDates = ['0001-01-01', '0096-02-29', '0099-12-31', '1900-02-28', '2000-02-29',
  '2011-12-30', '2018-11-04', '2024-02-29', '9999-12-31']

for (const timezone of ['America/New_York', 'America/Sao_Paulo', 'Pacific/Kiritimati', 'Pacific/Apia']) {
  test(`native date field round trips canonical dates in ${timezone}`, async () => {
    const script = `
      import assert from 'node:assert/strict'
      import { canonicalCalendarDate, formatLedgerDate, parseNativeCalendarDate } from ${JSON.stringify(dateModule)}
      assert.equal(Intl.DateTimeFormat().resolvedOptions().timeZone, ${JSON.stringify(timezone)})
      for (const iso of ${JSON.stringify(validDates)}) {
        const date = parseNativeCalendarDate(iso)
        assert.ok(date instanceof Date, iso)
        // Match the native picker's date-mode serialization.
        const inputValue = [
          String(date.getFullYear()).padStart(4, '0'),
          String(date.getMonth() + 1).padStart(2, '0'),
          String(date.getDate()).padStart(2, '0'),
        ].join('-')
        assert.equal(inputValue, iso)
        assert.equal(canonicalCalendarDate(inputValue), iso)
      }
      assert.equal(formatLedgerDate('2024-02-29', 'pt_BR'), '29/02/2024')
      assert.equal(formatLedgerDate('2024-02-29', 'en_US'), '02/29/2024')
      assert.equal(formatLedgerDate('2026-01-01', 'pt_BR'), '01/01/2026')
    `
    const child = spawn(process.execPath, ['--input-type=module', '--eval', script], {
      env: { ...process.env, TZ: timezone },
      stdio: 'inherit',
    })
    const [code, signal] = await once(child, 'exit')
    assert.equal(code, 0, signal ?? 'timezone subprocess failed')
  })
}

test('Arabic RTL presentation leaves the native model and accounting fact canonical', () => {
  const iso = '2024-02-29'
  const presented = formatLedgerDate(iso, 'ar_EG')
  assert.equal(presented.replaceAll('\u200f', ''), '٢٩/٠٢/٢٠٢٤')
  assert.equal(canonicalCalendarDate(presented), '')
  assert.equal(canonicalCalendarDate(iso), iso)
  assert.equal(parseNativeCalendarDate(iso).toISOString().slice(0, 10), iso)
})

test('missing and invalid dates are never silently normalized', () => {
  for (const invalid of ['', null, 20240229, '0000-01-01', '0099-02-29', '1900-02-29',
    '2026-02-30', '2026-04-31', '2026-00-01', '2026-13-01', '2026-01-00',
    '2026-1-01', '2026-01-01\n', '10000-01-01', '2026-01-01T23:00:00Z']) {
    assert.equal(parseNativeCalendarDate(invalid), null, JSON.stringify(invalid))
    assert.equal(canonicalCalendarDate(invalid), '', JSON.stringify(invalid))
  }
  assert.equal(formatLedgerDate(null, 'pt_BR'), '')
  assert.equal(formatLedgerDate('2026-02-30', 'pt_BR'), '2026-02-30')
  assert.equal(formatLedgerDate('2026-13-01', 'pt_BR'), '2026-13-01')
  assert.equal(formatLedgerDate('2026-01-01T23:00:00Z', 'pt_BR'), '2026-01-01T23:00:00Z')
})

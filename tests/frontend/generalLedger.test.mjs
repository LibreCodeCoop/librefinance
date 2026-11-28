// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import test from 'node:test'
import assert from 'node:assert/strict'
import { createGeneralLedgerExport, formatGeneralLedgerAmount } from '../../src/generalLedger.js'

test('ledger formatting retains all digits without binary floating point', () => {
  assert.equal(formatGeneralLedgerAmount('9223372036854775807', 'BRL', 2, 'pt_BR'), 'R$\u00a092.233.720.368.547.758,07')
  assert.equal(formatGeneralLedgerAmount('-1', 'BRL', 2, 'en_US'), '-R$0.01')
  assert.equal(formatGeneralLedgerAmount('-9007199254740993', 'BRL', 2, 'en_US'), '-R$90,071,992,547,409.93')
  assert.throws(() => formatGeneralLedgerAmount(9007199254740993, 'BRL', 2))
})

const report = {
  basis: 'posted_debit_positive', account: { id: 10 }, currency: 'BRL', currencyPrecision: 2,
  from: '2026-01-01', through: '2026-01-31', snapshot: 'a'.repeat(64), rowCount: 2,
  openingMinor: '9007199254740993', closingMinor: '9007199254740994',
}
const firstRow = { postingId: 1, journalId: 1, reversedJournalId: null, date: '2026-01-01',
  currency: 'BRL', memo: '=HYPERLINK("x")', amountMinor: '3', debitMinor: '3', creditMinor: '0', runningMinor: '9007199254740996' }
const lastRow = { postingId: 2, journalId: 2, reversedJournalId: 1, date: '2026-01-01',
  currency: 'BRL', memo: 'Reversal, quoted "text"', amountMinor: '-2', debitMinor: '0', creditMinor: '2', runningMinor: '9007199254740994' }
const first = { ...report, rows: [firstRow], pageOpeningMinor: report.openingMinor,
  nextCursor: { afterDate: firstRow.date, afterId: firstRow.postingId } }
const last = { ...report, rows: [lastRow], pageOpeningMinor: firstRow.runningMinor, nextCursor: null }

test('complete exact export has opening and closing records, escapes formulas and preserves reversal', () => {
  const csv = createGeneralLedgerExport(report)
  csv.append(first)
  assert.throws(() => csv.finish(), /Incomplete/)
  csv.append(last)
  const lines = csv.finish()
  assert.equal(lines.length, 5)
  assert.equal(lines[0].trim().split(',').length, 16)
  assert.equal(lines[1].trim().split(',').length, 16)
  assert.equal(lines[4].trim().split(',').length, 16)
  assert.ok(lines[1].endsWith(',9007199254740993\r\n'))
  assert.ok(lines[2].includes(',"\'=HYPERLINK(""x"")",3,3,0,9007199254740996'))
  assert.ok(lines[3].includes(',2,2,1,"Reversal, quoted ""text""",-2,0,2,9007199254740994'))
  assert.ok(lines[4].endsWith(',9007199254740994\r\n'))
})

test('export refuses truncated, changed and misordered data', () => {
  const incomplete = createGeneralLedgerExport(report)
  assert.throws(() => incomplete.append({ ...first, nextCursor: null }), /Incomplete/)
  const changed = createGeneralLedgerExport(report)
  changed.append(first)
  assert.throws(() => changed.append({ ...last, snapshot: 'b'.repeat(64) }), /snapshot/)
  const misordered = createGeneralLedgerExport(report)
  misordered.append(first)
  assert.throws(() => misordered.append({ ...last, rows: [{ ...lastRow, postingId: 1 }] }), /order/)
})

test('empty period exports matching opening and closing with no invented rows', () => {
  const empty = { ...report, rowCount: 0, closingMinor: report.openingMinor, rows: [], pageOpeningMinor: report.openingMinor, nextCursor: null }
  const csv = createGeneralLedgerExport(empty)
  csv.append(empty)
  assert.equal(csv.finish().length, 3)
})

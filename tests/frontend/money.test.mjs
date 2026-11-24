// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import test from 'node:test'
import assert from 'node:assert/strict'
import { formatLedgerMoney, ledgerLocale, toMinorUnits } from '../../src/money.js'

test('exact conversion of common Brazilian amounts', () => {
  assert.equal(toMinorUnits('0,01'), 1)
  assert.equal(toMinorUnits('123,45'), 12345)
  assert.equal(toMinorUnits('1000.99'), 100099)
  assert.equal(toMinorUnits('5'), 500)
  assert.equal(toMinorUnits('7.2'), 720)
})

test('never guesses separators or rounds malformed money', () => {
  for (const sample of ['', ' ', '1.999', '1.234,56', '12,345', '-1', '1e8', 'NaN', 'R$ 5', '0']) {
    assert.throws(() => toMinorUnits(sample), Error, sample)
  }
})

test('rejects values larger than the core ledger maximum', () => {
  assert.throws(() => toMinorUnits('90000000000.01'), Error)
})


test('platform locale changes only presentation, not currency or minor units', () => {
  const amount = toMinorUnits('1234.56')
  assert.equal(amount, 123456)
  assert.equal(ledgerLocale('pt_BR'), 'pt-BR')
  assert.equal(ledgerLocale('en_US'), 'en-US')
  assert.equal(ledgerLocale('invalid_locale_!'), 'pt-BR')
  assert.equal(formatLedgerMoney(amount, 'BRL', 'pt_BR'), 'R$\u00a01.234,56')
  assert.equal(formatLedgerMoney(amount, 'BRL', 'en_US'), 'R$1,234.56')
  assert.equal(formatLedgerMoney(-1, 'BRL', 'en_US'), '-R$0.01')
  assert.equal(toMinorUnits('1234.56'), amount)
})

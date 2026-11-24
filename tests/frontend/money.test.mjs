// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import test from 'node:test'
import assert from 'node:assert/strict'
import { t } from '@nextcloud/l10n'
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

test('parses opt-in precisions from decimal digits', () => {
  assert.equal(toMinorUnits(' 1234 ', 0), 1234)
  assert.equal(toMinorUnits('7,2', 1), 72)
  assert.equal(toMinorUnits('7.2', 2), 720)
  assert.equal(toMinorUnits('7.2', 3), 7200)
  assert.equal(toMinorUnits('0,001', 3), 1)
  assert.equal(toMinorUnits('12.345', 3), 12345)
})

test('rejects invalid amounts with the existing translated errors', () => {
  const limitsError = { message: t('librefinance', 'Amount outside the permitted limits.') }
  assert.throws(() => toMinorUnits('1.234'), {
    message: t('librefinance', 'Enter a valid amount with up to two decimal places.'),
  })
  assert.throws(() => toMinorUnits('0'), limitsError)
  assert.throws(() => toMinorUnits('1.0', 0), limitsError)
  assert.throws(() => toMinorUnits('1.20', 1), limitsError)
  for (const sample of ['', '1.234,567', '1,234.567', '1 234', '1e3', '-1', '+1', '.1', '1.', '0.000', '1.2340']) {
    assert.throws(() => toMinorUnits(sample, 3), limitsError, sample)
  }
})

test('enforces the same minor-unit limit at each precision', () => {
  const limitsError = { message: t('librefinance', 'Amount outside the permitted limits.') }
  for (const [precision, maximum, overflow] of [
    [0, '9000000000000', '9000000000001'],
    [1, '900000000000.0', '900000000000.1'],
    [2, '90000000000.00', '90000000000.01'],
    [3, '9000000000.000', '9000000000.001'],
  ]) {
    assert.equal(toMinorUnits(maximum, precision), 9000000000000)
    assert.throws(() => toMinorUnits(overflow, precision), limitsError)
  }
  assert.equal(toMinorUnits('8999999999.999', 3), 8999999999999)
})

test('both helpers reject invalid precisions', () => {
  const limitsError = { message: t('librefinance', 'Amount outside the permitted limits.') }
  for (const precision of [-1, 4, 1.5, NaN, Infinity, '2', null]) {
    assert.throws(() => toMinorUnits('1', precision), limitsError, String(precision))
    assert.throws(() => formatLedgerMoney(1, 'BRL', 'en_US', precision), limitsError, String(precision))
  }
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

test('display precision is fixed by the argument independently of currency and locale', () => {
  assert.equal(formatLedgerMoney(1234, 'JPY', 'en_US', 0), '¥1,234')
  assert.equal(formatLedgerMoney(1234, 'JPY', 'en_US'), '¥12.34')
  assert.equal(formatLedgerMoney(1234, 'BRL', 'en_US', 1), 'R$123.4')
  assert.equal(formatLedgerMoney(-1234567, 'KWD', 'en_US', 3), '-KWD\u00a01,234.567')
  assert.equal(formatLedgerMoney(-1234567, 'KWD', 'pt_BR', 3), '-KWD\u00a01.234,567')
  assert.equal(formatLedgerMoney(1000, 'KWD', 'en_US', 3), 'KWD\u00a01.000')
  assert.equal(formatLedgerMoney(0, 'KWD', 'en_US', 3), 'KWD\u00a00.000')
})

test('displays signed amounts near the ledger limit at the chosen precision', () => {
  assert.equal(formatLedgerMoney(-9000000000000, 'JPY', 'en_US', 0), '-¥9,000,000,000,000')
  assert.equal(formatLedgerMoney('8999999999999', 'BRL', 'en_US'), 'R$89,999,999,999.99')
  assert.equal(formatLedgerMoney(-8999999999999, 'KWD', 'en_US', 3), '-KWD\u00a08,999,999,999.999')
})

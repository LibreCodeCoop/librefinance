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

test('workspace currency metadata controls exact parsing and signed display', async () => {
  const { resolveMoneyContext, parseContextMoney, formatContextMoney, formatContextInput } = await import('../../src/money.js')
  const jpy = { currency: 'JPY', currencyPrecision: 0, currencyMetadataVersion: 1 }
  const kwd = { currency: 'KWD', currencyPrecision: 3, currencyMetadataVersion: 1 }
  const brl = { currency: 'BRL', currencyPrecision: 2, currencyMetadataVersion: 1 }
  assert.deepEqual(resolveMoneyContext(jpy), { currency: 'JPY', precision: 0 })
  assert.deepEqual(resolveMoneyContext(kwd), { currency: 'KWD', precision: 3 })
  assert.equal(parseContextMoney('1000', jpy), 1000)
  assert.equal(parseContextMoney('1,001', kwd), 1001)
  assert.equal(parseContextMoney('12,34', brl), 1234)
  assert.equal(parseContextMoney('12,34', { currency: 'BRL' }), 1234)
  assert.equal(formatContextMoney(-1234, jpy, undefined, 'en_US'), '-¥1,234')
  assert.equal(formatContextMoney(-1234, kwd, undefined, 'en_US'), '-KWD\u00a01.234')
  assert.equal(formatContextMoney(1234, brl, undefined, 'pt_BR'), 'R$\u00a012,34')
  assert.equal(formatContextInput(1234, jpy, undefined, 'pt_BR'), '1234')
  assert.equal(formatContextInput(1234, kwd, undefined, 'pt_BR'), '1,234')
  assert.equal(formatContextInput(1234, kwd, undefined, 'en_US'), '1.234')
  assert.equal(formatContextInput(0, brl, undefined, 'pt_BR'), '0,00')
  assert.equal(parseContextMoney('1.001', kwd, [{ currency: 'KWD' }]), 1001)
  assert.equal(parseContextMoney('0,000', kwd, [], { allowZero: true }), 0)
  assert.equal(parseContextMoney('0', jpy, [], { allowZero: true }), 0)
  assert.throws(() => parseContextMoney('0', jpy), Error)
  assert.throws(() => parseContextMoney('1.2', jpy), Error)
  assert.throws(() => parseContextMoney('9000000000.001', kwd), Error)
  assert.throws(() => parseContextMoney('1', kwd, [jpy]), Error)
  assert.throws(() => parseContextMoney('1', kwd, [{ ...kwd, currencyPrecision: 2 }]), Error)
  assert.equal(formatContextMoney(1234, kwd, jpy, 'en_US'), '¥1,234')
})

test('missing or contradictory non-BRL metadata never guesses scale or permits a submission', async () => {
  const { resolveMoneyContext, parseContextMoney, formatContextMoney, formatContextInput } = await import('../../src/money.js')
  for (const workspace of [
    null, {}, { currency: 'JPY' }, { currency: 'KWD', currencyPrecision: 3 },
    { currency: 'XXX', currencyPrecision: null, currencyMetadataVersion: 1 },
    { currency: 'JPY', currencyPrecision: 0, currencyMetadataVersion: 0 },
    { currency: 'BRL', currencyPrecision: null, currencyMetadataVersion: 1 },
    { currency: 'BRL', currencyPrecision: 3, currencyMetadataVersion: 1 },
    { currency: 'JPY', currencyPrecision: '0', currencyMetadataVersion: 1 },
  ]) {
    assert.equal(resolveMoneyContext(workspace), null, JSON.stringify(workspace))
    assert.throws(() => parseContextMoney('1', workspace), Error)
    assert.throws(() => parseContextMoney('0', workspace, [], { allowZero: true }), Error)
    assert.equal(formatContextInput(1234, workspace), '')
    assert.equal(formatContextMoney(1234, workspace), t('librefinance', 'Unavailable'))
  }
  const brl = { currency: 'BRL' }
  assert.equal(resolveMoneyContext(brl, { currency: 'JPY' }), null)
  assert.throws(() => parseContextMoney('12.34', brl, [{ currency: 'JPY' }]), Error)
  assert.equal(resolveMoneyContext(brl, { currency: 'BRL', currencyPrecision: null, currencyMetadataVersion: 1 }), null)
})

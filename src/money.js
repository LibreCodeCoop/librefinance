// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { getLocale, t } from '@nextcloud/l10n'

const MAX_MINOR = 9000000000000

function limitsError() {
  return new Error(t('librefinance', 'Amount outside the permitted limits.'))
}

function validatePrecision(precision) {
  if (!Number.isInteger(precision) || precision < 0 || precision > 3) {
    throw limitsError()
  }
}

/** Convert a positive decimal amount to exact minor units at the given precision. */
export function toMinorUnits(value, precision = 2) {
  validatePrecision(precision)
  const match = /^(\d{1,13})(?:[.,](\d{1,3}))?$/.exec(String(value).trim())
  const [, units, fraction = ''] = match ?? []
  // Scale zero needs 13 whole digits to reach MAX_MINOR. Base-100 input keeps its 12-digit limit.
  const maxWholeDigits = precision === 0 ? 13 : 12
  if (!match || units.length > maxWholeDigits || fraction.length > precision) {
    if (precision === 2) {
      throw new Error(t('librefinance', 'Enter a valid amount with up to two decimal places.'))
    }
    throw limitsError()
  }
  // At most 15 digits, so converting the assembled integer to Number is exact.
  const amount = Number(units + fraction.padEnd(precision, '0'))
  if (amount === 0 || amount > MAX_MINOR) {
    throw limitsError()
  }
  return amount
}


/** Nextcloud locale, independent of the selected language and stored currency. */
export function ledgerLocale(locale = getLocale()) {
  try {
    return Intl.getCanonicalLocales(locale?.replaceAll('_', '-') || 'pt-BR')[0]
  } catch {
    return 'pt-BR'
  }
}

/** Present signed minor units at the supplied precision, which defaults to base 100. */
export function formatLedgerMoney(minor, currency = 'BRL', locale = getLocale(), precision = 2) {
  validatePrecision(precision)
  const scale = 10 ** precision
  // Within ±MAX_MINOR the integer converts exactly, and division error is less
  // than half a minor unit, so formatting to this precision recovers every digit.
  return new Intl.NumberFormat(ledgerLocale(locale), {
    style: 'currency',
    currency,
    minimumFractionDigits: precision,
    maximumFractionDigits: precision,
  }).format(Number(minor) / scale)
}

/**
 * Resolve the server-owned scale. A record without its own metadata may use
 * the current workspace's scale only when it belongs to that same currency.
 * Legacy BRL without either metadata field retains its existing scale.
 */
export function resolveMoneyContext(workspace, record = workspace) {
  if (!record || typeof record !== 'object') return null
  const explicit = Object.hasOwn(record, 'currencyPrecision') || Object.hasOwn(record, 'currencyMetadataVersion')
  const currency = record.currency ?? (!explicit ? workspace?.currency : null)
  if (typeof currency !== 'string' || !/^[A-Z]{3}$/.test(currency)) return null
  if (explicit) {
    const precision = record.currencyPrecision
    if (!Number.isInteger(precision) || precision < 0 || precision > 3
      || (currency === 'BRL' && precision !== 2)
      || !Number.isSafeInteger(record.currencyMetadataVersion) || record.currencyMetadataVersion < 1) return null
    return { currency, precision }
  }
  if (record !== workspace && currency === workspace?.currency) return resolveMoneyContext(workspace)
  return currency === 'BRL' ? { currency, precision: 2 } : null
}

/** Existing posting flows use one functional currency, never an implicit conversion. */
export function parseContextMoney(value, workspace, records = [], { allowZero = false } = {}) {
  const context = resolveMoneyContext(workspace)
  if (!context || records.some(record => {
    if (record == null) return false
    const selected = resolveMoneyContext(workspace, record)
    return !selected || selected.currency !== context.currency || selected.precision !== context.precision
  })) throw limitsError()
  const input = String(value).trim()
  if (allowZero && /^0(?:[.,]0{1,3})?$/.test(input)
    && (input.split(/[.,]/)[1]?.length ?? 0) <= context.precision) return 0
  return toMinorUnits(input, context.precision)
}

function exactInteger(minor) {
  if (typeof minor !== 'number' && !(typeof minor === 'string' && /^-?\d+$/.test(minor))) return null
  const value = Number(minor)
  return Number.isSafeInteger(value) ? value : null
}

export function formatContextMoney(minor, workspace, record = workspace, locale = getLocale()) {
  const context = resolveMoneyContext(workspace, record)
  const value = exactInteger(minor)
  return context && value !== null
    ? formatLedgerMoney(value, context.currency, locale, context.precision)
    : t('librefinance', 'Unavailable')
}

/** Rehydrate editable decimal text from exact stored minor units. */
export function formatContextInput(minor, workspace, record = workspace, locale = getLocale()) {
  const context = resolveMoneyContext(workspace, record)
  const value = exactInteger(minor)
  if (!context || value === null || Math.abs(value) > MAX_MINOR) return ''
  const magnitude = Math.abs(value)
  const scale = 10 ** context.precision
  const sign = value < 0 ? '-' : ''
  const whole = String(Math.floor(magnitude / scale))
  if (context.precision === 0) return sign + whole
  const decimal = new Intl.NumberFormat(ledgerLocale(locale), { useGrouping: false }).formatToParts(1.1)
    .find(part => part.type === 'decimal')?.value
  return sign + whole + (decimal === ',' ? ',' : '.')
    + String(magnitude % scale).padStart(context.precision, '0')
}

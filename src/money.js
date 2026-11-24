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

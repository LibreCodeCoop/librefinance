// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { ledgerLocale } from './money.js'

/** Present a calendar date without changing its day in another timezone. */
export function formatLedgerDate(value, locale) {
  if (typeof value !== 'string') return ''
  if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) return value
  const date = new Date(value + 'T00:00:00Z')
  if (!Number.isFinite(date.getTime()) || date.toISOString().slice(0, 10) !== value) return value
  return new Intl.DateTimeFormat(ledgerLocale(locale), {
    year: 'numeric', month: '2-digit', day: '2-digit', timeZone: 'UTC',
  }).format(date)
}

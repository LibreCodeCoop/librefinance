// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { ledgerLocale } from './money.js'

function parseCalendarDate(value) {
  if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(value) || value.startsWith('0000-')) return null
  const date = new Date(value + 'T00:00:00Z')
  return Number.isFinite(date.getTime()) && date.toISOString().slice(0, 10) === value ? date : null
}

/** The native date input supplies its Gregorian value as an ISO string. */
export function canonicalCalendarDate(value) {
  return parseCalendarDate(value) ? value : ''
}

// NcDateTimePickerNative reads local getters to render its Date model.
// Expose the UTC fields there so even a day skipped locally can be displayed.
class NativeCalendarDate extends Date {
  getFullYear() { return this.getUTCFullYear() }
  getMonth() { return this.getUTCMonth() }
  getDate() { return this.getUTCDate() }
}

export function parseNativeCalendarDate(value) {
  const date = parseCalendarDate(value)
  return date ? new NativeCalendarDate(date.getTime()) : null
}

/** Present a calendar date without changing its day in another timezone. */
export function formatLedgerDate(value, locale) {
  if (typeof value !== 'string') return ''
  const date = parseCalendarDate(value)
  if (!date) return value
  return new Intl.DateTimeFormat(ledgerLocale(locale), {
    year: 'numeric', month: '2-digit', day: '2-digit', timeZone: 'UTC',
  }).format(date)
}

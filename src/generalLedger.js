// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { ledgerLocale } from './money.js'

function minor(value) {
  if (typeof value !== 'string' || !/^-?(?:0|[1-9][0-9]*)$/.test(value)) {
    throw new Error('Invalid exact minor units')
  }
  return BigInt(value)
}

/** Preserve every digit, even when an aggregate exceeds Number.MAX_SAFE_INTEGER. */
export function formatGeneralLedgerAmount(value, currency, precision, locale) {
  const amount = minor(value)
  if (!Number.isInteger(precision) || precision < 0 || precision > 3) throw new Error('Invalid precision')
  const scale = 10n ** BigInt(precision)
  const absolute = amount < 0n ? -amount : amount
  const whole = absolute / scale
  const remainder = absolute % scale
  const formatter = new Intl.NumberFormat(ledgerLocale(locale), {
    style: 'currency', currency, minimumFractionDigits: precision, maximumFractionDigits: precision,
  })
  const fraction = new Intl.NumberFormat(ledgerLocale(locale), {
    minimumIntegerDigits: Math.max(1, precision), useGrouping: false, maximumFractionDigits: 0,
  }).format(remainder)
  const signedWhole = amount < 0n ? (whole === 0n ? -0 : -whole) : whole
  return formatter.formatToParts(signedWhole).map(part => part.type === 'fraction' ? fraction : part.value).join('')
}

// RFC 4180 quoting plus formula neutralization for human-entered text.
// Integer columns are canonical exact numeric strings, never arbitrary input.
function textCell(value) {
  const text = String(value ?? '')
  const safe = /^[\s\u0000-\u001f]*[=+\-@]/u.test(text) || /^[\t\r\n]/.test(text) ? "'" + text : text
  return '"' + safe.replaceAll('"', '""') + '"'
}

/** Incremental CSV builder. A download is possible only for the complete, reconciled snapshot. */
export function createGeneralLedgerExport(report) {
  if (report.basis !== 'posted_debit_positive' || !Number.isSafeInteger(report.rowCount) || report.rowCount < 0
    || !/^[a-f0-9]{64}$/.test(report.snapshot) || !/^[A-Z]{3}$/.test(report.currency)) {
    throw new Error('Invalid ledger report')
  }
  const opening = minor(report.openingMinor)
  const closing = minor(report.closingMinor)
  let running = opening
  let count = 0
  let last = null
  let completed = false
  const lines = ['accountId,currency,currencyPrecision,from,through,basis,metric,date,postingId,journalId,reversedJournalId,memo,amountMinor,debitMinor,creditMinor,runningMinor\r\n']
  const meta = [report.account.id, report.currency, report.currencyPrecision, report.from, report.through, report.basis].join(',')
  lines.push([meta, 'opening', '', '', '', '', '', '', '', '', String(opening)].join(',') + '\r\n')
  function append(page) {
    if (completed || page.snapshot !== report.snapshot || page.rowCount !== report.rowCount
      || page.account.id !== report.account.id || page.currency !== report.currency
      || page.currencyPrecision !== report.currencyPrecision || page.from !== report.from || page.through !== report.through
      || page.basis !== report.basis || minor(page.openingMinor) !== opening || minor(page.closingMinor) !== closing
      || minor(page.pageOpeningMinor) !== running || !Array.isArray(page.rows)) throw new Error('Ledger snapshot changed')
    for (const row of page.rows) {
      if (!Number.isSafeInteger(row.postingId) || row.postingId < 1
        || !Number.isSafeInteger(row.journalId) || row.journalId < 1
        || (row.reversedJournalId !== null && (!Number.isSafeInteger(row.reversedJournalId) || row.reversedJournalId < 1))
        || row.currency !== report.currency || row.date < report.from || row.date > report.through
        || !/^\d{4}-\d{2}-\d{2}$/.test(row.date)
        || (last && (row.date < last.date || (row.date === last.date && row.postingId <= last.postingId)))) {
        throw new Error('Invalid ledger row order')
      }
      const amount = minor(row.amountMinor)
      if (minor(row.debitMinor) !== (amount > 0n ? amount : 0n)
        || minor(row.creditMinor) !== (amount < 0n ? -amount : 0n)) throw new Error('Invalid debit or credit')
      running += amount
      if (minor(row.runningMinor) !== running) throw new Error('Invalid running balance')
      lines.push([meta, 'posting', row.date, row.postingId, row.journalId, row.reversedJournalId ?? '',
        textCell(row.memo), row.amountMinor, row.debitMinor, row.creditMinor, row.runningMinor].join(',') + '\r\n')
      count++
      last = row
    }
    if (count > report.rowCount) throw new Error('Too many ledger rows')
    if (page.nextCursor === null) {
      if (count !== report.rowCount || running !== closing) throw new Error('Incomplete ledger export')
      completed = true
      lines.push([meta, 'closing', '', '', '', '', '', '', '', '', String(closing)].join(',') + '\r\n')
    } else if (!page.rows.length || page.nextCursor.afterId !== last.postingId
      || page.nextCursor.afterDate !== last.date || count >= report.rowCount) {
      throw new Error('Invalid ledger continuation')
    }
  }
  return {
    append,
    finish() {
      if (!completed) throw new Error('Incomplete ledger export')
      return lines
    },
  }
}

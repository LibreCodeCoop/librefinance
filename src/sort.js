// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
// Local table ordering is explicitly limited to loaded rows. Paginated
// financial lists must not present this as a server-wide ordering guarantee.
import { ledgerLocale } from './money.js'

let cachedLocale = null
let cachedCollator = null

export function sortLoadedRows(rows, column, direction = 'asc', accessors = {}, locale) {
  if (!column || !['asc','desc'].includes(direction)) return rows.slice()
  const resolvedLocale = ledgerLocale(locale)
  let collator
  if (cachedCollator && cachedLocale === resolvedLocale) {
    collator = cachedCollator
  } else {
    collator = new Intl.Collator(resolvedLocale, { numeric: true, sensitivity: 'base' })
    cachedLocale = resolvedLocale
    cachedCollator = collator
  }
  const valueOf = accessors[column] || (row => row[column])
  const multiplier = direction === 'asc' ? 1 : -1
  return rows.map((row, index) => ({ row, index })).sort((left, right) => {
    const a = valueOf(left.row)
    const b = valueOf(right.row)
    const aMissing = a === null || a === undefined || a === ''
    const bMissing = b === null || b === undefined || b === ''
    if (aMissing || bMissing) {
      return aMissing !== bMissing ? (aMissing ? 1 : -1) : left.index - right.index
    }
    const comparison = typeof a === 'number' && typeof b === 'number'
      ? Math.sign(a - b)
      : collator.compare(String(a), String(b))
    return multiplier * comparison || left.index - right.index
  }).map(({row}) => row)
}

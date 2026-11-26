// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { toMinorUnits } from './money.js'

/** Parse a signed decimal in the selected snapshot's precision, without rounding. */
export function previewMinorUnits(value, precision) {
  if (!Number.isInteger(precision) || precision < 0 || precision > 3) return null
  const match = /^(-?)(\d{1,13})(?:[.,](\d{1,3}))?$/.exec(String(value).trim())
  if (!match || match[2].length > (precision === 0 ? 13 : 12)
    || (match[3]?.length ?? 0) > precision) return null
  if (!/[1-9]/.test(match[2] + (match[3] ?? ''))) return 0
  try {
    const amount = toMinorUnits(match[2] + (match[3] ? '.' + match[3] : ''), precision)
    return match[1] ? -amount : amount
  } catch {
    return null
  }
}

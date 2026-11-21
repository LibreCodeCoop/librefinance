// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Convert a positive base-100 amount to exact minor units without binary floating-point
 * arithmetic. The initial ledger uses two-decimal currencies only.
 */
export function toMinorUnits(value) {
  const normalized = String(value).trim().replace(',', '.')
  if (!/^\d{1,12}(\.\d{1,2})?$/.test(normalized)) {
    throw new Error('Informe um valor válido com até duas casas decimais.')
  }
  const [units, cents = ''] = normalized.split('.')
  const amount = Number(units) * 100 + Number(cents.padEnd(2, '0'))
  if (!Number.isSafeInteger(amount) || amount <= 0 || amount > 9000000000000) {
    throw new Error('Valor fora dos limites admitidos.')
  }
  return amount
}

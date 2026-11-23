// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Projection of unfinished work, NOT a combined accounting ledger.
 * Confirmed invoices belong to the linked obligation register; bank
 * statement evidence is never a payment or a second financial posting.
 * Inputs come from separately authorized, server-filtered, paginated APIs.
 */
export function workQueueItems(drafts = [], bankMovements = []) {
  const items = []
  for (const row of drafts) {
    const id = Number(row.id)
    if (!['draft','issued','partially_paid','paid'].includes(row.status)
      || !Number.isSafeInteger(id) || id < 1) continue
    if (!['invoice', 'bill'].includes(row.kind)) continue
    const amount = Number(row.total_minor)
    if (!Number.isSafeInteger(amount)) continue
    const draft = row.status === 'draft'
    items.push({
      key: (draft ? 'draft:' : 'unlinked:') + id, id, kind: row.kind,
      type: draft ? 'draft' : 'unlinked',
      date: row.issue_on,
      label: draft
        ? row.kind === 'bill' ? 'Documento de compra' : 'Documento de venda'
        : 'Documento histórico sem vínculo',
      description: row.number,
      amountMinor: row.kind === 'bill' ? -amount : amount,
      status: draft ? 'A confirmar' : 'Revisar vínculo',
    })
  }
  for (const row of bankMovements) {
    const id = Number(row.id)
    if (row.status !== 'pending' || !Number.isSafeInteger(id) || id < 1) continue
    const amount = Number(row.amount_minor)
    if (!Number.isSafeInteger(amount)) continue
    items.push({
      key: 'bank:' + id, id, type: 'bank',
      transaction: row,
      date: row.booking_date,
      label: amount >= 0 ? 'Entrada no extrato' : 'Saída no extrato',
      description: row.description,
      amountMinor: amount,
      status: 'A conciliar',
    })
  }
  return items
}

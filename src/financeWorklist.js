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
    if (!Number.isSafeInteger(row?.id) || row.id < 1) continue
    const id = row.id
    if (!['draft','issued','partially_paid','paid'].includes(row.status)
      || !['invoice', 'bill'].includes(row.kind)) continue
    if (!Number.isSafeInteger(row.total_minor)) continue
    const amount = row.total_minor
    const draft = row.status === 'draft'
    items.push({
      key: (draft ? 'draft:' : 'unlinked:') + id, id, kind: row.kind,
      type: draft ? 'draft' : 'unlinked',
      date: row.issue_on,
      label: draft
        ? row.kind === 'bill' ? 'purchase_document' : 'sales_document'
        : 'historic_unlinked',
      description: row.number,
      amountMinor: row.kind === 'bill' ? -amount : amount,
      status: draft ? 'confirm' : 'link_review',
    })
  }
  for (const row of bankMovements) {
    if (!Number.isSafeInteger(row?.id) || row.id < 1) continue
    const id = row.id
    if (row.status !== 'pending') continue
    if (!Number.isSafeInteger(row.amount_minor)) continue
    const amount = row.amount_minor
    items.push({
      key: 'bank:' + id, id, type: 'bank',
      transaction: row,
      date: row.booking_date,
      label: amount >= 0 ? 'bank_inflow' : 'bank_outflow',
      description: row.description,
      amountMinor: amount,
      status: 'reconcile',
    })
  }
  return items
}

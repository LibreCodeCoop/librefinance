<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { t } from '@nextcloud/l10n'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { formatLedgerMoney } from '../money.js'
import { formatLedgerDate } from '../date.js'
defineProps({ preview: { type: Object, required: true } })
function classification(value) {
  if (value === 'new') return t('librefinance', 'New pending movement')
  if (value === 'replayed') return t('librefinance', 'Already imported')
  return t('librefinance', 'Conflicting external identifier')
}
function diagnostic(code) {
  switch (code) {
    case 'invalid_csv_header': return t('librefinance', 'Use the CSV columns id, date, amount, description in that order.')
    case 'invalid_csv_amount':
    case 'invalid_ofx_amount': return t('librefinance', 'Correct the amount, using the supported decimal precision.')
    case 'invalid_date': return t('librefinance', 'Correct the movement date.')
    case 'bank_import_currency_mismatch': return t('librefinance', 'The statement currency differs from the destination account.')
    case 'invalid_ofx_currency': return t('librefinance', 'The OFX statement currency is invalid or unsupported.')
    case 'unsupported_bank_import_format': return t('librefinance', 'Choose a supported CSV or OFX file.')
    case 'invalid_ofx_accounts': return t('librefinance', 'Use a file containing exactly one bank statement.')
    case 'empty_import': return t('librefinance', 'The statement does not contain movements.')
    default: return t('librefinance', 'Correct the file format or data before reviewing again.')
  }
}
</script>
<template>
  <section class="lf-statement-preview" :aria-label="t('librefinance', 'Statement import preview')">
    <h3>{{ t('librefinance', 'Statement import preview') }}</h3>
    <NcNoteCard v-if="preview.fileError" type="error" role="alert">
      <p v-if="preview.fileError.row">{{ t('librefinance', 'Data row {row}', { row: preview.fileError.row }) }}</p>
      <p>{{ diagnostic(preview.fileError.code) }} ({{ preview.fileError.code }})</p>
      <p>{{ t('librefinance', 'No rows can be confirmed from an invalid file.') }}</p>
    </NcNoteCard>
    <template v-else>
      <NcNoteCard :type="preview.ready ? 'info' : 'warning'" role="status">
        <p>{{ t('librefinance', 'New: {new}; already imported: {replayed}; conflicts: {conflicting}.', {
          new: preview.counts.new, replayed: preview.counts.replayed, conflicting: preview.counts.conflicting,
        }) }}</p>
        <p>{{ preview.ready
          ? t('librefinance', 'Only pending evidence will be imported. No cash is booked or journal created.')
          : t('librefinance', 'Resolve conflicting identifiers in the source file and review again. No rows can be imported.') }}</p>
      </NcNoteCard>
      <ul class="lf-preview-rows" data-testid="lf-import-preview-rows">
        <li v-for="row in preview.rows" :key="row.row">
          <strong>{{ classification(row.classification) }}</strong>
          <p>{{ row.externalId }} · {{ formatLedgerDate(row.date) }}</p>
          <p>{{ row.description }} · {{ formatLedgerMoney(row.amountMinor, preview.currency, undefined, preview.currencyPrecision) }}</p>
        </li>
      </ul>
    </template>
  </section>
</template>
<style scoped>
.lf-statement-preview { display: grid; gap: 8px; }
.lf-preview-rows { display: grid; gap: 4px; padding: 0; margin: 0; list-style: none; max-height: 18rem; overflow-y: auto; }
.lf-preview-rows li { border: 1px solid var(--color-border); border-radius: var(--border-radius-large); padding: 8px; overflow-wrap: anywhere; }
.lf-preview-rows p { margin: 2px 0; }
</style>

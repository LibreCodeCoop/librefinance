<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { computed, inject, onBeforeUnmount, ref, watch } from 'vue'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import LfDateField from './LfDateField.vue'
import LfSelect from './LfSelect.vue'
import { financeApi, financeError } from '../services/api.js'
import { formatLedgerDate } from '../date.js'
import { createGeneralLedgerExport, formatGeneralLedgerAmount } from '../generalLedger.js'

const w = inject('lfWorkspace')
const today = new Date().toLocaleDateString('sv-SE')
const from = ref(today.slice(0, 4) + '-01-01')
const through = ref(today)
const accountId = ref(0)
const fromValid = ref(true)
const throughValid = ref(true)
const accountOptions = computed(() => w.accounts.value.map(a => ({ value: a.id, label: a.code + ' · ' + a.name })))
const authorized = computed(() => Number(w.space.value?.id) === Number(w.activeId.value)
  && w.modulesLoadedFor.value === Number(w.activeId.value)
  && w.modules.value.some(m => m.id === 'librefinance_reports' && m.enabled))
const exportAllowed = computed(() => authorized.value && ['auditor', 'operator', 'manager', 'owner'].includes(w.space.value?.role))
const valid = computed(() => authorized.value && accountOptions.value.some(a => a.value === accountId.value)
  && fromValid.value && throughValid.value && !!from.value && !!through.value && from.value <= through.value)
const report = ref(null)
const rows = ref([])
const cursor = ref(null)
const busy = ref(false)
const exporting = ref(false)
const error = ref('')
const journalOpen = ref(false)
const journal = ref(null)
const journalError = ref('')
let generation = 0
let journalGeneration = 0

function closeJournal() {
  journalGeneration++
  journalOpen.value = false
  journal.value = null
  journalError.value = ''
}
function clear() {
  generation++
  report.value = null
  rows.value = []
  cursor.value = null
  busy.value = false
  exporting.value = false
  error.value = ''
  closeJournal()
}
watch([w.activeId, authorized, () => w.space.value?.role], () => {
  clear()
  accountId.value = 0
}, { flush: 'sync' })
watch([accountId, from, through, fromValid, throughValid], clear, { flush: 'sync' })
onBeforeUnmount(clear)

function path(route, query, next = null) {
  const filters = new URLSearchParams({ accountId: String(query.account.id), from: query.from, through: query.through })
  if (query.snapshot) filters.set('snapshot', query.snapshot)
  if (next) {
    filters.set('afterDate', next.afterDate)
    filters.set('afterId', String(next.afterId))
  }
  return route + '?' + filters
}
function message(e) {
  if (e.response?.data?.error === 'ledger_report_changed') return t('librefinance', 'The ledger changed. Apply the report again before continuing.')
  return financeError(e)
}
function failure(e) {
  clear()
  error.value = message(e)
}
async function apply() {
  if (!valid.value || busy.value) return
  clear()
  const token = generation
  const target = Number(w.activeId.value)
  const query = { account: { id: accountId.value }, from: from.value, through: through.value }
  busy.value = true
  try {
    const page = await financeApi('librefinance_reports', target, path('/general-ledger', query))
    if (token !== generation) return
    report.value = page
    rows.value = page.rows
    cursor.value = page.nextCursor
  } catch (e) { if (token === generation) failure(e) }
  finally { if (token === generation) busy.value = false }
}
async function more() {
  if (!report.value || !cursor.value || busy.value || exporting.value || !authorized.value) return
  const token = generation
  const target = Number(w.activeId.value)
  busy.value = true
  try {
    const page = await financeApi('librefinance_reports', target, path('/general-ledger', report.value, cursor.value))
    if (token !== generation) return
    rows.value = [...rows.value, ...page.rows]
    cursor.value = page.nextCursor
  } catch (e) { if (token === generation) failure(e) }
  finally { if (token === generation) busy.value = false }
}
async function exportCsv() {
  if (!report.value || busy.value || exporting.value || !exportAllowed.value) return
  const token = generation
  const target = Number(w.activeId.value)
  const applied = report.value
  exporting.value = true
  error.value = ''
  try {
    const csv = createGeneralLedgerExport(applied)
    let next = null
    do {
      const page = await financeApi('librefinance_reports', target, path('/general-ledger/export', applied, next))
      if (token !== generation || !exportAllowed.value) return
      csv.append(page)
      next = page.nextCursor
    } while (next !== null)
    const blob = new Blob(csv.finish(), { type: 'text/csv;charset=utf-8' })
    const url = URL.createObjectURL(blob)
    try {
      const anchor = document.createElement('a')
      anchor.href = url
      anchor.download = 'librefinance-general-ledger.csv'
      document.body.appendChild(anchor)
      anchor.click()
      anchor.remove()
    } finally { URL.revokeObjectURL(url) }
  } catch (e) { if (token === generation) failure(e) }
  finally { if (token === generation) exporting.value = false }
}
async function inspectJournal(id) {
  if (!authorized.value || !report.value) return
  const token = ++journalGeneration
  const target = Number(w.activeId.value)
  journalOpen.value = true
  journal.value = null
  journalError.value = ''
  try {
    const detail = await financeApi('librefinance_reports', target, '/general-ledger/journals/' + id)
    if (token === journalGeneration && authorized.value) journal.value = detail
  } catch (e) {
    if (token !== journalGeneration) return
    if ([403, 404].includes(e.response?.status)) failure(e)
    else journalError.value = message(e)
  }
}
const money = value => formatGeneralLedgerAmount(value, report.value.currency, report.value.currencyPrecision)
</script>
<template>
  <section class="lf-general-ledger" :aria-label="t('librefinance', 'General ledger')">
    <form class="lf-filter-row lf-report-period lf-ledger-period" @submit.prevent="apply">
      <LfSelect id="lf-ledger-account" v-model="accountId" :label="t('librefinance', 'Account')"
        :options="[{ value: 0, label: t('librefinance', 'Select an account') }, ...accountOptions]" />
      <LfDateField id="lf-ledger-from" v-model="from" @validity-change="fromValid = $event" :label="t('librefinance', 'From')" />
      <LfDateField id="lf-ledger-through" v-model="through" @validity-change="throughValid = $event" :label="t('librefinance', 'Through')" />
      <NcButton variant="secondary" type="submit" :disabled="!valid || busy">{{ t('librefinance', 'Apply period') }}</NcButton>
    </form>
    <p class="lf-muted">{{ t('librefinance', 'Posted ledger only. Debits increase the signed balance and credits decrease it. No cash or accrual conversion is performed.') }}</p>
    <NcNoteCard v-if="error" type="error" role="alert">{{ error }}</NcNoteCard>
    <div v-if="busy && !report" class="lf-loading"><NcLoadingIcon :size="30" /><p>{{ t('librefinance', 'Calculating report…') }}</p></div>
    <template v-if="report">
      <p class="lf-muted">{{ report.account.code }} · {{ report.account.name }} · {{ report.currency }} ·
        <time :datetime="report.from">{{ formatLedgerDate(report.from) }}</time> –
        <time :datetime="report.through">{{ formatLedgerDate(report.through) }}</time>
      </p>
      <div class="lf-report-metrics lf-ledger-metrics">
        <div><span>{{ t('librefinance', 'Opening balance') }}</span><strong>{{ money(report.openingMinor) }}</strong></div>
        <div><span>{{ t('librefinance', 'Closing balance') }}</span><strong>{{ money(report.closingMinor) }}</strong></div>
      </div>
      <div class="lf-report-actions lf-ledger-actions">
        <p class="lf-muted">{{ t('librefinance', 'Showing {loaded} of {total} posted rows.', { loaded: rows.length, total: report.rowCount }) }}</p>
        <NcButton v-if="exportAllowed" :disabled="busy || exporting" @click="exportCsv">
          {{ exporting ? t('librefinance', 'Exporting complete ledger…') : t('librefinance', 'Export CSV') }}
        </NcButton>
      </div>
      <p v-if="exportAllowed" class="lf-muted">{{ t('librefinance', 'The CSV includes every row of the applied period in exact minor units. Import the integer columns as text to preserve all digits in spreadsheets.') }}</p>
      <div v-if="rows.length" class="lf-table-scroll"><table class="lf-data-table lf-ledger-table">
        <thead><tr><th>{{ t('librefinance', 'Date') }}</th>
          <th>{{ t('librefinance', 'Journal') }}</th>
          <th>{{ t('librefinance', 'Description') }}</th>
          <th class="lf-numeric">{{ t('librefinance', 'Debit') }}</th>
          <th class="lf-numeric">{{ t('librefinance', 'Credit') }}</th>
          <th class="lf-numeric">{{ t('librefinance', 'Running balance') }}</th></tr></thead>
        <tbody><tr v-for="row in rows" :key="row.postingId">
          <td><time :datetime="row.date">{{ formatLedgerDate(row.date) }}</time></td>
          <td><NcButton variant="tertiary" @click="inspectJournal(row.journalId)">#{{ row.journalId }}</NcButton>
            <NcButton v-if="row.reversedJournalId" variant="tertiary" @click="inspectJournal(row.reversedJournalId)">
              {{ t('librefinance', 'Reversal of #{id}', { id: row.reversedJournalId }) }}
            </NcButton></td>
          <td>{{ row.memo }}</td><td class="lf-numeric">{{ money(row.debitMinor) }}</td>
          <td class="lf-numeric">{{ money(row.creditMinor) }}</td><td class="lf-numeric">{{ money(row.runningMinor) }}</td>
        </tr></tbody>
      </table></div>
      <NcEmptyContent v-else :name="t('librefinance', 'No movements in this period')" />
      <div v-if="cursor" class="lf-table-footer"><NcButton :disabled="busy || exporting" @click="more">{{ t('librefinance', 'Load more') }}</NcButton></div>
    </template>
    <NcDialog :open="journalOpen" :name="t('librefinance', 'Posted journal')" size="normal" content-classes="lf-finance-dialog-content"
      @update:open="!$event && closeJournal()">
      <NcNoteCard v-if="journalError" type="error" role="alert">{{ journalError }}</NcNoteCard>
      <div v-else-if="!journal" class="lf-loading"><NcLoadingIcon :size="30" /></div>
      <template v-else>
        <h3>#{{ journal.id }} · {{ formatLedgerDate(journal.date) }}</h3><p>{{ journal.memo }}</p>
        <NcButton v-if="journal.reversedJournalId" variant="tertiary" @click="inspectJournal(journal.reversedJournalId)">
          {{ t('librefinance', 'Reversal of #{id}', { id: journal.reversedJournalId }) }}
        </NcButton>
        <div class="lf-table-scroll"><table class="lf-data-table lf-ledger-journal-table">
          <thead><tr><th>{{ t('librefinance', 'Account') }}</th>
          <th class="lf-numeric">{{ t('librefinance', 'Signed amount') }}</th></tr></thead>
          <tbody><tr v-for="(posting, index) in journal.postings" :key="index">
            <td>{{ posting.code }} · {{ posting.name }}</td>
            <td class="lf-numeric">{{ formatGeneralLedgerAmount(posting.amountMinor, posting.currency, report.currencyPrecision) }}</td>
          </tr></tbody>
        </table></div>
      </template>
    </NcDialog>
  </section>
</template>

<style scoped>
#librefinance-app .lf-ledger-period {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  align-items: end;
}
#librefinance-app .lf-ledger-period > :deep(.lf-field) {
  grid-column: 1 / -1;
  min-width: 0;
  max-width: none;
}
#librefinance-app .lf-ledger-period > :deep(*) { min-width: 0; }
#librefinance-app .lf-ledger-period > :deep(button[type="submit"]) { justify-self: start; }
#librefinance-app .lf-ledger-metrics { grid-template-columns: repeat(2, minmax(0, 1fr)); }
.lf-ledger-actions { display: flex; gap: 8px; flex-wrap: wrap; justify-content: space-between; align-items: center; }
#librefinance-app .lf-ledger-table :is(th, td) { padding-inline: 7px; }
.lf-ledger-table td:nth-child(3) { overflow-wrap: anywhere; min-width: 90px; }
.lf-ledger-journal-table { width: 100%; }
.lf-ledger-journal-table :is(th, td) { padding: 8px; text-align: start; border-bottom: 1px solid var(--color-border); }
.lf-ledger-journal-table .lf-numeric { text-align: end; font-variant-numeric: tabular-nums; }
@media (max-width: 670px) {
  #librefinance-app .lf-ledger-period, #librefinance-app .lf-ledger-metrics {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>

<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { computed, inject, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { mdiPlus, mdiChartLine, mdiCashMultiple, mdiTableLarge, mdiPencilOutline, mdiArchiveOutline, mdiDeleteOutline } from '@mdi/js'
import LfPageHeader from '../components/LfPageHeader.vue'
import LfDateField from '../components/LfDateField.vue'
import LfSelect from '../components/LfSelect.vue'
import { financeApi, financeError } from '../services/api.js'
const w = inject('lfWorkspace')
const { activeId, money, manager } = w
const today = new Date().toLocaleDateString('sv-SE')
const startYear = today.slice(0, 4) + '-01-01'
const reportKinds = [
  { value: 'profit_loss', label: t('librefinance', 'Profit and loss for the period'), help: t('librefinance', 'Income, expenses and result by period'), icon: mdiChartLine },
  { value: 'cash_flow', label: t('librefinance', 'Actual cash flow'), help: t('librefinance', 'Cash inflows, outflows and ledger balance'), icon: mdiCashMultiple },
  { value: 'trial_balance', label: t('librefinance', 'Trial balance'), help: t('librefinance', 'Ledger balance by account and class'), icon: mdiTableLarge },
]
const catalog = ref([])
const catalogNext = ref(null)
const selected = ref('profit_loss')
const from = ref(startYear)
const through = ref(today)
const result = ref(null)
const trial = ref([])
const trialNext = ref(null)
const busy = ref(false)
const catalogBusy = ref(false)
const error = ref('')
const modalError = ref('')
const notice = ref('')
const editorOpen = ref(false)
const archiveTarget = ref(null)
const deleteTarget = ref(null)
const draft = ref({ id: 0, name: '', kind: 'profit_loss', from: startYear, through: today })
let generation = 0
const currentSaved = computed(() => selected.value.startsWith('saved:')
  ? catalog.value.find(row => row.id === Number(selected.value.slice(6))) ?? null : null)
const currentKind = computed(() => currentSaved.value?.kind ?? selected.value)
const currentType = computed(() => reportKinds.find(item => item.value === currentKind.value) ?? reportKinds[0])
const currentTitle = computed(() => currentSaved.value?.name ?? currentType.value.label)
const canUsePeriod = computed(() => currentKind.value !== 'trial_balance')
const classes = { asset: t('librefinance', 'Asset'), liability: t('librefinance', 'Liability'), equity: t('librefinance', 'Equity'),
  revenue: t('librefinance', 'Revenue'), expense: t('librefinance', 'Expense') }
const balanceColumns = [
  { id: 'code', label: t('librefinance', 'Accounting code') },
  { id: 'name', label: t('librefinance', 'Account name') },
  { id: 'class', label: t('librefinance', 'Account nature') },
  { id: 'balanceMinor', label: t('librefinance', 'Ledger balance') },
]
const defaultBalanceColumns = balanceColumns.map(column => column.id)
const classOptions = [
  { value: '', label: t('librefinance', 'All classes') },
  ...Object.entries(classes).map(([value,label]) => ({ value, label })),
]
const activeBalanceColumns = computed(() => currentSaved.value?.columns?.length
  ? currentSaved.value.columns : defaultBalanceColumns)
const activeAccountClass = computed(() => currentSaved.value?.accountClass || '')
function setReportColumn(column, checked) {
  const current = draft.value.columns ?? [...defaultBalanceColumns]
  draft.value.columns = checked
    ? [...current.filter(c => c !== column), column]
    : current.filter(c => c !== column)
}
watch(() => draft.value.kind, kind => {
  draft.value.columns = kind === 'trial_balance' ? [...defaultBalanceColumns] : []
  draft.value.accountClass = ''
})

function currentPath() {
  const filters = new URLSearchParams({ through: through.value })
  if (canUsePeriod.value) filters.set('from', from.value)
  if (currentKind.value === 'trial_balance' && activeAccountClass.value)
    filters.set('accountClass', activeAccountClass.value)
  const route = currentKind.value === 'profit_loss' ? '/profit-loss'
    : (currentKind.value === 'cash_flow' ? '/cash-flow' : '/trial-balance')
  return route + '?' + filters.toString()
}
async function execute() {
  const token = ++generation
  busy.value = true
  error.value = ''
  result.value = null
  trial.value = []
  trialNext.value = null
  try {
    const response = await financeApi('librefinance_reports', Number(activeId.value), currentPath())
    if (token !== generation) return
    result.value = response
    if (currentKind.value === 'trial_balance') {
      trial.value = response.accounts
      trialNext.value = response.nextAfterId
    }
  } catch (e) { if (token === generation) error.value = financeError(e) }
  finally { if (token === generation) busy.value = false }
}
async function loadMore() {
  if (busy.value || !trialNext.value) return
  const token = generation
  busy.value = true
  try {
    const response = await financeApi('librefinance_reports', Number(activeId.value),
      currentPath() + '&afterId=' + trialNext.value)
    if (token !== generation) return
    trial.value = [...trial.value, ...response.accounts]
    trialNext.value = response.nextAfterId
  } catch (e) { if (token === generation) error.value = financeError(e) }
  finally { if (token === generation) busy.value = false }
}
async function loadSaved(afterId = null) {
  if (catalogBusy.value) return
  catalogBusy.value = true
  try {
    const response = await financeApi('librefinance_reports', Number(activeId.value),
      '/saved-reports' + (afterId ? '?afterId=' + afterId : ''))
    if (afterId) catalog.value = [...catalog.value, ...response.reports]
    else catalog.value = response.reports
    catalogNext.value = response.nextAfterId
  } catch (e) { error.value = financeError(e) }
  finally { catalogBusy.value = false }
}
function selectReport(key) {
  selected.value = key
  const saved = key.startsWith('saved:')
    ? catalog.value.find(row => row.id === Number(key.slice(6))) : null
  from.value = saved?.from ?? startYear
  through.value = saved?.through ?? today
  notice.value = ''
  execute()
}
function beginCreate() {
  modalError.value = ''
  draft.value = { id: 0, name: '', kind: currentKind.value, from: from.value,
    through: through.value, columns: currentKind.value === 'trial_balance'
      ? [...defaultBalanceColumns] : [], accountClass: '' }
  editorOpen.value = true
}
function beginEdit() {
  if (!currentSaved.value) return
  modalError.value = ''
  draft.value = { ...currentSaved.value,
    columns: [...(currentSaved.value.columns || [])] }
  editorOpen.value = true
}
async function saveReport() {
  if (catalogBusy.value) return
  modalError.value = ''
  if (!draft.value.name.trim()) { modalError.value = t('librefinance', 'Enter a name for the report.'); return }
  catalogBusy.value = true
  try {
    const payload = { name: draft.value.name.trim(), kind: draft.value.kind,
      from: draft.value.kind === 'trial_balance' ? null : draft.value.from,
      through: draft.value.through,
      columns: draft.value.kind === 'trial_balance' ? draft.value.columns : [],
      accountClass: draft.value.kind === 'trial_balance'
        ? (draft.value.accountClass || null) : null }
    const id = draft.value.id
    const response = await financeApi('librefinance_reports', Number(activeId.value),
      '/saved-reports' + (id ? '/' + id : ''), id ? 'PUT' : 'POST', payload)
    if (id) catalog.value = catalog.value.map(row => row.id === id
      ? { ...row, ...response } : row)
    else catalog.value = [...catalog.value, response]
    selected.value = 'saved:' + response.id
    from.value = response.from ?? startYear
    through.value = response.through
    editorOpen.value = false
    notice.value = id ? t('librefinance', 'Report updated.') : t('librefinance', 'Report registered in this workspace.')
    execute()
  } catch (e) { modalError.value = financeError(e) }
  finally { catalogBusy.value = false }
}
async function archiveReport() {
  if (!archiveTarget.value || catalogBusy.value) return
  catalogBusy.value = true
  try {
    const id = archiveTarget.value.id
    await financeApi('librefinance_reports', Number(activeId.value), '/saved-reports/' + id, 'DELETE')
    catalog.value = catalog.value.filter(row => row.id !== id)
    archiveTarget.value = null
    notice.value = t('librefinance', 'Report archived; no ledger entries were deleted.')
    if (selected.value === 'saved:' + id) selectReport('profit_loss')
  } catch (e) { error.value = financeError(e) }
  finally { catalogBusy.value = false }
}
async function deleteReport() {
  if (!deleteTarget.value || catalogBusy.value) return
  catalogBusy.value = true
  try {
    const id = deleteTarget.value.id
    await financeApi('librefinance_reports', Number(activeId.value),
      '/saved-reports/' + id + '/permanent', 'DELETE')
    catalog.value = catalog.value.filter(row => row.id !== id)
    deleteTarget.value = null
    if (selected.value === 'saved:' + id) selectReport('profit_loss')
    notice.value = t('librefinance', 'Configuration deleted. No ledger entries were changed.')
  } catch (e) { error.value = financeError(e) }
  finally { catalogBusy.value = false }
}
onMounted(async () => { await Promise.all([loadSaved(), execute()]) })
onBeforeUnmount(() => ++generation)
</script>
<template>
  <div class="lf-view lf-report-workspace">
    <LfPageHeader :title="t('librefinance', 'Reports')" :eyebrow="t('librefinance', 'FINANCIAL ANALYSIS')"
      :description="t('librefinance', 'Choose a report, adjust its period and save configurations for future use.')">
      <template #actions>
        <NcButton v-if="manager" variant="primary" @click="beginCreate">
          <template #icon><NcIconSvgWrapper :path="mdiPlus" :size="20" /></template>
          {{ t('librefinance', 'New report') }}
        </NcButton>
      </template>
    </LfPageHeader>
    <NcNoteCard v-if="error" type="error" role="alert">{{ error }}</NcNoteCard>
    <NcNoteCard v-if="notice" type="success" role="status">{{ notice }}</NcNoteCard>
    <div class="lf-report-layout">
      <section class="lf-surface lf-report-catalog" :aria-label="t('librefinance', 'Report catalog')">
        <h2>{{ t('librefinance', 'Available templates') }}</h2>
        <p class="lf-muted">{{ t('librefinance', 'Reports based on posted accounting data.') }}</p>
        <div class="lf-report-choices">
          <button v-for="type in reportKinds" :key="type.value" type="button"
            :aria-pressed="selected === type.value" :class="{ active: selected === type.value }"
            @click="selectReport(type.value)">
            <NcIconSvgWrapper :path="type.icon" :size="20" aria-hidden="true" />
            <span><strong>{{ type.label }}</strong><small>{{ type.help }}</small></span>
          </button>
        </div>
        <div class="lf-report-catalog-heading"><h3>{{ t('librefinance', 'My reports') }}</h3>
          <NcButton v-if="manager" variant="tertiary" @click="beginCreate">{{ t('librefinance', 'Add') }}</NcButton></div>
        <p v-if="!catalog.length" class="lf-muted">{{ t('librefinance', 'No custom report has been registered.') }}</p>
        <div class="lf-report-choices">
          <button v-for="saved in catalog" :key="saved.id" type="button"
            :aria-pressed="selected === 'saved:' + saved.id"
            :class="{ active: selected === 'saved:' + saved.id }"
            @click="selectReport('saved:' + saved.id)">
            <NcIconSvgWrapper :path="reportKinds.find(item => item.value === saved.kind)?.icon" :size="20" aria-hidden="true" />
            <span><strong>{{ saved.name }}</strong><small>{{ reportKinds.find(item => item.value === saved.kind)?.label }}</small></span>
          </button>
        </div>
        <NcButton v-if="catalogNext" variant="tertiary" :disabled="catalogBusy"
          @click="loadSaved(catalogNext)">{{ t('librefinance', 'Load more templates') }}</NcButton>
      </section>
      <section class="lf-surface lf-report-preview">
        <div class="lf-section-heading"><div>
          <h2>{{ currentTitle }}</h2><p>{{ currentType.help }}</p></div>
          <div v-if="manager && currentSaved" class="lf-row-actions">
            <NcButton variant="tertiary" @click="beginEdit">
              <template #icon><NcIconSvgWrapper :path="mdiPencilOutline" :size="18" /></template>{{ t('librefinance', 'Edit') }}
            </NcButton>
            <NcButton variant="tertiary" @click="archiveTarget = currentSaved">
              <template #icon><NcIconSvgWrapper :path="mdiArchiveOutline" :size="18" /></template>{{ t('librefinance', 'Archive') }}
            </NcButton>
            <NcButton variant="tertiary" @click="deleteTarget = currentSaved">
              <template #icon><NcIconSvgWrapper :path="mdiDeleteOutline" :size="18" /></template>{{ t('librefinance', 'Delete') }}
            </NcButton>
          </div>
        </div>
        <form class="lf-filter-row lf-report-period" @submit.prevent="execute">
          <LfDateField v-if="canUsePeriod" id="lf-report-from" v-model="from" :label="t('librefinance', 'From')" />
          <LfDateField id="lf-report-through" v-model="through" :label="canUsePeriod ? t('librefinance', 'Through') : t('librefinance', 'Reference date')" />
          <NcButton variant="secondary" type="submit" :disabled="busy">{{ t('librefinance', 'Apply period') }}</NcButton>
        </form>
        <p class="lf-muted">{{currentSaved ? t('librefinance', 'Saved template with an initial period. You can view other dates without changing the template.') : t('librefinance', 'Standard template available in all authorized workspaces.')}}</p>
        <div v-if="busy && !result" class="lf-loading"><NcLoadingIcon :size="30" /><p>{{ t('librefinance', 'Calculating report…') }}</p></div>
        <template v-if="result && currentKind === 'profit_loss'">
          <div class="lf-report-metrics">
            <div><span>{{ t('librefinance', 'Income') }}</span><strong>{{ money(result.revenueMinor) }}</strong></div>
            <div><span>{{ t('librefinance', 'Expenses') }}</span><strong>{{ money(result.expenseMinor) }}</strong></div>
            <div><span>{{ t('librefinance', 'Result') }}</span><strong>{{ money(result.resultMinor) }}</strong></div>
          </div>
          <p class="lf-muted">{{ t('librefinance', 'Actual result for the period. Excludes forecasts and pending bank statements.') }}</p>
        </template>
        <template v-if="result && currentKind === 'cash_flow'">
          <div class="lf-report-metrics">
            <div><span>{{ t('librefinance', 'Opening balance') }}</span><strong>{{ money(result.openingMinor) }}</strong></div>
            <div><span>{{ t('librefinance', 'Change') }}</span><strong>{{ money(result.netMinor) }}</strong></div>
            <div><span>{{ t('librefinance', 'Closing balance') }}</span><strong>{{ money(result.closingMinor) }}</strong></div>
          </div>
          <h3>{{ t('librefinance', 'Movements by month') }}</h3>
          <div v-if="result.months.length" class="lf-table-scroll"><table class="lf-data-table">
            <thead><tr>
              <th>{{ t('librefinance', 'Month') }}</th>
              <th class="lf-numeric">{{ t('librefinance', 'Net change') }}</th>
            </tr></thead>
            <tbody><tr v-for="month in result.months" :key="month.month">
              <td>{{ month.month }}</td><td class="lf-numeric">{{ money(month.netMinor) }}</td>
            </tr></tbody>
          </table></div>
          <NcEmptyContent v-else :name="t('librefinance', 'No movements in this period')" />
        </template>
        <template v-if="result && currentKind === 'trial_balance'">
          <p v-if="activeAccountClass" class="lf-muted">{{ t('librefinance', 'Filtered by class: {class}. The filter is applied on the server before pagination.', { class: classes[activeAccountClass] }, { escape: false, sanitize: false }) }}</p>
          <div v-if="trial.length" class="lf-table-scroll"><table class="lf-data-table">
            <thead><tr><th v-for="column in activeBalanceColumns" :key="column"
              :class="{ 'lf-numeric': column === 'balanceMinor' }">
              {{ balanceColumns.find(field => field.id === column)?.label }}</th></tr></thead>
            <tbody><tr v-for="account in trial" :key="account.id">
              <td v-for="column in activeBalanceColumns" :key="column"
                :class="{ 'lf-numeric': column === 'balanceMinor', 'lf-code': column === 'code' }">
                <strong v-if="column === 'name'">{{ account.name }}</strong>
                <template v-else-if="column === 'balanceMinor'">{{ money(account.balanceMinor) }}</template>
                <template v-else-if="column === 'class'">{{ classes[account.class] || account.class }}</template>
                <template v-else>{{ account.code }}</template>
              </td>
            </tr></tbody>
          </table></div>
          <NcEmptyContent v-else :name="t('librefinance', 'No account found')" />
          <div v-if="trialNext" class="lf-table-footer"><NcButton :disabled="busy" @click="loadMore">{{ t('librefinance', 'More accounts') }}</NcButton></div>
        </template>
        <details class="lf-finance-explanation">
          <summary>{{ t('librefinance', 'How are these numbers calculated?') }}</summary>
          <p>{{ t('librefinance', 'Reports use only posted entries in the workspace. They exclude pending statements, projections and unposted tax data. New accounting models still require their own calculation services and formal validation.') }}</p>
        </details>
      </section>
    </div>
    <NcDialog size="normal" content-classes="lf-finance-dialog-content" :open="editorOpen"
      :name="draft.id ? t('librefinance', 'Edit report') : t('librefinance', 'New report')" @update:open="editorOpen = $event">
      <form class="lf-dialog-form" @submit.prevent="saveReport">
        <p>{{ t('librefinance', 'Register a report configuration from an existing report. Creating entirely new accounting reports is a future capability.') }}</p>
        <NcNoteCard v-if="modalError" type="error" role="alert">{{ modalError }}</NcNoteCard>
        <NcTextField v-model="draft.name" :label="t('librefinance', 'Report name')" required maxlength="120" />
        <LfSelect id="lf-saved-report-kind" v-model="draft.kind" :label="t('librefinance', 'Base template')"
          :options="reportKinds.map(item => ({value:item.value,label:item.label}))" />
        <div class="lf-form-grid">
          <LfDateField v-if="draft.kind !== 'trial_balance'" id="lf-saved-report-from" v-model="draft.from" :label="t('librefinance', 'From')" />
          <LfDateField id="lf-saved-report-through" v-model="draft.through"
            :label="draft.kind === 'trial_balance' ? t('librefinance', 'Reference date') : t('librefinance', 'Through')" />
        </div>
        <section v-if="draft.kind === 'trial_balance'" class="lf-report-builder">
          <h3>{{ t('librefinance', 'Customize the table') }}</h3>
          <p class="lf-muted">{{ t('librefinance', 'Choose columns, their order and the account class. Accounting calculations do not change.') }}</p>
          <div class="lf-report-column-options">
            <NcCheckboxRadioSwitch v-for="column in balanceColumns" :key="column.id"
              :model-value="draft.columns?.includes(column.id)" type="checkbox"
              @update:model-value="setReportColumn(column.id, $event)">
              {{ column.label }}</NcCheckboxRadioSwitch>
          </div>
          <LfSelect id="lf-saved-report-account-class" v-model="draft.accountClass"
            :label="t('librefinance', 'Show only accounts of class')" :options="classOptions" />
          <NcNoteCard type="info">{{ t('librefinance', 'This version configures the trial balance presentation and class filter; it does not create custom formulas or financial statements.') }}</NcNoteCard>
        </section>
        <div class="lf-form-actions">
          <NcButton variant="tertiary" @click="editorOpen=false">{{ t('librefinance', 'Cancel') }}</NcButton>
          <NcButton variant="primary" type="submit" :disabled="catalogBusy">{{ t('librefinance', 'Save report') }}</NcButton>
        </div>
      </form>
    </NcDialog>
    <NcDialog size="normal" content-classes="lf-finance-dialog-content" :open="Boolean(deleteTarget)"
      :name="t('librefinance', 'Permanently delete report')" @update:open="!$event && (deleteTarget = null)">
      <div class="lf-dialog-form">
        <NcNoteCard type="warning">{{ t('librefinance', 'This action permanently deletes the report configuration, even if it is archived. It does not remove accounting information.') }}</NcNoteCard>
        <p>{{ t('librefinance', 'Delete {name}?', { name: deleteTarget?.name }, { escape: false, sanitize: false }) }}</p>
        <div class="lf-form-actions">
          <NcButton variant="tertiary" @click="deleteTarget=null">{{ t('librefinance', 'Cancel') }}</NcButton>
          <NcButton variant="primary" :disabled="catalogBusy" @click="deleteReport">
            {{ t('librefinance', 'Delete configuration') }}
          </NcButton>
        </div>
      </div>
    </NcDialog>
    <NcDialog size="normal" content-classes="lf-finance-dialog-content" :open="Boolean(archiveTarget)"
      :name="t('librefinance', 'Archive report')" @update:open="!$event && (archiveTarget = null)">
      <div class="lf-dialog-form">
        <p>{{ t('librefinance', 'Archive {name}? The template will no longer appear in the catalog. Ledger entries and accounting data will remain intact.', { name: archiveTarget?.name }, { escape: false, sanitize: false }) }}</p>
        <div class="lf-form-actions">
          <NcButton variant="tertiary" @click="archiveTarget=null">{{ t('librefinance', 'Cancel') }}</NcButton>
          <NcButton variant="primary" :disabled="catalogBusy" @click="archiveReport">{{ t('librefinance', 'Archive template') }}</NcButton>
        </div>
      </div>
    </NcDialog>
  </div>
</template>

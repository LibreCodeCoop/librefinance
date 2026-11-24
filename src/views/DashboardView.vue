<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { computed, inject, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { t } from '@nextcloud/l10n'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import {
  mdiWalletOutline, mdiArrowDownLeft, mdiArrowUpRight, mdiChartLine,
  mdiBankOutline, mdiReceiptTextOutline, mdiCashClock, mdiArrowRight,
  mdiCalendarMonthOutline, mdiSwapHorizontal,
} from '@mdi/js'
import LfPageHeader from '../components/LfPageHeader.vue'
import { financeApi, financeError } from '../services/api.js'
import { ledgerLocale } from '../money.js'
import { formatLedgerDate } from '../date.js'

const store = inject('lfWorkspace')
const navigate = inject('lfNavigate')
const entryDialog = inject('lfEntryDialog')
const { activeId, accounts, journals, money, naturalBalance, modules, writable, error: workspaceError } = store
const personal = computed(() => store.space.value?.kind === 'personal')
const enabled = (id) => modules.value.some(m => m.id === id && m.enabled)
const reportEnabled = computed(() => enabled('librefinance_reports'))
const billingEnabled = computed(() => enabled('librefinance_invoicing'))
const bankEnabled = computed(() => enabled('librefinance_banking'))
const obligationsEnabled = computed(() => enabled('librefinance_obligations'))
const profit = ref(null)
const cash = ref(null)
const error = ref('')
const loading = ref(false)
// User-level dashboard policies control presentation only, not financial API ACL.
const showOverviewKpis = ref(false)
let presentationSequence = 0
async function loadUserPresentationPolicy() {
  const token = ++presentationSequence
  const target = Number(activeId.value)
  const saas = store.space.value?.saasManaged === true
  showOverviewKpis.value = !saas
  if (!saas || !target) return
  try {
    const response = await axios.get(generateUrl('/apps/librefinance/api/saas/v1/workspaces/'
      + target + '/policies/me/dashboard.kpis.enabled'))
    if (token !== presentationSequence || Number(activeId.value) !== target) return
    showOverviewKpis.value = response.data.effectiveValue === true
  } catch {
    // A transient API failure must not reveal a hidden card in SaaS mode.
    if (token === presentationSequence) showOverviewKpis.value = false
  }
}
watch(() => [activeId.value, store.space.value?.saasManaged], loadUserPresentationPolicy)
const today = new Date()
const year = today.getFullYear()
const startYear = year + '-01-01'
const startMonth = year + '-' + String(today.getMonth() + 1).padStart(2, '0') + '-01'
const dateUntil = year + '-' + String(today.getMonth() + 1).padStart(2, '0')
  + '-' + String(today.getDate()).padStart(2, '0')
const period = ref('year')
const from = computed(() => period.value === 'month' ? startMonth : startYear)
const formatPeriodDate = (value) => new Intl.DateTimeFormat(ledgerLocale(), {
  day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC',
}).format(new Date(value + 'T00:00:00Z'))
const periodLabel = computed(() => formatPeriodDate(from.value)
  + ' — ' + formatPeriodDate(dateUntil))
function changePeriod(next) {
  if (!['year', 'month'].includes(next) || next === period.value) return
  period.value = next
  load()
}
let generation = 0
async function load() {
  const token = ++generation
  profit.value = null
  cash.value = null
  error.value = ''
  if (!reportEnabled.value) return
  loading.value = true
  try {
    // These are full-period server aggregates, never estimates based on the
    // paginated recent-journal list or unposted bank-statement lines.
    const dates = '?from=' + from.value + '&through=' + dateUntil
    const [summary, cashResult] = await Promise.all([
      financeApi('librefinance_reports', activeId.value, '/profit-loss' + dates),
      financeApi('librefinance_reports', activeId.value, '/cash-flow' + dates),
    ])
    if (token !== generation) return
    profit.value = summary
    cash.value = cashResult
  } catch (failure) {
    if (token === generation) error.value = financeError(failure)
  } finally {
    if (token === generation) loading.value = false
  }
}
onMounted(() => { load(); loadWidgetLayout(); loadUserPresentationPolicy() })
onBeforeUnmount(() => { ++generation; ++layoutSequence; ++presentationSequence })
const recent = computed(() => journals.value.slice(0, 5))
const firstUse = computed(() => writable.value && !workspaceError.value && journals.value.length === 0)
const namedAccounts = computed(() =>
  accounts.value.filter(a => a.is_bank && a.active).slice(0, 5))
const kpis = computed(() => [
  {
    id: 'cash', label: t('librefinance', 'Cash balance'),
    value: cash.value ? money(cash.value.closingMinor) : '—',
    help: t('librefinance', 'Posted balance, not statement balance'),
    icon: mdiWalletOutline, tone: 'cash',
  },
  {
    id: 'revenue', label: t('librefinance', 'Income'),
    value: profit.value ? money(profit.value.revenueMinor) : '—',
    help: t('librefinance', 'Accumulated in the period'), icon: mdiArrowDownLeft, tone: 'income',
  },
  {
    id: 'expense', label: t('librefinance', 'Expenses'),
    value: profit.value ? money(profit.value.expenseMinor) : '—',
    help: t('librefinance', 'Accumulated in the period'), icon: mdiArrowUpRight, tone: 'expense',
  },
  {
    id: 'result', label: t('librefinance', 'Result'),
    value: profit.value ? money(profit.value.resultMinor) : '—',
    help: t('librefinance', 'Income minus expenses'), icon: mdiChartLine, tone: 'result',
  },
])
const months = computed(() => {
  const known = new Map((cash.value?.months ?? []).map(row => [row.month, Number(row.netMinor)]))
  const end = today.getMonth() + 1
  const start = period.value === 'month' ? end : Math.max(1, end - 5)
  return Array.from({ length: end - start + 1 }, (_, i) => {
    const month = start + i
    const key = year + '-' + String(month).padStart(2, '0')
    return {
      key,
      label: new Intl.DateTimeFormat(ledgerLocale(), { month: 'short', timeZone: 'UTC' })
        .format(new Date(Date.UTC(year, month - 1, 15))),
      minor: known.get(key) ?? 0,
    }
  })
})
const maxMovement = computed(() => Math.max(1, ...months.value.map(m => Math.abs(m.minor))))
const hasRecentMovement = computed(() => months.value.some(m => m.minor !== 0))
function barHeight(minor) {
  if (!minor) return '0%'
  return Math.max(8, Math.round(Math.abs(minor) / maxMovement.value * 100)) + '%'
}
const shortcuts = computed(() => [
  bankEnabled.value && {
    id: 'banking', label: t('librefinance', 'Bank reconciliation'),
    help: t('librefinance', 'Review statements and journal entries'), icon: mdiBankOutline,
  },
  obligationsEnabled.value && {
    id: 'finance', label: t('librefinance', 'Payables and receivables'),
    help: t('librefinance', 'Expected inflows and outflows in one schedule'), icon: mdiCashClock,
  },
  reportEnabled.value && {
    id: 'reports', label: t('librefinance', 'Full reports'),
    help: t('librefinance', 'Explore the trial balance and cash flow'), icon: mdiChartLine,
  },
].filter(Boolean))
function firstExpense() {
  if (!writable.value || !namedAccounts.value.length) return
  store.direction.value = 'expense'
  go('transactions')
  entryDialog.value = true
}
const defaultWidgets = [
  { id: 'summary', width: 2, visible: true },
  { id: 'cashflow', width: 1, visible: true },
  { id: 'bankAccounts', width: 1, visible: true },
  { id: 'recentActivity', width: 1, visible: true },
  { id: 'quickActions', width: 1, visible: true },
]
const widgetLabels = {
  summary: t('librefinance', 'Financial summary'), cashflow: t('librefinance', 'Cash movement'),
  bankAccounts: t('librefinance', 'Financial accounts'), recentActivity: t('librefinance', 'Recent activity'),
  quickActions: t('librefinance', 'Quick access'),
}
const widgets = ref(defaultWidgets.map(item => ({ ...item })))
const editingWidgets = ref(false)
const savingWidgets = ref(false)
const widgetError = ref('')
const draggedWidgetId = ref('')
let layoutSequence = 0
const visibleWidgets = computed(() => widgets.value.filter(item => editingWidgets.value || item.visible))
async function loadWidgetLayout() {
  const token = ++layoutSequence
  try {
    const data = await financeApi('librefinance', Number(activeId.value), '/dashboard-layout')
    if (token !== layoutSequence) return
    widgets.value = data.widgets
    widgetError.value = ''
  } catch (e) {
    if (token === layoutSequence) widgetError.value = financeError(e)
  }
}
function startWidgetEditing() {
  if (savingWidgets.value) return
  editingWidgets.value = true
  widgetError.value = ''
}
function moveWidget(id, offset) {
  const array = [...widgets.value]
  const index = array.findIndex(item => item.id === id)
  const target = index + offset
  if (index < 0 || target < 0 || target >= array.length) return
  ;[array[index], array[target]] = [array[target], array[index]]
  widgets.value = array
}
function dropWidget(targetId) {
  const source = draggedWidgetId.value
  draggedWidgetId.value = ''
  if (!source || source === targetId) return
  const array = [...widgets.value]
  const start = array.findIndex(item => item.id === source)
  const target = array.findIndex(item => item.id === targetId)
  if (start < 0 || target < 0) return
  array.splice(target, 0, array.splice(start, 1)[0])
  widgets.value = array
}
function resizeWidget(id) {
  widgets.value = widgets.value.map(item => item.id === id
    ? { ...item, width: item.width === 1 ? 2 : 1 } : item)
}
function toggleWidget(id) {
  if (widgets.value.filter(item => item.visible).length === 1
      && widgets.value.some(item => item.id === id && item.visible)) return
  widgets.value = widgets.value.map(item => item.id === id
    ? { ...item, visible: !item.visible } : item)
}
async function saveWidgetLayout() {
  if (savingWidgets.value) return
  savingWidgets.value = true
  widgetError.value = ''
  try {
    const response = await financeApi('librefinance', Number(activeId.value),
      '/dashboard-layout', 'PUT', { widgets: widgets.value })
    widgets.value = response.widgets
    editingWidgets.value = false
  } catch (e) { widgetError.value = financeError(e) }
  finally { savingWidgets.value = false }
}
async function resetWidgets() {
  if (savingWidgets.value) return
  savingWidgets.value = true
  widgetError.value = ''
  try {
    const response = await financeApi('librefinance', Number(activeId.value),
      '/dashboard-layout', 'DELETE')
    widgets.value = response.widgets
  } catch (e) { widgetError.value = financeError(e) }
  finally { savingWidgets.value = false }
}
function cancelWidgetEditing() {
  if (savingWidgets.value) return
  editingWidgets.value = false
  loadWidgetLayout()
}
function go(view) { navigate(view) }
</script>

<template>
  <div class="lf-view lf-overview">
    <LfPageHeader :title="t('librefinance', 'Overview')" :eyebrow="t('librefinance', 'FINANCIAL DASHBOARD')"
      :description="personal
        ? t('librefinance', 'Track actual household inflows and outflows. Future bills appear in Payables and receivables.')
        : t('librefinance', 'A clear view of the financial position and recorded transactions.')">
      <template #actions>
        <NcButton v-if="!editingWidgets" variant="secondary" @click="startWidgetEditing">
          {{ t('librefinance', 'Customize dashboard') }}
        </NcButton>
        <div v-else class="lf-widget-edit-actions">
          <NcButton variant="tertiary" :disabled="savingWidgets" @click="cancelWidgetEditing">{{ t('librefinance', 'Cancel') }}</NcButton>
          <NcButton variant="tertiary" :disabled="savingWidgets" @click="resetWidgets">{{ t('librefinance', 'Restore defaults') }}</NcButton>
          <NcButton variant="primary" :disabled="savingWidgets" @click="saveWidgetLayout">{{ t('librefinance', 'Finish editing') }}</NcButton>
        </div>
        <div class="lf-period-controls" :aria-label="t('librefinance', 'Indicator period')">
          <div class="lf-period-switch">
            <NcButton :variant="period === 'month' ? 'primary' : 'tertiary'"
              :pressed="period === 'month'" @click="changePeriod('month')">{{ t('librefinance', 'This month') }}</NcButton>
            <NcButton :variant="period === 'year' ? 'primary' : 'tertiary'"
              :pressed="period === 'year'" @click="changePeriod('year')">{{ t('librefinance', 'This year') }}</NcButton>
          </div>
          <div class="lf-period-chip">
            <NcIconSvgWrapper :path="mdiCalendarMonthOutline" :size="18" />
            <span>{{ periodLabel }}</span>
          </div>
        </div>
      </template>
    </LfPageHeader>

    <div v-if="firstUse" class="lf-overview-subhead lf-overview-first-use">
      <div>
        <strong>{{personal ? t('librefinance', 'Your household workspace is ready') : t('librefinance', 'Your financial workspace is ready')}}</strong>
        <span>{{ namedAccounts.length
          ? (personal
            ? t('librefinance', 'Start with a paid household expense. There is no need to register a company.')
            : t('librefinance', 'Start by recording a paid expense or received income.'))
          : t('librefinance', 'Before the first entry, register a financial account in the chart of accounts.') }}</span>
      </div>
      <NcButton v-if="namedAccounts.length" variant="primary" @click="firstExpense">{{ t('librefinance', 'Record first expense') }}</NcButton>
      <NcButton v-else variant="secondary" @click="go('accounts')">{{ t('librefinance', 'Register financial account') }}</NcButton>
    </div>
    <div v-else class="lf-overview-subhead">
      <div>
        <strong>{{ t('librefinance', 'Your finances in perspective') }}</strong>
        <span>{{ t('librefinance', 'Consolidated ledger indicators') }}</span>
      </div>
      <span class="lf-data-source"><span class="lf-data-source-dot" aria-hidden="true" />
        {{ loading ? t('librefinance', 'Updating indicators') : t('librefinance', 'Posted data') }}
        <NcLoadingIcon v-if="loading" :size="18" />
      </span>
    </div>

    <NcNoteCard v-if="error" type="warning" role="alert">
      {{ t('librefinance', 'Indicators could not be retrieved: {error}. No balance was estimated.', { error }, { escape: false, sanitize: false }) }}
    </NcNoteCard>
    <NcNoteCard v-else-if="!reportEnabled" type="info">
      {{ t('librefinance', 'Enable Reports under Manage → Installed features to view totals and cash trends. Pending statements are not part of posted balances.') }}
    </NcNoteCard>

    <NcNoteCard v-if="widgetError" type="warning" role="alert">{{ widgetError }}</NcNoteCard>
    <p v-if="editingWidgets" class="lf-muted">{{ t('librefinance', 'Drag widgets or use the arrows to reorder them. Adjust their width and choose which appear for you in this workspace.') }}</p>
    <div class="lf-widget-grid" :class="{ 'lf-widget-grid--editing': editingWidgets }">
      <div v-for="(widget, index) in visibleWidgets" :key="widget.id"
        class="lf-widget-slot" :class="{ 'lf-widget-slot--hidden': !widget.visible,
          'lf-widget-slot--wide': widget.width === 2 }"
        :draggable="editingWidgets" @dragstart="draggedWidgetId = widget.id"
        @dragover="editingWidgets && $event.preventDefault()"
        @drop="editingWidgets && dropWidget(widget.id)"
        @dragend="draggedWidgetId = ''">
        <div v-if="editingWidgets" class="lf-widget-editor">
          <strong>{{ widgetLabels[widget.id] }}</strong>
          <div>
            <NcButton size="small" variant="tertiary" :disabled="index === 0"
              :aria-label="t('librefinance', 'Move {widget} up', { widget: widgetLabels[widget.id] }, { escape: false, sanitize: false })"
              @click="moveWidget(widget.id, -1)">↑</NcButton>
            <NcButton size="small" variant="tertiary" :disabled="index === visibleWidgets.length - 1"
              :aria-label="t('librefinance', 'Move {widget} down', { widget: widgetLabels[widget.id] }, { escape: false, sanitize: false })"
              @click="moveWidget(widget.id, 1)">↓</NcButton>
            <NcButton size="small" variant="tertiary" @click="resizeWidget(widget.id)">
              {{ widget.width === 2 ? t('librefinance', 'Normal width') : t('librefinance', 'Double width') }}</NcButton>
            <NcButton size="small" variant="tertiary" @click="toggleWidget(widget.id)">
              {{ widget.visible ? t('librefinance', 'Hide') : t('librefinance', 'Show') }}</NcButton>
          </div>
        </div>
        <template v-if="widget.id === 'summary'">
          <div v-if="showOverviewKpis" class="lf-kpis lf-overview-kpis"
            :aria-label="period === 'month' ? t('librefinance', 'Financial indicators for the month') : t('librefinance', 'Financial indicators for the year')">
                <section v-for="stat in kpis" :key="stat.id"
                  class="lf-kpi lf-overview-kpi" :class="'lf-overview-kpi--' + stat.tone">
                  <div class="lf-kpi-topline">
                    <span class="lf-kpi-label">{{ stat.label }}</span>
                    <span class="lf-kpi-icon" aria-hidden="true"><NcIconSvgWrapper :path="stat.icon" :size="21" /></span>
                  </div>
                  <strong class="lf-kpi-amount" :aria-label="stat.label + ': ' + stat.value">{{ stat.value }}</strong>
                  <span class="lf-kpi-detail">{{ stat.help }}</span>
                </section>
              </div>
          <p v-if="!showOverviewKpis" class="lf-muted">
            <span>{{ t('librefinance', 'Summary indicators hidden by presentation policy.') }}</span>
            {{ t('librefinance', 'Financial permissions and other reports remain unchanged.') }}
          </p>
        </template>
        <template v-else-if="widget.id === 'cashflow'">
      <section class="lf-surface lf-trend-panel">
        <div class="lf-section-heading">
          <div><h2>{{ t('librefinance', 'Cash movement') }}</h2>
            <p>{{ t('librefinance', 'Monthly net change in posted bank entries') }}</p></div>
          <NcButton v-if="reportEnabled" variant="tertiary" @click="go('reports')">
            {{ t('librefinance', 'View analysis') }} <template #icon><NcIconSvgWrapper :path="mdiArrowRight" :size="18" /></template>
          </NcButton>
        </div>
        <div v-if="cash && hasRecentMovement" class="lf-trend-chart"
          :style="{ gridTemplateColumns: `repeat(${months.length}, minmax(0, 1fr))` }"
          role="img" :aria-label="t('librefinance', 'Net cash change over recent months: {values}', { values: months.map(m => m.label + ' ' + money(m.minor)).join(', ') }, { escape: false, sanitize: false })">
          <div v-for="item in months" :key="item.key" class="lf-trend-month"
            :title="item.key + ': ' + money(item.minor)">
            <div class="lf-trend-plot">
              <div class="lf-trend-positive">
                <span v-if="item.minor > 0" class="lf-trend-bar lf-trend-bar--positive"
                  :style="{ height: barHeight(item.minor) }" />
              </div>
              <div class="lf-trend-negative">
                <span v-if="item.minor < 0" class="lf-trend-bar lf-trend-bar--negative"
                  :style="{ height: barHeight(item.minor) }" />
              </div>
            </div>
            <span class="lf-trend-month-label">{{ item.label }}</span>
          </div>
        </div>
        <div v-else class="lf-trend-empty">
          <NcIconSvgWrapper :path="mdiChartLine" :size="28" />
          <strong>{{ loading ? t('librefinance', 'Loading cash flow…') : t('librefinance', 'No cash change in this period') }}</strong>
          <p>{{ t('librefinance', 'The chart includes only posted entries affecting bank or cash accounts.') }}</p>
        </div>
        <div class="lf-trend-legend"><span class="lf-legend-positive" />{{ t('librefinance', 'Positive change') }}
          <span class="lf-legend-negative" />{{ t('librefinance', 'Negative change') }}</div>
      </section>

        </template>
        <template v-else-if="widget.id === 'bankAccounts'">
      <section class="lf-surface lf-bank-panel">
        <div class="lf-section-heading">
          <div><h2>{{ t('librefinance', 'Financial accounts') }}</h2>
            <p>{{ t('librefinance', 'Ledger balance by account') }}</p></div>
          <NcButton variant="tertiary" @click="go('accounts')">{{ t('librefinance', 'View chart of accounts') }}</NcButton>
        </div>
        <ul v-if="namedAccounts.length" class="lf-account-preview">
          <li v-for="bank in namedAccounts" :key="bank.id">
            <span class="lf-bank-account-symbol" aria-hidden="true"><NcIconSvgWrapper :path="mdiBankOutline" :size="20" /></span>
            <span class="lf-account-identity"><strong>{{ bank.name }}</strong><small>{{ t('librefinance', 'Account {code}', { code: bank.code }, { escape: false, sanitize: false }) }}</small></span>
            <strong>{{ naturalBalance(bank) }}</strong>
          </li>
        </ul>
        <NcEmptyContent v-else :name="t('librefinance', 'No cash account registered')"
          :description="t('librefinance', 'Balances appear after accounts are registered and transactions are posted.')" />
        <div v-if="bankEnabled" class="lf-bank-panel-footer">
          <NcButton variant="secondary" @click="go('banking')">{{ t('librefinance', 'Open bank reconciliation') }}</NcButton>
        </div>
      </section>
        </template>
        <template v-else-if="widget.id === 'recentActivity'">
      <section class="lf-surface lf-activity-panel">
        <div class="lf-section-heading">
          <div><h2>{{ t('librefinance', 'Recent activity') }}</h2>
            <p>{{ t('librefinance', 'Latest ledger entries') }}</p></div>
          <NcButton variant="tertiary" @click="go('transactions')">{{ t('librefinance', 'All journal entries') }}</NcButton>
        </div>
        <ul v-if="recent.length" class="lf-activity-list">
          <li v-for="item in recent" :key="item.id">
            <span class="lf-activity-symbol" aria-hidden="true"><NcIconSvgWrapper :path="mdiSwapHorizontal" :size="18" /></span>
            <div class="lf-activity-label"><strong>{{ item.memo || t('librefinance', 'Journal entry') }}</strong>
              <small>{{ formatLedgerDate(item.effective_on) }} · {{ t('librefinance', 'Postings: {count}', { count: item.postings?.length || 0 }, { escape: false, sanitize: false }) }}</small></div>
            <strong class="lf-activity-value">{{ money(Math.abs(item.postings?.[0]?.amountMinor || 0)) }}</strong>
          </li>
        </ul>
        <NcEmptyContent v-else :name="t('librefinance', 'No journal entries recorded')"
          :description="t('librefinance', 'This list will be populated when transactions are posted.')" />
      </section>

        </template>
        <template v-else-if="widget.id === 'quickActions'">
      <section class="lf-surface lf-shortcuts-panel">
        <div class="lf-section-heading">
          <div><h2>{{ t('librefinance', 'Quick access') }}</h2>
            <p>{{ t('librefinance', 'Continue with the work you need') }}</p></div>
        </div>
        <ul v-if="shortcuts.length" class="lf-quick-action-list">
          <li v-for="action in shortcuts" :key="action.id">
            <button type="button" class="lf-quick-action" @click="go(action.id)">
              <span class="lf-quick-action-icon" aria-hidden="true"><NcIconSvgWrapper :path="action.icon" :size="20" /></span>
              <span class="lf-quick-action-text"><strong>{{ action.label }}</strong><small>{{ action.help }}</small></span>
              <NcIconSvgWrapper :path="mdiArrowRight" :size="18" />
            </button>
          </li>
        </ul>
        <p v-else class="lf-muted">{{ t('librefinance', 'Enable features under Manage → Installed features to access other activities here.') }}</p>
      </section>
        </template>
      </div>
    </div>

    <details class="lf-data-methodology">
      <summary>{{ t('librefinance', 'Understand the displayed figures') }}</summary>
      <p>{{ t('librefinance', 'Cash balance, income, expenses and result are calculated from the workspace ledger. Imports, unreconciled statements and fiscal drafts do not automatically enter these indicators. Charts show actual changes, not forecasts.') }}</p>
    </details>
  </div>
</template>

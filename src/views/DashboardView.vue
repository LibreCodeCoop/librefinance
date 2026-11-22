<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { computed, inject, onBeforeUnmount, onMounted, ref } from 'vue'
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

const store = inject('lfWorkspace')
const navigate = inject('lfNavigate')
const entryDialog = inject('lfEntryDialog')
const { activeId, accounts, journals, money, naturalBalance, modules, writable, error: workspaceError } = store
const enabled = (id) => modules.value.some(m => m.id === id && m.enabled)
const reportEnabled = computed(() => enabled('librefinance_reports'))
const billingEnabled = computed(() => enabled('librefinance_invoicing'))
const bankEnabled = computed(() => enabled('librefinance_banking'))
const obligationsEnabled = computed(() => enabled('librefinance_obligations'))
const profit = ref(null)
const cash = ref(null)
const error = ref('')
const loading = ref(false)
const today = new Date()
const year = today.getFullYear()
const startYear = year + '-01-01'
const startMonth = year + '-' + String(today.getMonth() + 1).padStart(2, '0') + '-01'
const dateUntil = year + '-' + String(today.getMonth() + 1).padStart(2, '0')
  + '-' + String(today.getDate()).padStart(2, '0')
const period = ref('year')
const from = computed(() => period.value === 'month' ? startMonth : startYear)
const formatPeriodDate = (value) => new Intl.DateTimeFormat('pt-BR', {
  day: 'numeric', month: 'short', year: 'numeric',
}).format(new Date(value + 'T12:00:00'))
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
onMounted(() => { load(); loadWidgetLayout() })
onBeforeUnmount(() => { ++generation; ++layoutSequence })
const recent = computed(() => journals.value.slice(0, 5))
const firstUse = computed(() => writable.value && !workspaceError.value && journals.value.length === 0)
const namedAccounts = computed(() =>
  accounts.value.filter(a => a.is_bank && a.active).slice(0, 5))
const kpis = computed(() => [
  {
    id: 'cash', label: 'Saldo de caixa',
    value: cash.value ? money(cash.value.closingMinor) : '—',
    help: 'Saldo contabilizado, não saldo do extrato',
    icon: mdiWalletOutline, tone: 'cash',
  },
  {
    id: 'revenue', label: 'Receitas',
    value: profit.value ? money(profit.value.revenueMinor) : '—',
    help: 'Acumuladas no período', icon: mdiArrowDownLeft, tone: 'income',
  },
  {
    id: 'expense', label: 'Despesas',
    value: profit.value ? money(profit.value.expenseMinor) : '—',
    help: 'Acumuladas no período', icon: mdiArrowUpRight, tone: 'expense',
  },
  {
    id: 'result', label: 'Resultado',
    value: profit.value ? money(profit.value.resultMinor) : '—',
    help: 'Receitas menos despesas', icon: mdiChartLine, tone: 'result',
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
      label: new Intl.DateTimeFormat('pt-BR', { month: 'short' })
        .format(new Date(year, month - 1, 15)).replace('.', ''),
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
function displayDate(iso) {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(iso))
  return match ? match[3] + '/' + match[2] + '/' + match[1] : iso
}
const shortcuts = computed(() => [
  bankEnabled.value && {
    id: 'banking', label: 'Conciliação bancária',
    help: 'Confira extratos e lançamentos', icon: mdiBankOutline,
  },
  obligationsEnabled.value && {
    id: 'obligations', label: 'Contas a pagar e receber',
    help: 'Acompanhe títulos e baixas', icon: mdiCashClock,
  },
  billingEnabled.value && {
    id: 'sales', label: 'Faturas de venda',
    help: 'Consulte suas cobranças', icon: mdiReceiptTextOutline,
  },
  reportEnabled.value && {
    id: 'reports', label: 'Relatórios completos',
    help: 'Explore o balancete e o fluxo de caixa', icon: mdiChartLine,
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
  summary: 'Resumo financeiro', cashflow: 'Movimento do caixa',
  bankAccounts: 'Contas financeiras', recentActivity: 'Atividade recente',
  quickActions: 'Acesso rápido',
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
    <LfPageHeader title="Visão geral" eyebrow="PAINEL FINANCEIRO"
      description="Uma leitura objetiva da posição financeira e dos movimentos registrados.">
      <template #actions>
        <NcButton v-if="!editingWidgets" variant="secondary" @click="startWidgetEditing">
          Personalizar painel
        </NcButton>
        <div v-else class="lf-widget-edit-actions">
          <NcButton variant="tertiary" :disabled="savingWidgets" @click="cancelWidgetEditing">Cancelar</NcButton>
          <NcButton variant="tertiary" :disabled="savingWidgets" @click="resetWidgets">Restaurar padrão</NcButton>
          <NcButton variant="primary" :disabled="savingWidgets" @click="saveWidgetLayout">Concluir edição</NcButton>
        </div>
        <div class="lf-period-controls" aria-label="Período dos indicadores">
          <div class="lf-period-switch">
            <NcButton :variant="period === 'month' ? 'primary' : 'tertiary'"
              :pressed="period === 'month'" @click="changePeriod('month')">Este mês</NcButton>
            <NcButton :variant="period === 'year' ? 'primary' : 'tertiary'"
              :pressed="period === 'year'" @click="changePeriod('year')">Neste ano</NcButton>
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
        <strong>Seu espaço financeiro está pronto</strong>
        <span>{{ namedAccounts.length
          ? 'Comece registrando uma despesa já paga ou uma receita já recebida.'
          : 'Antes do primeiro registro, cadastre uma conta financeira no plano de contas.' }}</span>
      </div>
      <NcButton v-if="namedAccounts.length" variant="primary" @click="firstExpense">Registrar primeira despesa</NcButton>
      <NcButton v-else variant="secondary" @click="go('accounts')">Cadastrar conta financeira</NcButton>
    </div>
    <div v-else class="lf-overview-subhead">
      <div>
        <strong>Seu financeiro em perspectiva</strong>
        <span>Indicadores consolidados do livro contábil</span>
      </div>
      <span class="lf-data-source"><span class="lf-data-source-dot" aria-hidden="true" />
        {{ loading ? 'Atualizando indicadores' : 'Dados contabilizados' }}
        <NcLoadingIcon v-if="loading" :size="18" />
      </span>
    </div>

    <NcNoteCard v-if="error" type="warning" role="alert">
      Os indicadores não puderam ser consultados: {{ error }}. Nenhum saldo foi estimado.
    </NcNoteCard>
    <NcNoteCard v-else-if="!reportEnabled" type="info">
      Ative Relatórios em Configurações para consultar totais e evolução do caixa.
      Extratos pendentes não compõem os saldos contabilizados.
    </NcNoteCard>

    <NcNoteCard v-if="widgetError" type="warning" role="alert">{{ widgetError }}</NcNoteCard>
    <p v-if="editingWidgets" class="lf-muted">Arraste os widgets ou use as setas para reorganizar.
      Ajuste a largura e escolha quais aparecem para você nesta gestão financeira.</p>
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
              :aria-label="'Mover ' + widgetLabels[widget.id] + ' para cima'"
              @click="moveWidget(widget.id, -1)">↑</NcButton>
            <NcButton size="small" variant="tertiary" :disabled="index === visibleWidgets.length - 1"
              :aria-label="'Mover ' + widgetLabels[widget.id] + ' para baixo'"
              @click="moveWidget(widget.id, 1)">↓</NcButton>
            <NcButton size="small" variant="tertiary" @click="resizeWidget(widget.id)">
              {{ widget.width === 2 ? 'Largura normal' : 'Largura dupla' }}</NcButton>
            <NcButton size="small" variant="tertiary" @click="toggleWidget(widget.id)">
              {{ widget.visible ? 'Ocultar' : 'Exibir' }}</NcButton>
          </div>
        </div>
        <template v-if="widget.id === 'summary'">
          <div class="lf-kpis lf-overview-kpis" aria-label="Indicadores financeiros do ano">
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
        </template>
        <template v-else-if="widget.id === 'cashflow'">
      <section class="lf-surface lf-trend-panel">
        <div class="lf-section-heading">
          <div><h2>Movimento do caixa</h2>
            <p>Variação líquida mensal dos lançamentos bancários contabilizados</p></div>
          <NcButton v-if="reportEnabled" variant="tertiary" @click="go('reports')">
            Ver análise <template #icon><NcIconSvgWrapper :path="mdiArrowRight" :size="18" /></template>
          </NcButton>
        </div>
        <div v-if="cash && hasRecentMovement" class="lf-trend-chart"
          :style="{ gridTemplateColumns: `repeat(${months.length}, minmax(0, 1fr))` }"
          role="img" :aria-label="'Variação líquida do caixa nos últimos meses: ' + months.map(m => m.label + ' ' + money(m.minor)).join(', ')">
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
          <strong>{{ loading ? 'Consultando o fluxo de caixa…' : 'Sem variação de caixa neste intervalo' }}</strong>
          <p>O gráfico é preenchido somente por lançamentos que afetam contas bancárias ou caixa.</p>
        </div>
        <div class="lf-trend-legend"><span class="lf-legend-positive" />Variação positiva
          <span class="lf-legend-negative" />Variação negativa</div>
      </section>

        </template>
        <template v-else-if="widget.id === 'bankAccounts'">
      <section class="lf-surface lf-bank-panel">
        <div class="lf-section-heading">
          <div><h2>Contas financeiras</h2>
            <p>Saldo contábil por conta</p></div>
          <NcButton variant="tertiary" @click="go('accounts')">Ver plano de contas</NcButton>
        </div>
        <ul v-if="namedAccounts.length" class="lf-account-preview">
          <li v-for="bank in namedAccounts" :key="bank.id">
            <span class="lf-bank-account-symbol" aria-hidden="true"><NcIconSvgWrapper :path="mdiBankOutline" :size="20" /></span>
            <span class="lf-account-identity"><strong>{{ bank.name }}</strong><small>Conta {{ bank.code }}</small></span>
            <strong>{{ naturalBalance(bank) }}</strong>
          </li>
        </ul>
        <NcEmptyContent v-else name="Nenhuma conta de caixa cadastrada"
          description="Os saldos aparecem após o cadastro e a contabilização de movimentos." />
        <div v-if="bankEnabled" class="lf-bank-panel-footer">
          <NcButton variant="secondary" @click="go('banking')">Abrir conciliação bancária</NcButton>
        </div>
      </section>
        </template>
        <template v-else-if="widget.id === 'recentActivity'">
      <section class="lf-surface lf-activity-panel">
        <div class="lf-section-heading">
          <div><h2>Atividade recente</h2><p>Últimos lançamentos do livro razão</p></div>
          <NcButton variant="tertiary" @click="go('transactions')">Todos os lançamentos</NcButton>
        </div>
        <ul v-if="recent.length" class="lf-activity-list">
          <li v-for="item in recent" :key="item.id">
            <span class="lf-activity-symbol" aria-hidden="true"><NcIconSvgWrapper :path="mdiSwapHorizontal" :size="18" /></span>
            <div class="lf-activity-label"><strong>{{ item.memo || 'Lançamento contábil' }}</strong>
              <small>{{ displayDate(item.effective_on) }} · {{ item.postings?.length || 0 }} partidas</small></div>
            <strong class="lf-activity-value">{{ money(Math.abs(item.postings?.[0]?.amountMinor || 0)) }}</strong>
          </li>
        </ul>
        <NcEmptyContent v-else name="Nenhum lançamento registrado"
          description="Esta lista será preenchida quando houver movimentações contabilizadas." />
      </section>

        </template>
        <template v-else-if="widget.id === 'quickActions'">
      <section class="lf-surface lf-shortcuts-panel">
        <div class="lf-section-heading">
          <div><h2>Acesso rápido</h2><p>Continue de onde seu trabalho precisa</p></div>
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
        <p v-else class="lf-muted">Habilite recursos em Configurações para acessar outras atividades por aqui.</p>
      </section>
        </template>
      </div>
    </div>

    <details class="lf-data-methodology">
      <summary>Entenda os números apresentados</summary>
      <p>Saldo de caixa, receitas, despesas e resultado são calculados a partir do livro contábil do espaço.
        Importações, extratos pendentes de conciliação e rascunhos fiscais não entram automaticamente
        nos indicadores. Os gráficos mostram variações realizadas, não previsões.</p>
    </details>
  </div>
</template>

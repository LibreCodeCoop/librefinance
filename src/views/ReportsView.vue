<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { computed, inject, onBeforeUnmount, onMounted, ref, watch } from 'vue'
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
  { value: 'profit_loss', label: 'Resultado do período', help: 'Receitas, despesas e resultado por período', icon: mdiChartLine },
  { value: 'cash_flow', label: 'Fluxo de caixa realizado', help: 'Entradas, saídas e saldo contábil do caixa', icon: mdiCashMultiple },
  { value: 'trial_balance', label: 'Balancete', help: 'Saldo contábil por conta e natureza', icon: mdiTableLarge },
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
const classes = { asset: 'Ativo', liability: 'Passivo', equity: 'Patrimônio',
  revenue: 'Receita', expense: 'Despesa' }
const balanceColumns = [
  { id: 'code', label: 'Código contábil' },
  { id: 'name', label: 'Nome da conta' },
  { id: 'class', label: 'Natureza' },
  { id: 'balanceMinor', label: 'Saldo contábil' },
]
const defaultBalanceColumns = balanceColumns.map(column => column.id)
const classOptions = [
  { value: '', label: 'Todas as classes' },
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
  if (!draft.value.name.trim()) { modalError.value = 'Informe um nome para o relatório.'; return }
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
    notice.value = id ? 'Relatório atualizado.' : 'Relatório cadastrado neste espaço.'
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
    notice.value = 'Relatório arquivado; nenhum lançamento contábil foi excluído.'
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
    notice.value = 'Configuração excluída. Nenhum lançamento contábil foi modificado.'
  } catch (e) { error.value = financeError(e) }
  finally { catalogBusy.value = false }
}
onMounted(async () => { await Promise.all([loadSaved(), execute()]) })
onBeforeUnmount(() => ++generation)
</script>
<template>
  <div class="lf-view lf-report-workspace">
    <LfPageHeader title="Relatórios" eyebrow="ANÁLISE FINANCEIRA"
      description="Escolha um demonstrativo, ajuste seu período e salve configurações para uso futuro.">
      <template #actions>
        <NcButton v-if="manager" variant="primary" @click="beginCreate">
          <template #icon><NcIconSvgWrapper :path="mdiPlus" :size="20" /></template>
          Novo relatório
        </NcButton>
      </template>
    </LfPageHeader>
    <NcNoteCard v-if="error" type="error" role="alert">{{ error }}</NcNoteCard>
    <NcNoteCard v-if="notice" type="success" role="status">{{ notice }}</NcNoteCard>
    <div class="lf-report-layout">
      <section class="lf-surface lf-report-catalog" aria-label="Catálogo de relatórios">
        <h2>Modelos disponíveis</h2>
        <p class="lf-muted">Demonstrativos baseados em dados efetivamente contabilizados.</p>
        <div class="lf-report-choices">
          <button v-for="type in reportKinds" :key="type.value" type="button"
            :aria-pressed="selected === type.value" :class="{ active: selected === type.value }"
            @click="selectReport(type.value)">
            <NcIconSvgWrapper :path="type.icon" :size="20" aria-hidden="true" />
            <span><strong>{{ type.label }}</strong><small>{{ type.help }}</small></span>
          </button>
        </div>
        <div class="lf-report-catalog-heading"><h3>Meus relatórios</h3>
          <NcButton v-if="manager" variant="tertiary" @click="beginCreate">Adicionar</NcButton></div>
        <p v-if="!catalog.length" class="lf-muted">Nenhum relatório personalizado cadastrado.</p>
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
          @click="loadSaved(catalogNext)">Carregar mais modelos</NcButton>
      </section>
      <section class="lf-surface lf-report-preview">
        <div class="lf-section-heading"><div>
          <h2>{{ currentTitle }}</h2><p>{{ currentType.help }}</p></div>
          <div v-if="manager && currentSaved" class="lf-row-actions">
            <NcButton variant="tertiary" @click="beginEdit">
              <template #icon><NcIconSvgWrapper :path="mdiPencilOutline" :size="18" /></template>Editar
            </NcButton>
            <NcButton variant="tertiary" @click="archiveTarget = currentSaved">
              <template #icon><NcIconSvgWrapper :path="mdiArchiveOutline" :size="18" /></template>Arquivar
            </NcButton>
            <NcButton variant="tertiary" @click="deleteTarget = currentSaved">
              <template #icon><NcIconSvgWrapper :path="mdiDeleteOutline" :size="18" /></template>Excluir
            </NcButton>
          </div>
        </div>
        <form class="lf-filter-row lf-report-period" @submit.prevent="execute">
          <LfDateField v-if="canUsePeriod" id="lf-report-from" v-model="from" label="De" />
          <LfDateField id="lf-report-through" v-model="through" :label="canUsePeriod ? 'Até' : 'Data de referência'" />
          <NcButton variant="secondary" type="submit" :disabled="busy">Aplicar período</NcButton>
        </form>
        <p class="lf-muted">{{currentSaved ? 'Modelo salvo com período inicial. Você pode consultar outras datas sem alterar o modelo.' : 'Modelo padrão disponível em todos os espaços autorizados.'}}</p>
        <div v-if="busy && !result" class="lf-loading"><NcLoadingIcon :size="30" /><p>Calculando relatório…</p></div>
        <template v-if="result && currentKind === 'profit_loss'">
          <div class="lf-report-metrics">
            <div><span>Receitas</span><strong>{{ money(result.revenueMinor) }}</strong></div>
            <div><span>Despesas</span><strong>{{ money(result.expenseMinor) }}</strong></div>
            <div><span>Resultado</span><strong>{{ money(result.resultMinor) }}</strong></div>
          </div>
          <p class="lf-muted">Resultado realizado no período. Não inclui previsões ou extratos pendentes.</p>
        </template>
        <template v-if="result && currentKind === 'cash_flow'">
          <div class="lf-report-metrics">
            <div><span>Saldo inicial</span><strong>{{ money(result.openingMinor) }}</strong></div>
            <div><span>Variação</span><strong>{{ money(result.netMinor) }}</strong></div>
            <div><span>Saldo final</span><strong>{{ money(result.closingMinor) }}</strong></div>
          </div>
          <h3>Movimentos por mês</h3>
          <div class="lf-table-scroll"><table class="lf-data-table">
            <thead><tr><th>Mês</th><th class="lf-numeric">Variação líquida</th></tr></thead>
            <tbody><tr v-for="month in result.months" :key="month.month">
              <td>{{ month.month }}</td><td class="lf-numeric">{{ money(month.netMinor) }}</td>
            </tr></tbody>
          </table><NcEmptyContent v-if="!result.months.length" name="Sem movimentação neste período" /></div>
        </template>
        <template v-if="result && currentKind === 'trial_balance'">
          <p v-if="activeAccountClass" class="lf-muted">Filtrado por natureza: {{ classes[activeAccountClass] }}.
            O filtro é aplicado no servidor antes da paginação.</p>
          <div class="lf-table-scroll"><table class="lf-data-table">
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
          </table><NcEmptyContent v-if="!trial.length" name="Nenhuma conta encontrada" /></div>
          <div v-if="trialNext" class="lf-table-footer"><NcButton :disabled="busy" @click="loadMore">Mais contas</NcButton></div>
        </template>
        <details class="lf-finance-explanation">
          <summary>Como esses números são calculados?</summary>
          <p>Os demonstrativos utilizam exclusivamente lançamentos contabilizados do espaço.
            Não incluem extratos pendentes, projeções ou dados fiscais sem escrituração.
            Novos modelos contábeis ainda exigem serviços de cálculo próprios e validação formal.</p>
        </details>
      </section>
    </div>
    <NcDialog size="normal" content-classes="lf-finance-dialog-content" :open="editorOpen"
      :name="draft.id ? 'Editar relatório' : 'Novo relatório'" @update:open="editorOpen = $event">
      <form class="lf-dialog-form" @submit.prevent="saveReport">
        <p>Cadastre uma configuração de relatório a partir de um demonstrativo existente.
          A criação de demonstrativos contábeis inteiramente novos será uma capacidade futura.</p>
        <NcNoteCard v-if="modalError" type="error" role="alert">{{ modalError }}</NcNoteCard>
        <NcTextField v-model="draft.name" label="Nome do relatório" required maxlength="120" />
        <LfSelect id="lf-saved-report-kind" v-model="draft.kind" label="Modelo base"
          :options="reportKinds.map(item => ({value:item.value,label:item.label}))" />
        <div class="lf-form-grid">
          <LfDateField v-if="draft.kind !== 'trial_balance'" id="lf-saved-report-from" v-model="draft.from" label="De" />
          <LfDateField id="lf-saved-report-through" v-model="draft.through"
            :label="draft.kind === 'trial_balance' ? 'Data de referência' : 'Até'" />
        </div>
        <section v-if="draft.kind === 'trial_balance'" class="lf-report-builder">
          <h3>Personalizar a tabela</h3>
          <p class="lf-muted">Escolha as colunas, sua ordem e a natureza das contas. Os cálculos contábeis não mudam.</p>
          <div class="lf-report-column-options">
            <NcCheckboxRadioSwitch v-for="column in balanceColumns" :key="column.id"
              :model-value="draft.columns?.includes(column.id)" type="checkbox"
              @update:model-value="setReportColumn(column.id, $event)">
              {{ column.label }}</NcCheckboxRadioSwitch>
          </div>
          <LfSelect id="lf-saved-report-account-class" v-model="draft.accountClass"
            label="Mostrar apenas contas de" :options="classOptions" />
          <NcNoteCard type="info">Esta versão configura a apresentação e o filtro de classe do balancete,
            não cria fórmulas ou demonstrações contábeis livres.</NcNoteCard>
        </section>
        <div class="lf-form-actions">
          <NcButton variant="tertiary" @click="editorOpen=false">Cancelar</NcButton>
          <NcButton variant="primary" type="submit" :disabled="catalogBusy">Salvar relatório</NcButton>
        </div>
      </form>
    </NcDialog>
    <NcDialog size="normal" content-classes="lf-finance-dialog-content" :open="Boolean(deleteTarget)"
      name="Excluir relatório permanentemente" @update:open="!$event && (deleteTarget = null)">
      <div class="lf-dialog-form">
        <NcNoteCard type="warning">Esta ação exclui definitivamente a configuração do relatório,
          inclusive se estiver arquivada. Não remove informações contábeis.</NcNoteCard>
        <p>Deseja excluir <strong>{{ deleteTarget?.name }}</strong>?</p>
        <div class="lf-form-actions">
          <NcButton variant="tertiary" @click="deleteTarget=null">Cancelar</NcButton>
          <NcButton variant="primary" :disabled="catalogBusy" @click="deleteReport">
            Excluir configuração
          </NcButton>
        </div>
      </div>
    </NcDialog>
    <NcDialog size="normal" content-classes="lf-finance-dialog-content" :open="Boolean(archiveTarget)"
      name="Arquivar relatório" @update:open="!$event && (archiveTarget = null)">
      <div class="lf-dialog-form">
        <p>Arquivar <strong>{{ archiveTarget?.name }}</strong>? O modelo deixará de aparecer no catálogo.
          Os lançamentos e dados contábeis permanecerão intactos.</p>
        <div class="lf-form-actions">
          <NcButton variant="tertiary" @click="archiveTarget=null">Cancelar</NcButton>
          <NcButton variant="primary" :disabled="catalogBusy" @click="archiveReport">Arquivar modelo</NcButton>
        </div>
      </div>
    </NcDialog>
  </div>
</template>

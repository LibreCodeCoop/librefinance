<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { computed, inject, onBeforeUnmount, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcPopover from '@nextcloud/vue/components/NcPopover'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { mdiPlus, mdiFilterVariant, mdiRefresh, mdiBankOutline, mdiArrowLeftRight } from '@mdi/js'
import LfPageHeader from '../components/LfPageHeader.vue'
import LfDateField from '../components/LfDateField.vue'
import LfSelect from '../components/LfSelect.vue'
import CashPaymentDialog from '../components/CashPaymentDialog.vue'
import { financeApi, financeError } from '../services/api.js'
import { toMinorUnits } from '../money.js'

const w = inject('lfWorkspace')
const navigate = inject('lfNavigate', () => {})
const focusedInvoice = inject('lfFocusedInvoice', ref(null))
const focusedBank = inject('lfBankFocus', ref(null))
const directionHint = inject('lfFinanceDirectionHint', ref('all'))
const bankContext = inject('lfBankContext', ref(0))
const rows = ref([])
const nextCursor = ref(null)
const loading = ref(false)
const error = ref('')
const success = ref('')
const everHadRows = ref(false)
const accountFilter = ref(Number(bankContext.value) || 0)
const directionFilter = ref('all')
const statusFilter = ref('all')
const searchDraft = ref('')
const search = ref('')
const searchTimer = ref(null)
let requestVersion = 0
const selectedBank = computed(() => w.banks.value.find(a => Number(a.id) === Number(accountFilter.value)))
const banks = computed(() => w.banks.value)
const showFilters = computed(() => everHadRows.value || rows.value.length > 0
  || !!search.value || directionFilter.value !== 'all'
  || statusFilter.value !== 'all' || !!accountFilter.value)
const filterOptions = [
  { value:'all', label:'Todas' }, {value:'in',label:'Entradas'},
  {value:'out',label:'Saídas'},
]
const stateOptions = [
  {value:'all',label:'Todas'}, {value:'posted',label:'Realizadas'},
  {value:'planned',label:'Previstas'}, {value:'pending',label:'A conferir'},
]
function enabled(moduleId) {
  return w.modulesLoadedFor.value === Number(w.activeId.value)
    && w.modules.value.some(m => m.id === moduleId && m.enabled)
}
const canPlan = computed(() => enabled('librefinance_obligations'))
const canReviewBank = computed(() => enabled('librefinance_banking'))
const canInvoice = computed(() => enabled('librefinance_invoicing'))
function query(cursor = '') {
  const query = new URLSearchParams({
    direction:directionFilter.value, state:statusFilter.value,
    accountId:String(accountFilter.value), search:search.value,
  })
  if (cursor) query.set('cursor', cursor)
  return '/activity?' + query.toString()
}
async function load() {
  const token = ++requestVersion
  rows.value = []
  nextCursor.value = null
  error.value = ''
  if (!w.activeId.value || w.modulesLoadedFor.value !== Number(w.activeId.value)) {
    loading.value = false
    return
  }
  const spaceId = Number(w.activeId.value)
  loading.value = true
  try {
    const result = await financeApi('librefinance', spaceId, query())
    if (token !== requestVersion || spaceId !== Number(w.activeId.value)) return
    rows.value = result.items || []
    nextCursor.value = result.nextCursor || null
    if (rows.value.length) everHadRows.value = true
  } catch (cause) {
    if (token === requestVersion) error.value = financeError(cause)
  } finally {
    if (token === requestVersion) loading.value = false
  }
}
async function more() {
  if (!nextCursor.value || loading.value) return
  const token = requestVersion
  const spaceId = Number(w.activeId.value)
  const cursor = nextCursor.value
  loading.value = true
  try {
    const result = await financeApi('librefinance', spaceId, query(cursor))
    if (token !== requestVersion || spaceId !== Number(w.activeId.value)) return
    rows.value = [...rows.value, ...result.items]
    nextCursor.value = result.nextCursor || null
  } catch (cause) {
    if (token === requestVersion) error.value = financeError(cause)
  } finally {
    if (token === requestVersion) loading.value = false
  }
}
function setSearch() { search.value = searchDraft.value.trim() }
function debounceSearch() {
  if (searchTimer.value) clearTimeout(searchTimer.value)
  searchTimer.value = setTimeout(setSearch, 260)
}
function setAccount(id) {
  accountFilter.value = Number(id)
  bankContext.value = Number(id)
}
function goToBank() {
  bankContext.value = accountFilter.value
  navigate('banking')
}
function viewInvoice(row) {
  if (!row.invoiceId || !canInvoice.value) return
  directionHint.value = row.kind === 'out' ? 'payable' : 'receivable'
  focusedInvoice.value = {
    spaceId:Number(w.activeId.value),
    id:Number(row.invoiceId),
    kind:row.kind === 'out' ? 'bill' : 'invoice',
  }
  navigate('documents', { preserveDirection:true })
}
function reviewStatement(row) {
  if (!canReviewBank.value) return
  bankContext.value = Number(row.accountId)
  focusedBank.value = {
    spaceId:Number(w.activeId.value),
    transaction: {id:row.sourceId,bank_account_id:row.accountId,
      description:row.description,amount_minor:row.amountMinor,booking_date:row.date,status:'pending'},
  }
  navigate('banking')
}
const payment = ref(null)
const paymentError = ref('')
async function pay(row) {
  paymentError.value = ''
  if (!row.obligationId || !w.writable.value) return
  if (row.invoiceId && canInvoice.value) {
    try {
      const spaceId = Number(w.activeId.value)
      const data = await financeApi('librefinance', spaceId, '/invoices/' + row.invoiceId)
      if (spaceId !== Number(w.activeId.value)) return
      payment.value = {source:'invoice', kind:row.kind==='in'?'receivable':'payable',
        record:data.invoice}
    } catch (cause) { paymentError.value = financeError(cause) }
    return
  }
  payment.value = {
    source:'obligation',kind:row.kind==='in'?'receivable':'payable',
    record:{id:row.obligationId,description:row.description,
      amount_minor:row.totalMinor,paid_minor:row.paidMinor},
  }
}
async function onPaid() {
  success.value = 'Movimentação registrada.'
  await w.refreshCurrent({ preserveView:true })
  load()
}
const formOpen = ref(false)
const formSaving = ref(false)
const formError = ref('')
const kind = ref('out')
const timing = ref('planned')
const today = () => new Date().toLocaleDateString('sv-SE')
const form = ref({ description:'', amount:'', date:today(), dueOn:today(),
  accountId:0, categoryId:0 })
const bankOptions = computed(() => banks.value.map(a => ({value:a.id,label:a.name})))
const counterpartOptions = computed(() => w.accounts.value
  .filter(a => a.active && !a.is_bank
    && a.class === (kind.value==='in' ? 'revenue' : 'expense'))
  .map(a => ({value:a.id,label:a.name})))
function start() {
  kind.value = 'out'
  timing.value = canPlan.value ? 'planned' : 'posted'
  form.value = {
    description:'', amount:'', date:today(), dueOn:today(),
    accountId:accountFilter.value || (banks.value.length===1?banks.value[0].id:0),
    categoryId:counterpartOptions.value.length===1
      ? Number(counterpartOptions.value[0].value) : 0,
  }
  formError.value = ''
  formOpen.value = true
}
watch(counterpartOptions, options => {
  if (!options.some(option => Number(option.value)===Number(form.value.categoryId))) {
    form.value.categoryId = options.length === 1 ? Number(options[0].value) : 0
  }
}, { immediate:true })
async function save() {
  if (!w.writable.value || formSaving.value) return
  formError.value = ''
  const spaceId = Number(w.activeId.value)
  let amountMinor
  try {
    amountMinor = toMinorUnits(form.value.amount)
  } catch (cause) { formError.value = cause.message; return }
  if (!form.value.description.trim() || !amountMinor || amountMinor <= 0) {
    formError.value = 'Informe a descrição e um valor maior que zero.'
    return
  }
  if (timing.value === 'posted' && (!form.value.accountId || !form.value.categoryId)) {
    formError.value = 'Selecione onde o dinheiro entrou ou saiu e a categoria.'
    return
  }
  if (timing.value === 'planned' && !canPlan.value) {
    formError.value = 'O agendamento não está disponível nesta gestão.'
    return
  }
  formSaving.value = true
  try {
    if (timing.value === 'planned') {
      await financeApi('librefinance', spaceId, '/obligations', 'POST', {
        kind:kind.value==='in'?'receivable':'payable',
        description:form.value.description.trim(),
        amountMinor, issuedOn:today(), dueOn:form.value.dueOn,
      })
    } else {
      await financeApi('librefinance', spaceId, '/journals', 'POST', {
        debitId:Number(kind.value==='in'?form.value.accountId:form.value.categoryId),
        creditId:Number(kind.value==='in'?form.value.categoryId:form.value.accountId),
        amountMinor, memo:form.value.description.trim(), date:form.value.date,
        externalKey:'activity:'+crypto.randomUUID(),
      })
    }
    if (spaceId !== Number(w.activeId.value)) return
    formOpen.value = false
    success.value = 'Movimentação registrada.'
    await w.refreshCurrent({ preserveView:true })
    load()
  } catch (cause) {
    if (spaceId === Number(w.activeId.value)) formError.value = financeError(cause)
  } finally { formSaving.value = false }
}
// A transfer is a single balanced journal between the user's own liquid
// accounts. It is never revenue or expense and does not instruct the bank.
const transferOpen = ref(false)
const transferSaving = ref(false)
const transferError = ref('')
const transferForm = ref({ fromId:0, toId:0, amount:'', date:today(), note:'' })
const transferKey = ref('')
function startTransfer() {
  if (!w.writable.value || banks.value.length < 2) return
  const fromId = Number(accountFilter.value) || Number(banks.value[0].id)
  const other = banks.value.find(account => Number(account.id) !== fromId)
  transferForm.value = { fromId, toId:Number(other?.id || 0), amount:'',
    date:today(), note:'Transferência entre minhas contas' }
  transferError.value = ''
  transferKey.value = 'transfer:' + crypto.randomUUID()
  transferOpen.value = true
}
async function saveTransfer() {
  if (transferSaving.value || !w.writable.value) return
  transferError.value = ''
  const current = Number(w.activeId.value)
  const {fromId,toId,note,date} = transferForm.value
  let amountMinor
  try { amountMinor = toMinorUnits(transferForm.value.amount) }
  catch (cause) { transferError.value = cause.message; return }
  if (!amountMinor || amountMinor <= 0
    || Number(fromId) === Number(toId)
    || !banks.value.some(a => Number(a.id) === Number(fromId))
    || !banks.value.some(a => Number(a.id) === Number(toId))) {
    transferError.value = 'Escolha duas contas diferentes e informe o valor.'
    return
  }
  transferSaving.value = true
  try {
    await financeApi('librefinance',current,'/journals','POST',{
      debitId:Number(toId),creditId:Number(fromId),
      amountMinor,date,memo:note.trim()||'Transferência entre minhas contas',
      externalKey:transferKey.value,
    })
    if (current !== Number(w.activeId.value)) return
    transferOpen.value = false
    success.value = 'Transferência registrada entre suas contas. Nenhuma operação foi enviada ao banco.'
    await w.refreshCurrent({preserveView:true})
    load()
  } catch (cause) {
    if (current === Number(w.activeId.value)) transferError.value=financeError(cause)
  } finally { transferSaving.value=false }
}
function clearFilters() {
  setAccount(0)
  directionFilter.value = 'all'
  statusFilter.value = 'all'
  searchDraft.value = ''
  search.value = ''
}
watch([w.activeId,w.modulesLoadedFor], () => {
  formOpen.value = false
  transferOpen.value = false
  payment.value = null
  everHadRows.value = false
  clearFilters()
})
watch([w.activeId,w.modulesLoadedFor,accountFilter,directionFilter,statusFilter,search], load,
  {immediate:true})
onBeforeUnmount(() => {
  requestVersion++
  if (searchTimer.value) clearTimeout(searchTimer.value)
})
const stateLabel = row => ({
  planned:'Prevista',posted:row.reversal?'Estorno':'Realizada',
  pending:'A conferir',
})[row.state] || row.state
const directionLabel = row => row.kind==='transfer'?'Transferência':
  row.kind==='in'?'Entrada':'Saída'
const currency = row => w.money(row.amountMinor)
</script>

<template>
  <div class="lf-view lf-activity-view">
    <LfPageHeader title="Movimentações" eyebrow="FINANCEIRO"
      description="Tudo que vai entrar ou sair e o que já aconteceu, em uma lista. Os lançamentos do banco que precisam de conferência ficam identificados.">
      <template #actions>
        <NcButton v-if="w.writable.value" variant="primary" @click="start">
          <template #icon><NcIconSvgWrapper :path="mdiPlus" :size="20" /></template>
          Registrar
        </NcButton>
      </template>
    </LfPageHeader>
    <NcNoteCard v-if="error" type="error" role="alert">{{error}}</NcNoteCard>
    <NcNoteCard v-if="paymentError" type="error" role="alert">{{paymentError}}</NcNoteCard>
    <NcNoteCard v-if="success" type="success" role="status">{{success}}</NcNoteCard>

    <div v-if="banks.length" class="lf-activity-accounts">
      <NcPopover popup-role="dialog">
        <template #trigger>
          <NcButton variant="secondary" id="lf-activity-bank">
            <template #icon><NcIconSvgWrapper :path="mdiBankOutline" :size="18" /></template>
            {{selectedBank?.name || 'Todas as contas'}}
          </NcButton>
        </template>
        <div class="lf-agenda-filter-content" role="group" aria-label="Escolher conta financeira">
          <NcButton variant="tertiary" :aria-pressed="accountFilter===0"
            @click="setAccount(0)">Todas as contas</NcButton>
          <NcButton v-for="bank in banks" :key="bank.id" variant="tertiary"
            :aria-pressed="Number(accountFilter)===Number(bank.id)"
            @click="setAccount(bank.id)">{{bank.name}}</NcButton>
        </div>
      </NcPopover>
      <span v-if="selectedBank" class="lf-muted lf-activity-balance">
        Saldo registrado: <strong>{{w.money(selectedBank.balance_minor)}}</strong>
      </span>
      <NcButton variant="tertiary" @click="navigate('bankAccounts')">
        Gerenciar contas
      </NcButton>
      <NcButton v-if="w.writable.value && banks.length>1" variant="tertiary"
        @click="startTransfer">
        Transferir entre contas
      </NcButton>
      <NcButton v-if="canReviewBank" variant="tertiary" @click="goToBank()">
        Conferir extrato
      </NcButton>
    </div>
    <div v-else class="lf-activity-first-account">
      <NcButton variant="secondary" @click="navigate('bankAccounts')">
        <template #icon><NcIconSvgWrapper :path="mdiPlus" :size="18" /></template>
        Adicionar conta ou caixa
      </NcButton>
    </div>

    <div v-if="showFilters" class="lf-section-heading lf-activity-toolbar">
      <NcTextField v-model="searchDraft" type="search" class="lf-native-finance-search"
        label="Pesquisar movimentações" placeholder="Pesquisar pela descrição"
        maxlength="120" @update:model-value="debounceSearch" @keyup.enter="setSearch" />
      <div class="lf-native-agenda-filters">
        <NcPopover popup-role="dialog">
          <template #trigger>
            <NcButton variant="tertiary" id="lf-activity-direction"
              :pressed="directionFilter!=='all'">
              <template #icon><NcIconSvgWrapper :path="mdiFilterVariant" :size="18" /></template>
              {{filterOptions.find(o=>o.value===directionFilter)?.label}}
            </NcButton>
          </template>
          <div class="lf-agenda-filter-content" role="group" aria-label="Filtrar entradas e saídas">
            <NcButton v-for="option in filterOptions" :key="option.value" variant="tertiary"
              :aria-pressed="directionFilter===option.value"
              @click="directionFilter=option.value">{{option.label}}</NcButton>
          </div>
        </NcPopover>
        <NcPopover popup-role="dialog">
          <template #trigger>
            <NcButton variant="tertiary" id="lf-activity-status"
              :pressed="statusFilter!=='all'">
              <template #icon><NcIconSvgWrapper :path="mdiFilterVariant" :size="18" /></template>
              {{stateOptions.find(o=>o.value===statusFilter)?.label}}
            </NcButton>
          </template>
          <div class="lf-agenda-filter-content" role="group" aria-label="Filtrar situação financeira">
            <NcButton v-for="option in stateOptions" :key="option.value" variant="tertiary"
              :aria-pressed="statusFilter===option.value"
              @click="statusFilter=option.value">{{option.label}}</NcButton>
          </div>
        </NcPopover>
        <NcButton v-if="statusFilter!=='all'||directionFilter!=='all'||search"
          variant="tertiary" @click="clearFilters">Limpar filtros</NcButton>
      </div>
    </div>

    <div v-if="loading && !rows.length" role="status" class="lf-loading">
      <NcLoadingIcon :size="32" /> Carregando movimentações…
    </div>
    <NcEmptyContent v-else-if="!error && !rows.length"
      :name="showFilters ? 'Nenhuma movimentação com estes filtros' : 'Nenhuma movimentação registrada'"
      :description="showFilters
        ? 'Altere os filtros para consultar outras movimentações.'
        : 'Registre o primeiro valor que entrou, saiu ou está previsto.'"/>
    <div v-else-if="rows.length" class="lf-table-scroll">
      <table class="lf-data-table lf-activity-table">
        <thead><tr>
          <th>Data</th><th>Descrição</th><th>Tipo</th><th>Conta</th>
          <th>Situação</th><th class="lf-numeric">Valor</th><th>Ações</th>
        </tr></thead>
        <tbody>
          <tr v-for="row in rows" :key="row.source+':'+row.sourceId">
            <td>{{row.date}}</td>
            <td><strong>{{row.description}}</strong>
              <span v-if="row.source==='statement'" class="lf-muted lf-activity-sub">
                Extrato ainda não conferido; não altera o saldo.
              </span>
              <span v-if="row.invoiceId" class="lf-muted lf-activity-sub">Vinculada a fatura</span>
              <span v-if="row.kind==='transfer'" class="lf-muted lf-activity-sub">
                Entre contas próprias · não é receita nem despesa
              </span>
            </td>
            <td>{{directionLabel(row)}}</td>
            <td>{{row.accountId?w.accountNameById(row.accountId):'—'}}</td>
            <td><span :class="['lf-state', row.state==='pending'?'lf-state-warning'
              :row.state==='posted'?'lf-state-good':'']">{{stateLabel(row)}}</span></td>
            <td class="lf-numeric"><strong
              :class="row.kind==='in'?'lf-amount-in':row.kind==='out'?'lf-amount-out':''">
              {{currency(row)}}
            </strong></td>
            <td class="lf-row-actions">
              <NcButton v-if="row.state==='planned' && w.writable.value"
                variant="tertiary" size="small" @click="pay(row)">
                {{row.kind==='in'?'Receber':'Pagar'}}
              </NcButton>
              <NcButton v-if="row.invoiceId && canInvoice" variant="tertiary"
                size="small" @click="viewInvoice(row)">Fatura</NcButton>
              <NcButton v-if="row.state==='pending' && canReviewBank" variant="tertiary"
                size="small" @click="reviewStatement(row)">Conferir</NcButton>
              <span v-if="row.state==='posted'" class="lf-muted">Registrada</span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <div v-if="nextCursor" class="lf-table-footer">
      <NcButton variant="tertiary" :disabled="loading" @click="more">Carregar mais movimentações</NcButton>
    </div>
    <NcButton v-if="rows.length" variant="tertiary" size="small" @click="load">
      <template #icon><NcIconSvgWrapper :path="mdiRefresh" :size="18" /></template>
      Atualizar
    </NcButton>

    <NcDialog :open="formOpen" size="normal" content-classes="lf-finance-dialog-content"
      name="Registrar movimentação" @update:open="!$event && (formOpen=false)">
      <form class="lf-dialog-form lf-activity-entry" @submit.prevent="save">
        <NcNoteCard v-if="formError" type="error" role="alert">{{formError}}</NcNoteCard>
        <fieldset class="lf-entry-direction">
          <legend>O dinheiro vai entrar ou sair?</legend>
          <div class="lf-entry-direction-options">
            <button type="button" :class="{selected:kind==='in'}" :aria-pressed="kind==='in'"
              @click="kind='in'">
              <span><strong>Entrada</strong><small>Dinheiro que recebo</small></span>
            </button>
            <button type="button" :class="{selected:kind==='out'}" :aria-pressed="kind==='out'"
              @click="kind='out'">
              <span><strong>Saída</strong><small>Dinheiro que pago</small></span>
            </button>
          </div>
        </fieldset>
        <fieldset class="lf-entry-direction">
          <legend>Quando?</legend>
          <div class="lf-entry-direction-options">
            <button type="button" :class="{selected:timing==='posted'}"
              :aria-pressed="timing==='posted'" @click="timing='posted'">
              <span><strong>Já aconteceu</strong><small>Registrei o dinheiro</small></span>
            </button>
            <button v-if="canPlan" type="button" :class="{selected:timing==='planned'}"
              :aria-pressed="timing==='planned'" @click="timing='planned'">
              <span><strong>Vai acontecer</strong><small>Vou pagar ou receber</small></span>
            </button>
          </div>
        </fieldset>
        <NcTextField v-model="form.description" label="Descrição" maxlength="255"
          required placeholder="Ex.: Conta de luz ou pagamento de cliente"/>
        <NcTextField v-model="form.amount" label="Valor" inputmode="decimal"
          placeholder="0,00" required />
        <LfDateField v-if="timing==='planned'" id="lf-activity-due"
          v-model="form.dueOn" label="Quando deve acontecer?" />
        <template v-else>
          <LfDateField id="lf-activity-date" v-model="form.date" label="Quando aconteceu?" />
          <LfSelect v-if="bankOptions.length" id="lf-activity-payment-account"
            v-model="form.accountId" :options="bankOptions" label="Em qual conta?" />
          <NcNoteCard v-else type="info">
            Cadastre primeiro a conta bancária ou o caixa onde o dinheiro entrou ou saiu.
            <NcButton variant="tertiary" @click="formOpen=false;navigate('bankAccounts')">
              Adicionar conta
            </NcButton>
          </NcNoteCard>
          <LfSelect v-if="counterpartOptions.length>1" id="lf-activity-category"
            v-model="form.categoryId" :options="counterpartOptions" label="Categoria" />
          <NcNoteCard v-if="!counterpartOptions.length" type="warning">
            Ainda não há uma categoria de {{kind==='in'?'receita':'despesa'}} configurada.
            Configure-a em Gerenciar para registrar esse movimento.
          </NcNoteCard>
        </template>
        <div class="lf-form-actions">
          <NcButton variant="tertiary" :disabled="formSaving" @click="formOpen=false">
            Cancelar
          </NcButton>
          <NcButton variant="primary" type="submit" :disabled="formSaving
            || (timing==='posted'&&(!form.accountId||!form.categoryId))">
            {{formSaving?'Salvando…':'Salvar'}}
          </NcButton>
        </div>
      </form>
    </NcDialog>
    <NcDialog :open="transferOpen" size="normal"
      content-classes="lf-finance-dialog-content" name="Transferir entre contas"
      @update:open="!$event && !transferSaving && (transferOpen=false)">
      <form class="lf-dialog-form" @submit.prevent="saveTransfer">
        <NcNoteCard v-if="transferError" type="error" role="alert">{{transferError}}</NcNoteCard>
        <p class="lf-muted">Registre uma transferência que você realizou entre suas contas.
          Nenhum dinheiro será enviado pelo aplicativo.</p>
        <LfSelect id="lf-transfer-from" v-model="transferForm.fromId"
          label="Saiu de" :options="bankOptions" :disabled="transferSaving" />
        <LfSelect id="lf-transfer-to" v-model="transferForm.toId"
          label="Entrou em" :options="bankOptions" :disabled="transferSaving" />
        <NcTextField v-model="transferForm.amount" label="Valor" inputmode="decimal"
          :disabled="transferSaving" required/>
        <LfDateField id="lf-transfer-date" v-model="transferForm.date"
          label="Quando?" />
        <NcTextField v-model="transferForm.note" label="Descrição"
          :disabled="transferSaving" maxlength="255"/>
        <div class="lf-form-actions">
          <NcButton variant="tertiary" :disabled="transferSaving"
            @click="transferOpen=false">Cancelar</NcButton>
          <NcButton variant="primary" type="submit"
            :disabled="transferSaving||!transferForm.fromId||!transferForm.toId
              ||transferForm.fromId===transferForm.toId">
            {{transferSaving?'Salvando…':'Registrar transferência'}}
          </NcButton>
        </div>
      </form>
    </NcDialog>
    <CashPaymentDialog :open="Boolean(payment)" :source="payment?.source||'obligation'"
      :record="payment?.record" :kind="payment?.kind||'payable'"
      @update:open="!$event && (payment=null)" @paid="onPaid" />
  </div>
</template>

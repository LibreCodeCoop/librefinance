<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { computed, inject, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcPopover from '@nextcloud/vue/components/NcPopover'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import LfSortableHeader from '../components/LfSortableHeader.vue'
import { mdiPlus, mdiLinkVariant, mdiCashCheck, mdiCheckCircleOutline, mdiCancel, mdiFilterVariant } from '@mdi/js'
import LfPageHeader from '../components/LfPageHeader.vue'
import LfSelect from '../components/LfSelect.vue'
import LfDateField from '../components/LfDateField.vue'
import CashPaymentDialog from '../components/CashPaymentDialog.vue'
import { financeApi, financeError } from '../services/api.js'
import { toMinorUnits } from '../money.js'
const props=defineProps({
  kind: { type: String, default: 'all' },
  embedded: { type: Boolean, default: false },
})
const flow=inject('lfFlowContext',null)
const w=inject('lfWorkspace')
const navigate=inject('lfNavigate',()=>{})
const focusedInvoice=inject('lfFocusedInvoice',ref(null))
const focusedObligation=inject('lfFocusedObligation',ref(null))
const { activeId, money, writable, manager, contacts, contactNextAfterId, loadMoreCatalog }=w
const canReadInvoices=computed(()=>w.modulesLoadedFor.value===Number(activeId.value)
  &&w.modules.value.some(item=>item.id==='librefinance_invoicing'&&item.enabled))
const today=new Date().toLocaleDateString('sv-SE')
const list=ref([])
const hasListedRecords=ref(false)
const next=ref(null)
const filter=ref('all')
const kindFilter=ref(props.kind)
const directionChoices=[
  {value:'all',label:'Tudo'},
  {value:'receivable',label:'A receber'},
  {value:'payable',label:'A pagar'},
]
const dueRange=ref('all')
const sortBy=ref('due')
const sortDirection=ref('asc')
const searchDraft=ref(new URLSearchParams(window.location.search).get('search') || '')
const searchTerm=ref(searchDraft.value.trim())
let searchTimer=null
function applySearch() {
  const cleaned=searchDraft.value.trim().slice(0,120)
  if (searchTerm.value === cleaned) return
  searchTerm.value=cleaned
  const url=new URL(window.location.href)
  if (cleaned) url.searchParams.set('search',cleaned)
  else url.searchParams.delete('search')
  window.history.replaceState(null,'',url.pathname+url.search)
}
function scheduleSearch() {
  if (searchTimer) clearTimeout(searchTimer)
  searchTimer=setTimeout(applySearch,260)
}
function sort(column) {
  if (!['due','amount','description','reference'].includes(column)) return
  if (sortBy.value === column) sortDirection.value = sortDirection.value === 'asc' ? 'desc' : 'asc'
  else { sortBy.value=column; sortDirection.value='asc' }
}
function sortable(column) {
  return sortBy.value === column ? (sortDirection.value === 'asc'?'ascending':'descending') : 'none'
}
const dueChoices=[
  {value:'all',label:'Todas as datas'},
  {value:'overdue',label:'Vencidas'},
  {value:'next7',label:'Próximos 7 dias'},
]
const signedAmount=(row,minor)=>
  (row.kind==='receivable'?'+':'−')+money(Math.abs(Number(minor)))
const loading=ref(false)
const error=ref('')
const success=ref('')
const formOpen=ref(false)
const settlement=ref(null)
const cashTarget=ref(null)
const existingTarget=ref(null)
async function onExistingLinked(result) {
  existingTarget.value=null
  success.value='Lançamento existente vinculado ao compromisso, sem nova movimentação. #'
    +result.journalId+'.'
  await load()
}
async function onCashPaid(result) {
  cashTarget.value=null
  success.value='Movimentação e compromisso atualizados juntos. Lançamento #'
    +result.journalId+'.'
  await load()
}
const cancelTarget=ref(null)
const focusedRecord=ref(null)
const focusedError=ref('')
const displayedRows=computed(()=>focusedRecord.value
  && !list.value.some(row=>Number(row.id)===Number(focusedRecord.value.id))
  ? [focusedRecord.value,...list.value] : list.value)
let focusedSequence=0
function openLinkedDocument(row) {
  if(!row.linkedInvoiceId||!canReadInvoices.value)return
  focusedInvoice.value={spaceId:Number(activeId.value),
    id:Number(row.linkedInvoiceId),
    kind:row.kind==='receivable'?'invoice':'bill'}
  focusedRecord.value=null
  if(flow)flow.showDocument(row)
  else navigate(row.kind==='receivable'?'sales':'purchases')
}
async function inspectFocusedTitle() {
  const target=focusedObligation.value
  if(!target||Number(target.spaceId)!==Number(activeId.value))return
  focusedObligation.value=null
  filter.value='all'
  await nextTick()
  const token=++focusedSequence
  focusedError.value=''
  try {
    const result=await financeApi('librefinance_obligations',
      activeId.value,'/obligations/'+Number(target.id))
    if(token===focusedSequence)focusedRecord.value=result.obligation
  }catch(e){
    if(token===focusedSequence)focusedError.value=financeError(e)
  }
}
const draft=ref({kind:props.kind==='all'?'payable':props.kind,
  reference:'',description:'',amount:'',issuedOn:today,dueOn:today,contactId:0})
watch(() => flow?.newSimple.value, value => {
  if (!value || !writable.value || !activeId.value) return
  draft.value={kind:props.kind==='all'?'payable':props.kind,
    reference:'',description:'',amount:'',issuedOn:today,dueOn:today,contactId:0}
  formOpen.value=true
  flow.newSimple.value=false
}, { immediate:true })
const settleForm=ref({amount:'',paidOn:today,note:''})
const choices=[
  {value:'all',label:'Todas'},
  {value:'open',label:'Em aberto'},
  {value:'partial',label:'Parcialmente baixadas'},
  {value:'settled',label:'Liquidadas'},
  {value:'cancelled',label:'Canceladas'},
]
const kinds=[{value:'payable',label:'A pagar'},{value:'receivable',label:'A receber'}]
const contactChoices=computed(()=>[
  {value:0,label:'Sem contato'},
  ...contacts.value.map(c=>({value:c.id,label:c.name})),
])
const outstanding=row=>Number(row.amount_minor)-Number(row.paid_minor)
const overdue=row=>['open','partial'].includes(row.status)&&row.due_on<today
let generation=0
async function load() {
  const token=++generation
  loading.value=true;error.value=''
  try{
    const data=await financeApi('librefinance_obligations',activeId.value,
      '/obligations?kind='+kindFilter.value+'&status='+filter.value+'&dueRange='+dueRange.value
        +'&search='+encodeURIComponent(searchTerm.value)
        +'&sortBy='+sortBy.value+'&sortDirection='+sortDirection.value)
    if(token!==generation)return
    list.value=data.obligations
    if (data.obligations.length) hasListedRecords.value=true
    next.value=data.nextAfterId
  }catch(e){if(token===generation)error.value=financeError(e)}
  finally{if(token===generation)loading.value=false}
}
async function more() {
  if(!next.value||loading.value)return
  const token=generation
  loading.value=true
  try{
    const data=await financeApi('librefinance_obligations',activeId.value,
      '/obligations?kind='+kindFilter.value+'&status='+filter.value
        +'&dueRange='+dueRange.value+'&search='+encodeURIComponent(searchTerm.value)
        +'&sortBy='+sortBy.value+'&sortDirection='+sortDirection.value
        +'&afterId='+next.value)
    if(token!==generation)return
    list.value=[...list.value,...data.obligations];next.value=data.nextAfterId
  }catch(e){if(token===generation)error.value=financeError(e)}
  finally{if(token===generation)loading.value=false}
}
async function perform(task,notice) {
  if(loading.value)return false
  const token=++generation
  loading.value=true;error.value='';success.value=''
  try{
    await task()
    if(token!==generation)return false
    success.value=notice
    await load()
    return true
  }catch(e){if(token===generation)error.value=financeError(e);return false}
  finally{if(token===generation)loading.value=false}
}
async function create() {
  if(!draft.value.description.trim()||!draft.value.amount||!draft.value.dueOn)return
  const result=await perform(async()=>financeApi('librefinance_obligations',
    activeId.value,'/obligations','POST',{
      kind:props.kind==='all'?draft.value.kind:props.kind,reference:draft.value.reference.trim(),
      description:draft.value.description.trim(),amountMinor:toMinorUnits(draft.value.amount),
      issuedOn:draft.value.issuedOn,dueOn:draft.value.dueOn,
      contactId:Number(draft.value.contactId)||null,
    }),'Título registrado.')
  if(result){
    formOpen.value=false
    draft.value={kind:props.kind==='all'?'payable':props.kind,
      reference:'',description:'',amount:'',issuedOn:today,dueOn:today,contactId:0}
  }
}
function beginSettlement(row) {
  settlement.value=row
  settleForm.value={amount:(Math.floor(outstanding(row)/100)+','
    +String(outstanding(row)%100).padStart(2,'0')),paidOn:today,note:''}
}
async function settle() {
  if(!settlement.value)return
  const target=settlement.value
  const result=await perform(async()=>financeApi('librefinance_obligations',
    activeId.value,'/obligations/'+target.id+'/settlements','POST',{
      amountMinor:toMinorUnits(settleForm.value.amount),
      paidOn:settleForm.value.paidOn,operationKey:'web:'+crypto.randomUUID(),
      note:settleForm.value.note.trim()||null,
    }),'Baixa administrativa registrada. Nenhum lançamento contábil foi feito.')
  if(result)settlement.value=null
}
async function cancel() {
  if(!cancelTarget.value)return
  const id=cancelTarget.value.id
  const result=await perform(async()=>financeApi('librefinance_obligations',
    activeId.value,'/obligations/'+id+'/cancel','POST',{}),'Título cancelado.')
  if(result)cancelTarget.value=null
}
watch(()=>props.kind, value=>{kindFilter.value=value})
watch([kindFilter,filter,dueRange,sortBy,sortDirection,searchTerm],()=>{
  focusedRecord.value=null
  list.value=[]
  next.value=null
  load()
})
watch(activeId,()=>{focusedRecord.value=null;hasListedRecords.value=false;++focusedSequence;load()})
watch(focusedObligation,inspectFocusedTitle,{immediate:true})
onMounted(load)
onBeforeUnmount(()=>{++generation;++focusedSequence;if(searchTimer)clearTimeout(searchTimer)})
</script>
<template>
  <div class="lf-view">
    <LfPageHeader v-if="!embedded" title="A pagar e receber" eyebrow="FINANCEIRO"
      description="Acompanhe vencimentos e compromissos pendentes. Faturas confirmadas criam o título vinculado automaticamente.">
      <template #actions>
        <NcButton v-if="writable" variant="primary" @click="formOpen=true">
          <template #icon><NcIconSvgWrapper :path="mdiPlus" :size="20" /></template>
          Novo título
        </NcButton>
      </template>
    </LfPageHeader>
    <NcNoteCard v-if="!embedded" type="info">
      Faturas confirmadas geram o título financeiro vinculado.
      A baixa de um título avulso é administrativa: não gera movimentação bancária
      ou lançamento contábil. Títulos vinculados são liquidados pelo documento
      de origem, usando um lançamento já contabilizado.
    </NcNoteCard>
    <NcNoteCard v-if="error" type="error" role="alert">{{error}}</NcNoteCard>
    <NcNoteCard v-if="success" type="success" role="status">{{success}}</NcNoteCard>
    <NcNoteCard v-if="focusedError" type="error">{{focusedError}}</NcNoteCard>
    <NcNoteCard v-if="focusedRecord" type="info">
      Registro selecionado: {{focusedRecord.description}} ·
      {{money(outstanding(focusedRecord))}} em aberto.
    </NcNoteCard>
    <section class="lf-surface">
      <div v-if="displayedRows.length || hasListedRecords" class="lf-section-heading lf-obligation-filters">
        <div><h2>{{embedded?'Agenda de entradas e saídas':'Títulos financeiros'}}</h2>
          <p class="lf-muted">Valores previstos e baixas, sem misturar com o extrato bancário.</p>
        </div>
        <div class="lf-native-agenda-filters">
          <NcTextField v-model="searchDraft" class="lf-native-finance-search"
            type="search" label="Pesquisar na agenda" placeholder="Descrição ou referência"
            maxlength="120" @update:model-value="scheduleSearch" @keyup.enter="applySearch" />
          <NcPopover popup-role="dialog">
            <template #trigger>
              <NcButton id="lf-finance-direction" variant="tertiary"
                :pressed="kindFilter !== 'all'"
                :aria-label="'Direção: ' + directionChoices.find(option => option.value === kindFilter)?.label">
                <template #icon><NcIconSvgWrapper :path="mdiFilterVariant" :size="20" /></template>
                Direção: {{ directionChoices.find(option => option.value === kindFilter)?.label }}
              </NcButton>
            </template>
            <div class="lf-agenda-filter-content" role="group" aria-label="Filtrar por direção">
              <NcButton v-for="option in directionChoices" :key="option.value"
                variant="tertiary" :aria-pressed="kindFilter === option.value"
                @click="kindFilter=option.value">{{option.label}}</NcButton>
            </div>
          </NcPopover>
          <NcPopover popup-role="dialog">
            <template #trigger>
              <NcButton id="lf-obligations-filter" variant="tertiary"
                :aria-label="'Situação: ' + choices.find(option => option.value === filter)?.label"
                :pressed="filter !== 'all'">
                <template #icon><NcIconSvgWrapper :path="mdiFilterVariant" :size="20" /></template>
                Situação: {{ choices.find(option => option.value === filter)?.label }}
              </NcButton>
            </template>
            <div class="lf-agenda-filter-content" role="group" aria-label="Filtrar por situação">
              <NcButton v-for="option in choices" :key="option.value"
                variant="tertiary" :aria-pressed="filter === option.value"
                @click="filter=option.value">{{option.label}}</NcButton>
            </div>
          </NcPopover>
          <NcPopover popup-role="dialog">
            <template #trigger>
              <NcButton id="lf-obligations-due" variant="tertiary"
                :aria-label="'Vencimento: ' + dueChoices.find(option => option.value === dueRange)?.label"
                :pressed="dueRange !== 'all'">
                <template #icon><NcIconSvgWrapper :path="mdiFilterVariant" :size="20" /></template>
                Vencimento: {{ dueChoices.find(option => option.value === dueRange)?.label }}
              </NcButton>
            </template>
            <div class="lf-agenda-filter-content" role="group" aria-label="Filtrar por vencimento">
              <NcButton v-for="option in dueChoices" :key="option.value"
                variant="tertiary" :aria-pressed="dueRange === option.value"
                @click="dueRange=option.value">{{option.label}}</NcButton>
            </div>
          </NcPopover>
        </div>
      </div>
      <div v-if="loading && !displayedRows.length" class="lf-agenda-empty lf-loading" role="status">
        <NcLoadingIcon :size="44" />
        <span>Carregando títulos financeiros…</span>
      </div>
      <NcEmptyContent v-else-if="!error && !displayedRows.length"
        class="lf-agenda-empty"
        :name="kindFilter !== 'all' || filter !== 'all' || dueRange !== 'all' || searchTerm ? 'Nenhum resultado para os filtros' : 'Nenhum valor a pagar ou receber'"
        :description="kindFilter !== 'all' || filter !== 'all' || dueRange !== 'all' || searchTerm
          ? 'Altere os filtros ou a pesquisa para ver outros títulos.'
          : 'Cadastre uma conta ou cobrança; faturas confirmadas também aparecem aqui automaticamente.'">
        <template v-if="kindFilter !== 'all' || filter !== 'all' || dueRange !== 'all' || searchTerm" #action>
          <NcButton @click="kindFilter='all'; filter='all'; dueRange='all'; searchDraft=''; applySearch()">Limpar filtros</NcButton>
        </template>
        <template v-else-if="writable && !embedded" #action>
          <NcButton variant="primary" @click="formOpen=true">Novo título</NcButton>
        </template>
      </NcEmptyContent>
      <div v-else-if="displayedRows.length" class="lf-table-scroll">
        <table class="lf-data-table">
          <thead><tr>
            <th :aria-sort="sortable('reference')">
              <LfSortableHeader label="Referência" column="reference" :active-column="sortBy"
                :direction="sortDirection" @sort="sort" />
            </th>
            <th :aria-sort="sortable('description')">
              <LfSortableHeader label="Descrição" column="description" :active-column="sortBy"
                :direction="sortDirection" @sort="sort" />
            </th>
            <th>Direção</th><th>Origem</th>
            <th :aria-sort="sortable('due')">
              <LfSortableHeader label="Vencimento" column="due" :active-column="sortBy"
                :direction="sortDirection" @sort="sort" />
            </th>
            <th class="lf-numeric" :aria-sort="sortable('amount')">
              <LfSortableHeader label="Valor previsto" column="amount" :active-column="sortBy"
                :direction="sortDirection" @sort="sort" />
            </th>
            <th class="lf-numeric">Saldo previsto</th><th>Situação</th>
            <th v-if="writable">Ações</th>
          </tr></thead>
          <tbody><tr v-for="row in displayedRows" :key="row.id"
            :class="{'lf-row-focused':focusedRecord && Number(focusedRecord.id)===Number(row.id)}">
            <td class="lf-code">{{row.reference}}</td>
            <td><strong>{{row.description}}</strong></td>
            <td><span class="lf-flow-direction"
                :class="row.kind==='receivable'?'lf-flow-direction-in':'lf-flow-direction-out'">
              {{row.kind==='receivable'?'Entrada prevista':'Saída prevista'}}
            </span></td>
            <td>
              <template v-if="row.linkedInvoiceId">
                <span>Fatura #{{row.linkedInvoiceId}}</span>
                <NcButton v-if="canReadInvoices" size="small" variant="tertiary"
                  @click="openLinkedDocument(row)">Abrir fatura</NcButton>
                <span v-else class="lf-muted"> · Acesso ao documento indisponível</span>
              </template>
              <span v-else>Título avulso</span>
            </td>
            <td>{{row.due_on}}</td>
            <td class="lf-numeric"><strong
              :class="row.kind==='receivable'?'lf-amount-in':'lf-amount-out'">
              {{signedAmount(row,row.amount_minor)}}
            </strong></td>
            <td class="lf-numeric">
              <strong :class="row.kind==='receivable'?'lf-amount-in':'lf-amount-out'">
                {{signedAmount(row,outstanding(row))}}
              </strong>
              <span v-if="row.ledgerPaidMinor" class="lf-muted">
                Movimentado: {{money(row.ledgerPaidMinor)}}
              </span>
              <span v-if="row.administrativePaidMinor" class="lf-muted">
                Baixa sem movimentação: {{money(row.administrativePaidMinor)}}
              </span>
            </td>
            <td><span :class="overdue(row)?'lf-state lf-state-warning':'lf-state'">
              {{overdue(row)?'Vencido':({
                open:'Aberto',partial:'Parcial',
                settled:row.administrativePaidMinor>0 && row.ledgerPaidMinor>0
                  ?'Liquidado: movimentação e baixa administrativa'
                  : row.ledgerPaidMinor>0
                    ?'Liquidado por lançamento':'Baixado administrativamente',
                cancelled:'Cancelado'
              }[row.status]||row.status)}}</span></td>
            <td v-if="writable" class="lf-row-actions">
              <NcButton v-if="['open','partial'].includes(row.status)&&!row.linkedInvoiceId"
                size="small" variant="secondary" @click="cashTarget=row">
                <template #icon><NcIconSvgWrapper :path="mdiCashCheck" :size="18" /></template>
                {{row.kind==='receivable'?'Receber':'Pagar'}}
              </NcButton>
              <NcActions :aria-label="'Mais ações de ' + row.description" force-menu
                v-if="['open','partial'].includes(row.status)&&!row.linkedInvoiceId">
                <NcActionButton close-after-click @click="existingTarget=row">
                  <template #icon><NcIconSvgWrapper :path="mdiLinkVariant" :size="20" /></template>
                  Vincular lançamento existente
                </NcActionButton>
                <NcActionButton close-after-click @click="beginSettlement(row)">
                  <template #icon><NcIconSvgWrapper :path="mdiCheckCircleOutline" :size="20" /></template>
                  Baixa sem movimentação
                </NcActionButton>
                <NcActionButton v-if="manager&&row.status==='open'" close-after-click
                  @click="cancelTarget=row">
                  <template #icon><NcIconSvgWrapper :path="mdiCancel" :size="20" /></template>
                  Cancelar título
                </NcActionButton>
              </NcActions>
              <span v-else-if="row.linkedInvoiceId" class="lf-state">
                Liquidação pelo documento de origem
              </span>
            </td>
          </tr></tbody>
        </table>
      </div>
      <div v-if="next" class="lf-table-footer">
        <NcButton :disabled="loading" @click="more">Carregar mais títulos</NcButton>
      </div>
    </section>
    <NcDialog size="normal" content-classes="lf-finance-dialog-content" :open="formOpen"
      :name="embedded ? (props.kind==='receivable'?'Cobrança simples':'Conta simples')
        : 'Novo título financeiro'"
      @update:open="formOpen=$event">
      <form class="lf-dialog-form" @submit.prevent="create">
        <NcNoteCard type="warning">
          Cadastre somente compromissos sem fatura confirmada. Faturas de venda ou
          compra confirmadas geram a conta a pagar ou receber automaticamente. As vendas e compras confirmadas criam os títulos automaticamente.
        </NcNoteCard>
        <LfSelect v-if="props.kind==='all'" id="lf-title-type"
          v-model="draft.kind" :options="kinds" label="Tipo" />
        <p v-else class="lf-muted">
          {{props.kind==='receivable'?'Cobrança simples':'Conta simples'}}
        </p>
        <NcTextField v-model="draft.description" label="Descrição" maxlength="255" required />
        <NcTextField v-model="draft.reference" label="Referência (opcional)"
          maxlength="80" />
        <NcTextField v-model="draft.amount" label="Valor" inputmode="decimal"
          placeholder="0,00" required />
        <div class="lf-form-grid">
          <LfDateField id="lf-title-issued" v-model="draft.issuedOn" label="Emissão" />
          <LfDateField id="lf-title-due" v-model="draft.dueOn" label="Vencimento" />
        </div>
        <LfSelect id="lf-title-contact" v-model="draft.contactId" :options="contactChoices"
          label="Contato (opcional)" />
        <NcButton v-if="contactNextAfterId" variant="tertiary"
          @click="loadMoreCatalog('contacts')">Carregar mais contatos</NcButton>
        <div class="lf-form-actions">
          <NcButton variant="tertiary" @click="formOpen=false">Cancelar</NcButton>
          <NcButton type="submit" variant="primary" :disabled="loading">Cadastrar título</NcButton>
        </div>
      </form>
    </NcDialog>
    <NcDialog size="normal" content-classes="lf-finance-dialog-content" :open="Boolean(settlement)" name="Registrar baixa administrativa (sem banco)"
      @update:open="settlement = $event ? settlement : null">
      <form class="lf-dialog-form" @submit.prevent="settle">
        <p v-if="settlement"><strong>{{settlement.description}}</strong>
          · Em aberto: {{money(outstanding(settlement))}}</p>
        <NcNoteCard type="warning">
          Esta baixa atualiza apenas o compromisso. Não registra entrada ou
          saída bancária; a contabilização e conciliação permanecem separadas.
        </NcNoteCard>
        <NcTextField v-model="settleForm.amount" label="Valor da baixa"
          inputmode="decimal" placeholder="0,00" required />
        <LfDateField id="lf-settle-date" v-model="settleForm.paidOn" label="Data" />
        <NcTextField v-model="settleForm.note" label="Observação" maxlength="255" />
        <div class="lf-form-actions">
          <NcButton variant="tertiary" @click="settlement=null">Cancelar</NcButton>
          <NcButton variant="primary" type="submit" :disabled="loading">
            Registrar baixa
          </NcButton>
        </div>
      </form>
    </NcDialog>
    <CashPaymentDialog :open="Boolean(cashTarget)" source="obligation"
      :record="cashTarget" :kind="cashTarget?.kind ?? props.kind"
      @update:open="cashTarget=$event?cashTarget:null"
      @paid="onCashPaid" />
    <CashPaymentDialog :open="Boolean(existingTarget)" source="obligation"
      :record="existingTarget" :kind="existingTarget?.kind ?? props.kind"
      :existing="true" @update:open="existingTarget=$event?existingTarget:null"
      @paid="onExistingLinked" />
    <NcDialog content-classes="lf-finance-dialog-content" :open="Boolean(cancelTarget)" name="Cancelar título"
      @update:open="cancelTarget = $event ? cancelTarget : null">
      <div class="lf-dialog-form">
        <p>Cancelar <strong>{{cancelTarget?.description}}</strong>? O histórico será preservado.</p>
        <div class="lf-form-actions">
          <NcButton variant="tertiary" @click="cancelTarget=null">Manter título</NcButton>
          <NcButton variant="error" :disabled="loading" @click="cancel">Confirmar cancelamento</NcButton>
        </div>
      </div>
    </NcDialog>
  </div>
</template>

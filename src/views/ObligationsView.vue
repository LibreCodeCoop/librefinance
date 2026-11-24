<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { computed, inject, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { t } from '@nextcloud/l10n'
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
import { formatLedgerDate } from '../date.js'
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
// Explicitly close native popovers on selection. NcPopover normally toggles
// visibility on trigger clicks, which could hide an already-open menu instead.
const directionPopoverShown=ref(false)
const statusPopoverShown=ref(false)
const duePopoverShown=ref(false)
const next=ref(null)
const filter=ref('all')
const kindFilter=ref(props.kind)
const hasActiveFilters=computed(()=>
  (props.kind==='all' && kindFilter.value!=='all')
  || filter.value!=='all' || dueRange.value!=='all' || !!searchTerm.value)
const emptyTitle=computed(() => props.kind==='payable' ? t('librefinance', 'No bills to pay')
  : props.kind==='receivable' ? t('librefinance', 'No collections to receive')
    : t('librefinance', 'Nothing to pay or receive'))
const directionChoices=[
  {value:'all',label:t('librefinance', 'All directions')},
  {value:'receivable',label:t('librefinance', 'Receivables')},
  {value:'payable',label:t('librefinance', 'Payables')},
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
  {value:'all',label:t('librefinance', 'All dates')},
  {value:'overdue',label:t('librefinance', 'Overdue obligations')},
  {value:'next7',label:t('librefinance', 'Next 7 days')},
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
  success.value=t('librefinance', 'Existing journal entry #{id} linked to the obligation without recording a new movement.', { id: result.journalId }, { escape: false, sanitize: false })
  await load()
}
async function onCashPaid(result) {
  cashTarget.value=null
  success.value=t('librefinance', 'Movement and obligation updated together. Journal entry #{id}.', { id: result.journalId }, { escape: false, sanitize: false })
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
  {value:'all',label:t('librefinance', 'All')},
  {value:'open',label:t('librefinance', 'Outstanding')},
  {value:'partial',label:t('librefinance', 'Partially settled obligations')},
  {value:'settled',label:t('librefinance', 'Settled obligations')},
  {value:'cancelled',label:t('librefinance', 'Cancelled obligations')},
]
const directionLabel = computed(() => t('librefinance', 'Direction: {direction}', {
  direction: directionChoices.find(option => option.value === kindFilter.value)?.label ?? '',
}, { escape: false, sanitize: false }))
const statusLabel = computed(() => t('librefinance', 'Status: {status}', {
  status: choices.find(option => option.value === filter.value)?.label ?? '',
}, { escape: false, sanitize: false }))
const dueLabel = computed(() => t('librefinance', 'Due date: {range}', {
  range: dueChoices.find(option => option.value === dueRange.value)?.label ?? '',
}, { escape: false, sanitize: false }))
const contactChoices=computed(()=>[
  {value:0,label:t('librefinance', 'No contact')},
  ...contacts.value.map(c=>({value:c.id,label:c.name})),
])
const outstanding=row=>Number(row.amount_minor)-Number(row.paid_minor)
const overdue=row=>['open','partial'].includes(row.status)&&row.due_on<new Date().toLocaleDateString('sv-SE')
// Keep the calendar anchor stable for keyset continuation, even if the user
// crosses midnight while paging through the currently filtered records.
const referenceDate=ref(new Date().toLocaleDateString('sv-SE'))
let generation=0
async function load() {
  const token=++generation
  referenceDate.value=new Date().toLocaleDateString('sv-SE')
  loading.value=true;error.value=''
  try{
    const data=await financeApi('librefinance_obligations',activeId.value,
      '/obligations?kind='+kindFilter.value+'&status='+filter.value+'&dueRange='+dueRange.value+'&referenceDate='+referenceDate.value
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
        +'&dueRange='+dueRange.value+'&referenceDate='+referenceDate.value+'&search='+encodeURIComponent(searchTerm.value)
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
    }),t('librefinance', 'Obligation recorded.'))
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
    }),t('librefinance', 'Administrative settlement recorded. No journal entry was posted.'))
  if(result)settlement.value=null
}
async function cancel() {
  if(!cancelTarget.value)return
  const id=cancelTarget.value.id
  const result=await perform(async()=>financeApi('librefinance_obligations',
    activeId.value,'/obligations/'+id+'/cancel','POST',{}),t('librefinance', 'Obligation cancelled.'))
  if(result)cancelTarget.value=null
}
watch(()=>props.kind, value=>{kindFilter.value=value})
watch([kindFilter,filter,dueRange,sortBy,sortDirection,searchTerm],()=>{
  focusedRecord.value=null
  list.value=[]
  next.value=null
  load()
})
watch(activeId,()=>{
  focusedRecord.value=null
  hasListedRecords.value=false
  directionPopoverShown.value=false
  statusPopoverShown.value=false
  duePopoverShown.value=false
  ++focusedSequence
  load()
})
watch(focusedObligation,inspectFocusedTitle,{immediate:true})
onMounted(load)
onBeforeUnmount(()=>{++generation;++focusedSequence;if(searchTimer)clearTimeout(searchTimer)})
</script>
<template>
  <div class="lf-view">
    <LfPageHeader v-if="!embedded" :title="t('librefinance', 'Payables and receivables')" :eyebrow="t('librefinance', 'FINANCE')"
      :description="t('librefinance', 'Track due dates and outstanding obligations. Confirmed invoices automatically create the linked obligation.')">
      <template #actions>
        <NcButton v-if="writable" variant="primary" @click="formOpen=true">
          <template #icon><NcIconSvgWrapper :path="mdiPlus" :size="20" /></template>
          {{ t('librefinance', 'New obligation') }}
        </NcButton>
      </template>
    </LfPageHeader>
    <NcNoteCard v-if="!embedded" type="info">
      {{ t('librefinance', 'A confirmed sale or purchase already appears in this list. Do not register the same bill again. A settlement without a movement does not affect the bank account. For actual payments, use Pay or Receive.') }}
    </NcNoteCard>
    <NcNoteCard v-if="error" type="error" role="alert">{{error}}</NcNoteCard>
    <NcNoteCard v-if="success" type="success" role="status">{{success}}</NcNoteCard>
    <NcNoteCard v-if="focusedError" type="error">{{focusedError}}</NcNoteCard>
    <NcNoteCard v-if="focusedRecord" type="info">
      {{ t('librefinance', 'Selected record: {description} · {amount} outstanding.', { description: focusedRecord.description, amount: money(outstanding(focusedRecord)) }, { escape: false, sanitize: false }) }}
    </NcNoteCard>
    <section class="lf-surface">
      <div v-if="displayedRows.length || hasListedRecords" class="lf-section-heading lf-obligation-filters">
        <div><h2>{{props.kind==='payable' ? t('librefinance', 'Expected payments')
          : props.kind==='receivable' ? t('librefinance', 'Expected receipts') : t('librefinance', 'Bills and collections')}}</h2>
          <p class="lf-muted">{{ t('librefinance', 'Amounts you need to pay or receive. Bank statements are reviewed separately.') }}</p>
        </div>
        <div class="lf-native-agenda-filters">
          <NcTextField v-model="searchDraft" class="lf-native-finance-search"
            type="search" :label="t('librefinance', 'Search the schedule')" :placeholder="t('librefinance', 'Description or reference')"
            maxlength="120" @update:model-value="scheduleSearch" @keyup.enter="applySearch" />
          <NcPopover v-if="props.kind==='all'" v-model:shown="directionPopoverShown" popup-role="dialog">
            <template #trigger>
              <NcButton id="lf-finance-direction" variant="tertiary"
                :pressed="kindFilter !== 'all'"
                :aria-label="directionLabel">
                <template #icon><NcIconSvgWrapper :path="mdiFilterVariant" :size="20" /></template>
                {{ directionLabel }}
              </NcButton>
            </template>
            <div class="lf-agenda-filter-content" role="group" :aria-label="t('librefinance', 'Filter by direction')">
              <NcButton v-for="option in directionChoices" :key="option.value"
                variant="tertiary" :aria-pressed="kindFilter === option.value"
                @click="kindFilter=option.value; directionPopoverShown=false">{{option.label}}</NcButton>
            </div>
          </NcPopover>
          <NcPopover v-model:shown="statusPopoverShown" popup-role="dialog">
            <template #trigger>
              <NcButton id="lf-obligations-filter" variant="tertiary"
                :aria-label="statusLabel"
                :pressed="filter !== 'all'">
                <template #icon><NcIconSvgWrapper :path="mdiFilterVariant" :size="20" /></template>
                {{ statusLabel }}
              </NcButton>
            </template>
            <div class="lf-agenda-filter-content" role="group" :aria-label="t('librefinance', 'Filter by status')">
              <NcButton v-for="option in choices" :key="option.value"
                variant="tertiary" :aria-pressed="filter === option.value"
                @click="filter=option.value; statusPopoverShown=false">{{option.label}}</NcButton>
            </div>
          </NcPopover>
          <NcPopover v-model:shown="duePopoverShown" popup-role="dialog">
            <template #trigger>
              <NcButton id="lf-obligations-due" variant="tertiary"
                :aria-label="dueLabel"
                :pressed="dueRange !== 'all'">
                <template #icon><NcIconSvgWrapper :path="mdiFilterVariant" :size="20" /></template>
                {{ dueLabel }}
              </NcButton>
            </template>
            <div class="lf-agenda-filter-content" role="group" :aria-label="t('librefinance', 'Filter by due date')">
              <NcButton v-for="option in dueChoices" :key="option.value"
                variant="tertiary" :aria-pressed="dueRange === option.value"
                @click="dueRange=option.value; duePopoverShown=false">{{option.label}}</NcButton>
            </div>
          </NcPopover>
        </div>
      </div>
      <div v-if="loading && !displayedRows.length" class="lf-agenda-empty lf-loading" role="status">
        <NcLoadingIcon :size="44" />
        <span>{{ t('librefinance', 'Loading financial obligations…') }}</span>
      </div>
      <NcEmptyContent v-else-if="!error && !displayedRows.length"
        class="lf-agenda-empty"
        :name="hasActiveFilters ? t('librefinance', 'No results match these filters') : emptyTitle"
        :description="hasActiveFilters
          ? t('librefinance', 'Change the filters or search to see other records.')
          : t('librefinance', 'Register a bill or collection. Confirmed sales and purchases appear here automatically.')">
        <template v-if="hasActiveFilters" #action>
          <NcButton @click="kindFilter=props.kind; filter='all'; dueRange='all'; searchDraft=''; applySearch()">{{ t('librefinance', 'Clear filters') }}</NcButton>
        </template>
        <template v-else-if="writable && !embedded" #action>
          <NcButton variant="primary" @click="formOpen=true">{{ t('librefinance', 'New obligation') }}</NcButton>
        </template>
      </NcEmptyContent>
      <div v-else-if="displayedRows.length" class="lf-table-scroll">
        <table class="lf-data-table">
          <thead><tr>
            <th :aria-sort="sortable('reference')">
              <LfSortableHeader :label="t('librefinance', 'Reference')" column="reference" :active-column="sortBy"
                :direction="sortDirection" @sort="sort" />
            </th>
            <th :aria-sort="sortable('description')">
              <LfSortableHeader :label="t('librefinance', 'Description')" column="description" :active-column="sortBy"
                :direction="sortDirection" @sort="sort" />
            </th>
            <th>{{ t('librefinance', 'Direction') }}</th><th>{{ t('librefinance', 'Source origin') }}</th>
            <th :aria-sort="sortable('due')">
              <LfSortableHeader :label="t('librefinance', 'Due date')" column="due" :active-column="sortBy"
                :direction="sortDirection" @sort="sort" />
            </th>
            <th class="lf-numeric" :aria-sort="sortable('amount')">
              <LfSortableHeader :label="t('librefinance', 'Expected amount')" column="amount" :active-column="sortBy"
                :direction="sortDirection" @sort="sort" />
            </th>
            <th class="lf-numeric">{{ t('librefinance', 'Expected balance') }}</th><th>{{ t('librefinance', 'Status') }}</th>
            <th v-if="writable">{{ t('librefinance', 'Actions') }}</th>
          </tr></thead>
          <tbody><tr v-for="row in displayedRows" :key="row.id"
            :class="{'lf-row-focused':focusedRecord && Number(focusedRecord.id)===Number(row.id)}">
            <td class="lf-code">{{row.reference}}</td>
            <td><strong>{{row.description}}</strong></td>
            <td><span class="lf-flow-direction"
                :class="row.kind==='receivable'?'lf-flow-direction-in':'lf-flow-direction-out'">
              {{row.kind==='receivable'?t('librefinance', 'Expected inflow'):t('librefinance', 'Expected outflow')}}
            </span></td>
            <td>
              <template v-if="row.linkedInvoiceId">
                <span>{{ t('librefinance', 'Invoice #{id}', { id: row.linkedInvoiceId }, { escape: false, sanitize: false }) }}</span>
                <NcButton v-if="canReadInvoices" size="small" variant="tertiary"
                  @click="openLinkedDocument(row)">{{ t('librefinance', 'Open invoice') }}</NcButton>
                <span v-else class="lf-muted"> · {{ t('librefinance', 'Document access unavailable') }}</span>
              </template>
              <span v-else>{{ t('librefinance', 'Bill or collection') }}</span>
            </td>
            <td><time :datetime="row.due_on">{{ formatLedgerDate(row.due_on) }}</time></td>
            <td class="lf-numeric"><strong
              :class="row.kind==='receivable'?'lf-amount-in':'lf-amount-out'">
              {{signedAmount(row,row.amount_minor)}}
            </strong></td>
            <td class="lf-numeric">
              <strong :class="row.kind==='receivable'?'lf-amount-in':'lf-amount-out'">
                {{signedAmount(row,outstanding(row))}}
              </strong>
              <span v-if="row.ledgerPaidMinor" class="lf-muted">
                {{ t('librefinance', 'Posted: {amount}', { amount: money(row.ledgerPaidMinor) }, { escape: false, sanitize: false }) }}
              </span>
              <span v-if="row.administrativePaidMinor" class="lf-muted">
                {{ t('librefinance', 'Settlement without movement: {amount}', { amount: money(row.administrativePaidMinor) }, { escape: false, sanitize: false }) }}
              </span>
            </td>
            <td><span :class="overdue(row)?'lf-state lf-state-warning':'lf-state'">
              {{overdue(row)?t('librefinance', 'Overdue'):({
                open:t('librefinance', 'Open obligation'),partial:t('librefinance', 'Partial'),
                settled:row.administrativePaidMinor>0 && row.ledgerPaidMinor>0
                  ?t('librefinance', 'Settled by movement and administrative settlement')
                  : row.ledgerPaidMinor>0
                    ?t('librefinance', 'Settled by journal entry'):t('librefinance', 'Settled administratively'),
                cancelled:t('librefinance', 'Cancelled obligation')
              }[row.status]||row.status)}}</span></td>
            <td v-if="writable" class="lf-row-actions">
              <NcButton v-if="['open','partial'].includes(row.status)&&!row.linkedInvoiceId"
                size="small" variant="secondary" @click="cashTarget=row">
                <template #icon><NcIconSvgWrapper :path="mdiCashCheck" :size="18" /></template>
                {{row.kind==='receivable'?t('librefinance', 'Receive'):t('librefinance', 'Pay')}}
              </NcButton>
              <NcActions :aria-label="t('librefinance', 'More actions for {description}', { description: row.description }, { escape: false, sanitize: false })" force-menu
                v-if="['open','partial'].includes(row.status)&&!row.linkedInvoiceId">
                <NcActionButton close-after-click @click="existingTarget=row">
                  <template #icon><NcIconSvgWrapper :path="mdiLinkVariant" :size="20" /></template>
                  {{ t('librefinance', 'Link existing journal entry') }}
                </NcActionButton>
                <NcActionButton close-after-click @click="beginSettlement(row)">
                  <template #icon><NcIconSvgWrapper :path="mdiCheckCircleOutline" :size="20" /></template>
                  {{ t('librefinance', 'Settlement without movement') }}
                </NcActionButton>
                <NcActionButton v-if="manager&&row.status==='open'" close-after-click
                  @click="cancelTarget=row">
                  <template #icon><NcIconSvgWrapper :path="mdiCancel" :size="20" /></template>
                  {{ t('librefinance', 'Cancel obligation') }}
                </NcActionButton>
              </NcActions>
              <span v-else-if="row.linkedInvoiceId" class="lf-state">
                {{ t('librefinance', 'Settlement through the source document') }}
              </span>
            </td>
          </tr></tbody>
        </table>
      </div>
      <div v-if="next" class="lf-table-footer">
        <NcButton :disabled="loading" @click="more">{{ t('librefinance', 'Load more obligations') }}</NcButton>
      </div>
    </section>
    <NcDialog size="normal" content-classes="lf-finance-dialog-content" :open="formOpen"
      :name="props.kind==='receivable' ? t('librefinance', 'New collection')
        : props.kind==='payable' ? t('librefinance', 'New payable') : t('librefinance', 'New bill or collection')"
      @update:open="formOpen=$event">
      <form class="lf-dialog-form" @submit.prevent="create">
        <NcNoteCard type="warning">
          {{ t('librefinance', 'Use this form for bills or collections without a corresponding confirmed sale or purchase with details. Confirmed sales and purchases appear here automatically. Do not register them twice.') }}
        </NcNoteCard>
        <fieldset v-if="props.kind==='all'" class="lf-entry-direction">
          <legend>{{ t('librefinance', 'What will happen?') }}</legend>
          <div class="lf-entry-direction-options">
            <button type="button" :class="{selected:draft.kind==='payable'}"
              :aria-pressed="draft.kind==='payable'" @click="draft.kind='payable'">
              <NcIconSvgWrapper :path="mdiPlus" :size="18" aria-hidden="true" />
              <span><strong>{{ t('librefinance', 'I will pay') }}</strong>
                <small>{{ t('librefinance', 'Money will go out') }}</small></span>
            </button>
            <button type="button" :class="{selected:draft.kind==='receivable'}"
              :aria-pressed="draft.kind==='receivable'" @click="draft.kind='receivable'">
              <NcIconSvgWrapper :path="mdiCashCheck" :size="18" aria-hidden="true" />
              <span><strong>{{ t('librefinance', 'I will receive') }}</strong>
                <small>{{ t('librefinance', 'Money will come in') }}</small></span>
            </button>
          </div>
        </fieldset>
        <p v-else class="lf-muted">
          {{props.kind==='receivable'?t('librefinance', 'Simple collection'):t('librefinance', 'Simple bill')}}
        </p>
        <NcTextField v-model="draft.description" :label="t('librefinance', 'Description')" maxlength="255" required />
        <NcTextField v-model="draft.reference" :label="t('librefinance', 'Reference (optional)')"
          maxlength="80" />
        <NcTextField v-model="draft.amount" :label="t('librefinance', 'Amount')" inputmode="decimal"
          placeholder="0,00" required />
        <div class="lf-form-grid">
          <LfDateField id="lf-title-issued" v-model="draft.issuedOn" :label="t('librefinance', 'Issue date')" />
          <LfDateField id="lf-title-due" v-model="draft.dueOn" :label="t('librefinance', 'Due date')" />
        </div>
        <LfSelect id="lf-title-contact" v-model="draft.contactId" :options="contactChoices"
          :label="t('librefinance', 'Contact (optional)')" />
        <NcButton v-if="contactNextAfterId" variant="tertiary"
          @click="loadMoreCatalog('contacts')">{{ t('librefinance', 'Load more contacts') }}</NcButton>
        <div class="lf-form-actions">
          <NcButton variant="tertiary" @click="formOpen=false">{{ t('librefinance', 'Cancel') }}</NcButton>
          <NcButton type="submit" variant="primary" :disabled="loading">{{ t('librefinance', 'Save bill or collection') }}</NcButton>
        </div>
      </form>
    </NcDialog>
    <NcDialog size="normal" content-classes="lf-finance-dialog-content" :open="Boolean(settlement)" :name="t('librefinance', 'Record administrative settlement (without bank movement)')"
      @update:open="settlement = $event ? settlement : null">
      <form class="lf-dialog-form" @submit.prevent="settle">
        <p v-if="settlement"><strong>{{settlement.description}}</strong>
          · {{ t('librefinance', 'Outstanding: {amount}', { amount: money(outstanding(settlement)) }, { escape: false, sanitize: false }) }}</p>
        <NcNoteCard type="warning">
          {{ t('librefinance', 'This settlement only updates the obligation. It does not record a bank inflow or outflow. Posting and reconciliation are separate steps.') }}
        </NcNoteCard>
        <NcTextField v-model="settleForm.amount" :label="t('librefinance', 'Settlement amount')"
          inputmode="decimal" placeholder="0,00" required />
        <LfDateField id="lf-settle-date" v-model="settleForm.paidOn" :label="t('librefinance', 'Date')" />
        <NcTextField v-model="settleForm.note" :label="t('librefinance', 'Note')" maxlength="255" />
        <div class="lf-form-actions">
          <NcButton variant="tertiary" @click="settlement=null">{{ t('librefinance', 'Cancel') }}</NcButton>
          <NcButton variant="primary" type="submit" :disabled="loading">
            {{ t('librefinance', 'Record settlement') }}
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
    <NcDialog content-classes="lf-finance-dialog-content" :open="Boolean(cancelTarget)" :name="t('librefinance', 'Cancel obligation')"
      @update:open="cancelTarget = $event ? cancelTarget : null">
      <div class="lf-dialog-form">
        <p>{{ t('librefinance', 'Cancel {description}? Its history will be preserved.', { description: cancelTarget?.description ?? '' }, { escape: false, sanitize: false }) }}</p>
        <div class="lf-form-actions">
          <NcButton variant="tertiary" @click="cancelTarget=null">{{ t('librefinance', 'Keep obligation') }}</NcButton>
          <NcButton variant="error" :disabled="loading" @click="cancel">{{ t('librefinance', 'Confirm cancellation') }}</NcButton>
        </div>
      </div>
    </NcDialog>
  </div>
</template>

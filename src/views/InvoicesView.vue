<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { computed, inject, onBeforeUnmount, ref, watch } from 'vue'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { mdiPlus, mdiFileDocumentOutline } from '@mdi/js'
import LfPageHeader from '../components/LfPageHeader.vue'
import LfSortableHeader from '../components/LfSortableHeader.vue'
import { useLoadedSort } from '../composables/useLoadedSort.js'
import LfSelect from '../components/LfSelect.vue'
import CustomFieldsPanel from '../components/CustomFieldsPanel.vue'
import LfDateField from '../components/LfDateField.vue'
import CashPaymentDialog from '../components/CashPaymentDialog.vue'
import { financeApi, financeError } from '../services/api.js'
import { ledgerLocale, toMinorUnits } from '../money.js'
import { formatLedgerDate } from '../date.js'
const amountPlaceholder = new Intl.NumberFormat(ledgerLocale(), { minimumFractionDigits: 2 }).format(0)
const percentDisplay = value => new Intl.NumberFormat(ledgerLocale(), { style: 'percent', minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(Number(value)/10000)
const props = defineProps({
  section: { type: String, default: 'invoices' },
  embedded: { type: Boolean, default: false },
})
const w=inject('lfWorkspace')
const flow=inject('lfFlowContext',null)
const documentKind=inject('lfInvoiceKind',ref('invoice'))
const invoiceFilter=inject('lfInvoiceFilter',ref(documentKind.value))
const filterAvailable=inject('lfInvoiceFilterAvailable',ref(false))
const creationKind=ref('invoice')
const creationStep=ref('choose')
const navigate=inject('lfNavigate',()=>{})
const focusedInvoice=inject('lfFocusedInvoice',ref(null))
const focusedObligation=inject('lfFocusedObligation',ref(null))
const isPurchase=computed(()=>(chosen.value?.kind || creationKind.value)==='bill')
function commercialStatus(status, kind = chosen.value?.kind || creationKind.value) {
  const purchase = kind === 'bill'
  return ({
    draft: t('librefinance', 'Draft'),
    issued: purchase?t('librefinance', 'Confirmed purchase'):t('librefinance', 'Confirmed sale'),
    partially_paid: purchase?t('librefinance', 'Partial payment'):t('librefinance', 'Partial receipt'),
    paid: t('librefinance', 'Settled by journal entry'),
  })[status] || status
}
const canReadObligations=computed(()=>w.modulesLoadedFor.value===Number(w.activeId.value)
  &&w.modules.value.some(item=>item.id==='librefinance_obligations'&&item.enabled))
const { activeId, contacts, accounts, journals, nextBeforeId, loadMore, money, writable, manager }=w
const current='librefinance_invoicing'
const today=new Date().toLocaleDateString('sv-SE')
const tab=computed(() => props.section === 'taxes' ? 'taxes' : 'invoices')
const invoices=ref([])
const invoiceStatus=ref('')
const invoiceStatuses=[
  {value:'',label:t('librefinance', 'All statuses')},
  {value:'draft',label:t('librefinance', 'Draft')},
  {value:'issued',label:t('librefinance', 'Confirmed sale')+' / '+t('librefinance', 'Confirmed purchase')},
  {value:'partially_paid',label:t('librefinance', 'Partial')},
  {value:'paid',label:t('librefinance', 'Settled by journal entry')},
]
const hasOtherDocs=ref(false)
const hasListedInvoices=ref(false)
const showInvoiceFilters=computed(() => invoices.value.length > 0 || !!invoiceNext.value
  || hasOtherDocs.value || hasListedInvoices.value || !!invoiceStatus.value)
const localSort=useLoadedSort('')
const orderedInvoices=computed(()=>localSort.order(invoices.value))
const taxes=ref([])
const invoiceNext=ref(null)
const taxNext=ref(null)
const busy=ref(false)
const error=ref('')
const success=ref('')
const newInvoice=ref(false)
const newRule=ref(false)
const chosen=ref(null)
const detailLoading=ref(false)
const cashTarget=ref(null)
function beginCashPayment() {
  if (busy.value || detailLoading.value || !chosen.value || !chosenObligationId.value) return
  cashTarget.value=chosen.value
  closeDetails(false)
}
async function onCashPaid(result) {
  const target=cashTarget.value
  cashTarget.value=null
  success.value=t('librefinance', 'Receipt or payment recorded without duplication. Journal entry #{id}.', { id: result.journalId }, { escape: false, sanitize: false })
  await load()
  if(target)await showInvoice(target.id)
}
const chosenObligationId=ref(null)
const allocated=ref(false)
const form=ref({
  number:'',kind:creationKind.value,contactId:0,country:'',region:'',
  issueOn:today,dueOn:today,
  lines:[{description:'',quantity:1,price:'',taxRuleId:0}],customFields:{},
})
const rule=ref({
  code:'',label:'',country:'',region:'',kind:'vat',rate:amountPlaceholder,
  inclusive:false,from:today,through:'',sourceRef:'',
})
const allocation=ref({bankAccountId:0,journalId:0,amount:''})
const allocationMinor=computed(()=>{
  try { return toMinorUnits(allocation.value.amount) } catch { return 0 }
})
const journalChoices=computed(()=>{
  const bankId=Number(allocation.value.bankAccountId)
  const expected=isPurchase.value?-allocationMinor.value:allocationMinor.value
  if(!bankId||!expected)return []
  return journals.value.filter(row=>row.postings?.some(posting=>
    Number(posting.accountId)===bankId&&Number(posting.amountMinor)===expected))
    .map(row=>({value:Number(row.id),
      label:'#'+row.id+' · '+formatLedgerDate(row.effective_on)+' · '+(row.memo||t('librefinance', 'Entry'))
        +' · '+money(Math.abs(expected))}))
})
function minorInput(value) {
  const minor=Math.max(0,Number(value)||0)
  const separator = new Intl.NumberFormat(ledgerLocale(), { useGrouping: false }).formatToParts(1.1)
    .find(part => part.type === 'decimal')?.value
  return Math.floor(minor/100)+(separator === ',' ? ',' : '.')+String(minor%100).padStart(2,'0')
}
watch([()=>allocation.value.bankAccountId,()=>allocation.value.amount],()=>{
  if(!journalChoices.value.some(row=>row.value===Number(allocation.value.journalId))){
    allocation.value.journalId=0
  }
})
const bankChoices=computed(()=>accounts.value.filter(a=>a.is_bank&&a.active)
  .map(a=>({value:a.id,label:a.name})))
const contactsChoices=computed(()=>contacts.value.filter(c=>c.active)
  .map(c=>({value:c.id,label:c.name})))
const taxChoices=computed(()=>[{value:0,label:t('librefinance', 'No tax')},
  ...taxes.value.map(t=>({value:t.id,label:t.label+' ('+percentDisplay(t.rate_bps)+')'}))])
const ruleKinds=[
  {value:'vat',label:t('librefinance', 'VAT')},{value:'sales',label:t('librefinance', 'Sales tax')},
  {value:'withholding',label:t('librefinance', 'Withholding')},{value:'other',label:t('librefinance', 'Other tax')},
]
let generation=0
let detailVersion=0
function closeDetails(open) {
  if(open)return
  ++detailVersion
  detailLoading.value=false
  chosen.value=null
  chosenObligationId.value=null
  allocated.value=false
}
function rateBps(value) {
  const m=String(value).trim().match(/^(\d{1,4})(?:[.,](\d{1,2}))?$/)
  if(!m)throw new Error(t('librefinance', 'Invalid tax rate'))
  const n=Number(m[1])*100+Number((m[2]||'').padEnd(2,'0'))
  if(n>100000)throw new Error(t('librefinance', 'Tax rate exceeds the 1000% limit'))
  return n
}
function invoiceQuery(afterId = 0) {
  const params = new URLSearchParams()
  if (invoiceFilter.value !== 'all') params.set('kind', invoiceFilter.value)
  if (invoiceStatus.value) params.set('status', invoiceStatus.value)
  if (afterId) params.set('afterId', String(afterId))
  const query = params.toString()
  return '/invoices' + (query ? '?' + query : '')
}
async function load() {
  const token=++generation;busy.value=true;error.value=''
  try{
    const [docs,tax]=await Promise.all([
      financeApi(current,activeId.value,invoiceQuery()),
      financeApi(current,activeId.value,'/tax-rules'),
    ])
    if(token!==generation)return
    invoices.value=docs.invoices;invoiceNext.value=docs.nextAfterId
    if (docs.invoices.length) {
      hasListedInvoices.value=true
      filterAvailable.value=true
    }
    taxes.value=tax.rules;taxNext.value=tax.nextAfterId
    hasOtherDocs.value=false
    // When a filtered list is empty but another kind exists, keep the filter
    // available; never hide purchases merely because only sales were loaded.
    if (!docs.invoices.length && invoiceFilter.value!=='all' && tab.value==='invoices') {
      const other=await financeApi(current,activeId.value,
        '/invoices?kind='+(invoiceFilter.value==='invoice'?'bill':'invoice'))
      if(token!==generation)return
      hasOtherDocs.value=other.invoices.length>0
      if(hasOtherDocs.value)filterAvailable.value=true
    }
  }catch(e){if(token===generation)error.value=financeError(e)}
  finally{if(token===generation)busy.value=false}
}
async function more(kind) {
  const cursor=kind==='invoice'?invoiceNext.value:taxNext.value
  if(!cursor||busy.value)return
  const token=generation;busy.value=true
  try{
    const response=await financeApi(current,activeId.value,
      (kind==='invoice'?invoiceQuery(cursor):'/tax-rules?afterId='+cursor))
    if(token!==generation)return
    if(kind==='invoice'){
      invoices.value=[...invoices.value,...response.invoices]
      invoiceNext.value=response.nextAfterId
    }else{
      taxes.value=[...taxes.value,...response.rules]
      taxNext.value=response.nextAfterId
    }
  }catch(e){if(token===generation)error.value=financeError(e)}
  finally{if(token===generation)busy.value=false}
}
async function perform(task,notice) {
  if(busy.value)return false
  const token=++generation;busy.value=true;error.value='';success.value=''
  try{
    await task()
    if(token!==generation)return false
    success.value=notice
    await load()
    return true
  }catch(e){if(token===generation)error.value=financeError(e);return false}
  finally{if(token===generation)busy.value=false}
}
function addLine() {
  if(form.value.lines.length>=20){error.value=t('librefinance', 'Maximum of 20 invoice lines');return}
  form.value.lines.push({description:'',quantity:1,price:'',taxRuleId:0})
}
async function create() {
  if(form.value.lines.some(line=>!line.description.trim()
    || !Number.isSafeInteger(Number(line.quantity)) || Number(line.quantity)<1
    || Number(line.quantity)>100000)) {
    error.value=t('librefinance', 'Review line descriptions and quantities.')
    return
  }
  const successResult=await perform(async()=>{
    const lines=form.value.lines.map(line=>({
      description:line.description.trim(),quantity:Number(line.quantity),
      unitPriceMinor:toMinorUnits(line.price),
      taxRuleId:Number(line.taxRuleId)||null,
    }))
    await financeApi(current,activeId.value,'/invoices','POST',{
      number:form.value.number.trim(),kind:creationKind.value,
      contactId:Number(form.value.contactId),issueOn:form.value.issueOn,
      dueOn:form.value.dueOn,country:form.value.country.trim(),
      region:form.value.region.trim()||null,lines,
      customFields:form.value.customFields,
    })
  },isPurchase.value?t('librefinance', 'Purchase invoice saved as draft, without bank movements.')
    :t('librefinance', 'Sales invoice saved as draft, without fiscal issuance.'))
  if(successResult){
    newInvoice.value=false
    if (invoiceFilter.value !== 'all' && invoiceFilter.value !== creationKind.value)
      invoiceFilter.value = creationKind.value
    form.value={number:'',kind:creationKind.value,contactId:0,country:'',region:'',
      issueOn:today,dueOn:today,lines:[{description:'',quantity:1,price:'',taxRuleId:0}],customFields:{}}
  }
}
async function createRule() {
  const done=await perform(async()=>financeApi(current,activeId.value,'/tax-rules','POST',{
    code:rule.value.code.trim(),label:rule.value.label.trim(),
    country:rule.value.country.trim().toUpperCase(),region:rule.value.region.trim()||null,
    kind:rule.value.kind,rateBps:rateBps(rule.value.rate),inclusive:rule.value.inclusive,
    validFrom:rule.value.from,validThrough:rule.value.through||null,
    sourceRef:rule.value.sourceRef.trim(),
  }),t('librefinance', 'Rule registered as an immutable version; legal compliance not verified.'))
  if(done)newRule.value=false
}
async function showInvoice(id, refresh=false) {
  if(!refresh)closeDetails(false)
  const token=generation
  const request=++detailVersion
  const spaceId=Number(activeId.value)
  detailLoading.value=true
  error.value=''
  try{
    const response=await financeApi(current,spaceId,'/invoices/'+id)
    if(token===generation && request===detailVersion && spaceId===Number(activeId.value)){
      chosen.value=response.invoice
      chosenObligationId.value=response.obligationId
      allocation.value={bankAccountId:0,journalId:0,
        amount:minorInput(Number(response.invoice.total_minor)
          -Number(response.invoice.paid_minor))}
    }
  }catch(e){
    if(token===generation && request===detailVersion){
      closeDetails(false)
      error.value=financeError(e)
    }
  }finally{
    if(request===detailVersion)detailLoading.value=false
  }
}
async function issue() {
  if(detailLoading.value || !chosen.value)return
  const id=chosen.value.id
  const done=await perform(async()=>financeApi(current,activeId.value,
    '/invoices/'+id+'/issue','POST',{}),
    t('librefinance', 'Document confirmed. Linked obligation created; no fiscal invoice was issued.'))
  if(done && chosen.value?.id===id)await showInvoice(id, true)
}
async function allocate() {
  if(detailLoading.value || !chosen.value)return
  if(!journalChoices.value.some(row=>row.value===Number(allocation.value.journalId))){
    error.value=t('librefinance', 'Select a posted journal entry matching the account and amount.')
    return
  }
  const id=chosen.value.id
  const done=await perform(async()=>financeApi(current,activeId.value,
    '/invoices/'+id+'/allocations','POST',{
      bankAccountId:Number(allocation.value.bankAccountId),
      journalId:Number(allocation.value.journalId),
      amountMinor:toMinorUnits(allocation.value.amount),
    }),t('librefinance', 'Existing journal entry associated; no transfer created.'))
  if(done && chosen.value?.id===id){allocated.value=false;await showInvoice(id, true)}
}
async function openFocusedInvoice() {
  const focus=focusedInvoice.value
  if(!focus||Number(focus.spaceId)!==Number(activeId.value)
    ||!['invoice','bill'].includes(focus.kind))return
  focusedInvoice.value=null
  await showInvoice(Number(focus.id))
}
watch(activeId, () => {
  // A previous workspace's documents must not expose filters in a new empty space.
  hasListedInvoices.value=false
  hasOtherDocs.value=false
  filterAvailable.value=false
  invoiceStatus.value=''
})
watch([activeId,invoiceFilter,invoiceStatus],async()=>{
  invoices.value=[]
  invoiceNext.value=null
  closeDetails(false)
  newInvoice.value=false
  await load()
  await openFocusedInvoice()
},{immediate:true})
// Opening a detailed document must run after the initial active-space watcher,
// which resets forms while resolving the authorized workspace data.
function selectCreationKind(value) {
  if (!['invoice','bill'].includes(value)) return
  creationKind.value=value
  form.value={number:'',kind:value,contactId:0,country:'',region:'',
    issueOn:today,dueOn:today,lines:[{description:'',quantity:1,price:'',taxRuleId:0}],customFields:{}}
  creationStep.value='details'
}
watch(() => flow?.newDetailed.value, requested => {
  if (!requested || !w.writable.value || !w.activeId.value) return
  creationStep.value='choose'
  newInvoice.value=true
  flow.newDetailed.value=false
}, { immediate:true })
onBeforeUnmount(()=>{++generation;++detailVersion})
function openLinkedObligation() {
  if(!canReadObligations.value||!chosenObligationId.value||!chosen.value)return
  focusedObligation.value={spaceId:Number(activeId.value),
    id:Number(chosenObligationId.value)}
  closeDetails(false)
  if(flow)flow.showAccounts()
  else navigate('obligations')
}
</script>
<template>
  <div class="lf-view">
    <LfPageHeader v-if="!embedded" :title="tab === 'taxes' ? t('librefinance', 'Tax rules')
        : (isPurchase ? t('librefinance', 'Purchase documents') : t('librefinance', 'Sales invoices'))"
      :eyebrow="tab === 'taxes' ? t('librefinance', 'TAX CONFIGURATION') : t('librefinance', 'INVOICES')"
      :description="tab === 'taxes'
        ? t('librefinance', 'Configure calculation rules, jurisdiction and validity. This section does not issue fiscal documents.')
        : t('librefinance', 'Register sales and purchases with items and taxes. When confirmed, the amount appears in Financial movements.')">
      <template #actions>
        <NcButton v-if="tab === 'taxes' ? manager : writable" variant="primary"
          @click="tab==='taxes'?newRule=true:newInvoice=true">
          <template #icon><NcIconSvgWrapper :path="mdiPlus" :size="20" /></template>
          {{tab==='taxes'?t('librefinance', 'New rule'):(isPurchase?t('librefinance', 'New purchase invoice'):t('librefinance', 'New sales invoice'))}}
        </NcButton>
      </template>
    </LfPageHeader>
    <NcNoteCard v-if="tab==='taxes'" type="info">
      {{ t('librefinance', 'Configured rules are tax parameters; they do not represent fiscal qualification.') }}
    </NcNoteCard>
    <NcNoteCard v-if="error" type="error" role="alert">{{error}}</NcNoteCard>
    <NcNoteCard v-if="success" type="success" role="status">{{success}}</NcNoteCard>
    <section v-if="tab==='invoices'" :class="{ 'lf-surface': !embedded }">
      <div v-if="showInvoiceFilters" class="lf-section-heading">
        <h2>{{ invoiceFilter === 'all' ? t('librefinance', 'Registered invoices')
          : invoiceFilter === 'bill' ? t('librefinance', 'Registered purchases') : t('librefinance', 'Registered sales') }}</h2>
        <LfSelect id="lf-invoice-status-filter" v-model="invoiceStatus"
          :label="t('librefinance', 'Status')" :options="invoiceStatuses" />
      </div>
      <div v-if="busy && !invoices.length" class="lf-loading" role="status">
        {{ t('librefinance', 'Loading invoices…') }}
      </div>
      <div v-else-if="invoices.length" class="lf-table-scroll">
        <table class="lf-data-table">
          <thead><tr>
            <th v-for="col in [
              {key:'number',label:t('librefinance', 'Number')}, {key:'kind',label:t('librefinance', 'Type')},
              {key:'status',label:t('librefinance', 'Status')}, {key:'due_on',label:t('librefinance', 'Due date')},
              {key:'total_minor',label:t('librefinance', 'Total')}, {key:'paid_minor',label:t('librefinance', 'Settled')}]"
              :key="col.key" :class="{'lf-numeric':['total_minor','paid_minor'].includes(col.key)}"
              :aria-sort="localSort.ariaSort(col.key)">
              <LfSortableHeader :label="col.label" :column="col.key"
                :active-column="localSort.column.value" :direction="localSort.direction.value"
                scope="loaded" @sort="localSort.sort" />
            </th>
            <th>{{ t('librefinance', 'Actions') }}</th>
          </tr></thead>
          <tbody><tr v-for="invoice in orderedInvoices" :key="invoice.id">
            <td class="lf-code">{{invoice.number}}</td>
            <td>{{invoice.kind==='invoice'?t('librefinance', 'Sale'):t('librefinance', 'Purchase')}}</td>
            <td><span class="lf-state">{{commercialStatus(invoice.status, invoice.kind)}}</span></td>
            <td><time :datetime="invoice.due_on">{{ formatLedgerDate(invoice.due_on) }}</time></td>
            <td class="lf-numeric">{{money(invoice.total_minor)}}</td>
            <td class="lf-numeric">{{money(invoice.paid_minor)}}</td>
            <td><NcButton size="small" variant="tertiary" @click="showInvoice(invoice.id)">
              {{ t('librefinance', 'Details') }}
            </NcButton></td>
          </tr></tbody>
        </table>
      </div>
      <NcEmptyContent v-else-if="!error"
        :name="hasOtherDocs || invoiceStatus ? t('librefinance', 'No invoices match this filter') : t('librefinance', 'No invoices registered')"
        :description="invoiceStatus ? '' : hasOtherDocs
          ? t('librefinance', 'Use the Type filter and choose All to view the other records.')
          : t('librefinance', 'Use New invoice to record a sale or purchase. Confirming the invoice creates the corresponding amount in Financial movements.')">
        <template v-if="invoiceStatus" #action>
          <NcButton @click="invoiceStatus=''">{{ t('librefinance', 'Clear filters') }}</NcButton>
        </template>
      </NcEmptyContent>
      <p v-if="localSort.column.value && invoiceNext" class="lf-muted">
        {{ t('librefinance', 'Sorting applies only to loaded documents.') }}</p>
      <div v-if="invoiceNext" class="lf-table-footer">
        <NcButton :disabled="busy" @click="more('invoice')">{{ t('librefinance', 'Load more') }}</NcButton>
      </div>
    </section>
    <section v-else class="lf-surface">
      <div class="lf-section-heading"><h2>{{ t('librefinance', 'Tax calculation rules') }}</h2></div>
      <div v-if="taxes.length" class="lf-table-scroll"><table class="lf-data-table">
        <thead><tr><th>{{ t('librefinance', 'Code') }}</th>
            <th>{{ t('librefinance', 'Jurisdiction') }}</th>
            <th>{{ t('librefinance', 'Type') }}</th>
          <th>{{ t('librefinance', 'Tax rate') }}</th>
            <th>{{ t('librefinance', 'Validity') }}</th>
            <th>{{ t('librefinance', 'Source') }}</th></tr></thead>
        <tbody><tr v-for="tax in taxes" :key="tax.id">
          <td><strong>{{tax.code}} · {{tax.label}}</strong></td>
          <td>{{tax.country}} {{tax.region||''}}</td><td>{{ ruleKinds.find(option => option.value === tax.kind)?.label || tax.kind }}</td>
          <td>{{ percentDisplay(tax.rate_bps) }} {{tax.inclusive?t('librefinance', '(included)'):''}}</td>
          <td>{{ t('librefinance', '{start} to {end}', { start: formatLedgerDate(tax.valid_from), end: tax.valid_through ? formatLedgerDate(tax.valid_through) : t('librefinance', 'indefinite') }, { escape: false, sanitize: false }) }}</td>
          <td>{{tax.source_ref}}</td>
        </tr></tbody>
      </table></div>
      <NcEmptyContent v-else :name="t('librefinance', 'No rules registered')" />
      <div v-if="taxNext" class="lf-table-footer">
        <NcButton :disabled="busy" @click="more('tax')">{{ t('librefinance', 'Load more rules') }}</NcButton>
      </div>
    </section>

    <NcDialog content-classes="lf-finance-dialog-content" :open="newInvoice"
      :name="t('librefinance', 'New invoice')" size="large"
      @update:open="newInvoice=$event">
      <div v-if="creationStep==='choose'" class="lf-dialog-form">
        <fieldset class="lf-entry-direction">
          <legend>{{ t('librefinance', 'What would you like to record?') }}</legend>
          <div class="lf-entry-direction-options">
            <button type="button" @click="selectCreationKind('invoice')">
              <span><strong>{{ t('librefinance', 'Sale') }}</strong>
                <small>{{ t('librefinance', 'I will charge for products or services') }}</small></span>
            </button>
            <button type="button" @click="selectCreationKind('bill')">
              <span><strong>{{ t('librefinance', 'Purchase') }}</strong>
                <small>{{ t('librefinance', 'I will pay a supplier') }}</small></span>
            </button>
          </div>
        </fieldset>
        <div class="lf-form-actions">
          <NcButton variant="tertiary" @click="newInvoice=false">{{ t('librefinance', 'Cancel') }}</NcButton>
        </div>
      </div>
      <form v-else class="lf-dialog-form" @submit.prevent="create">
        <NcButton variant="tertiary" @click="creationStep='choose'">
          {{ isPurchase ? t('librefinance', 'Purchase · Change type') : t('librefinance', 'Sale · Change type') }}
        </NcButton>
        <div class="lf-form-grid">
          <NcTextField v-model="form.number" :label="t('librefinance', 'Number')" maxlength="80" required />
          <p class="lf-muted">{{isPurchase
            ? t('librefinance', 'Describe what you purchased. When confirmed, the amount appears in Accounts payable.')
            : t('librefinance', 'Describe what you sold. When confirmed, the amount appears in Accounts receivable.')}}</p>
          <LfSelect id="lf-invoice-contact" v-model="form.contactId" :options="contactsChoices"
            :label="t('librefinance', 'Customer or supplier')" />
          <NcTextField v-model="form.country" :label="t('librefinance', 'Country (ISO 2, required)')" maxlength="2" required />
          <NcTextField v-model="form.region" :label="t('librefinance', 'Region (optional)')" maxlength="32" />
          <LfDateField id="lf-invoice-date" v-model="form.issueOn" :label="t('librefinance', 'Issue date')" />
          <LfDateField id="lf-invoice-due" v-model="form.dueOn" :label="t('librefinance', 'Due date')" />
        </div>
        <h3>{{ isPurchase ? t('librefinance', 'Purchase items') : t('librefinance', 'Sale items') }}</h3>
        <div v-for="(line,index) in form.lines" :key="index" class="lf-invoice-line">
          <NcTextField v-model="line.description" :label="t('librefinance', 'Line {index} description', { index: index + 1 }, { escape: false, sanitize: false })"
            maxlength="255" required />
          <NcTextField v-model="line.quantity" :label="t('librefinance', 'Quantity')" type="number" required />
          <NcTextField v-model="line.price" :label="t('librefinance', 'Unit price')" inputmode="decimal"
            :placeholder="amountPlaceholder" required />
          <LfSelect :id="'lf-invoice-tax-'+index" v-model="line.taxRuleId"
            :label="t('librefinance', 'Tax')" :options="taxChoices" />
          <NcButton variant="tertiary" :disabled="form.lines.length===1"
            @click="form.lines.splice(index,1)">{{ t('librefinance', 'Remove line') }}</NcButton>
        </div>
        <NcButton variant="secondary" :disabled="form.lines.length>=20" @click="addLine">
          {{ t('librefinance', 'Add line') }}
        </NcButton>
        <CustomFieldsPanel :key="activeId+':'+creationKind+':'+newInvoice"
          v-model="form.customFields" :entity-kind="creationKind" />
        <div class="lf-form-actions">
          <NcButton variant="tertiary" @click="newInvoice=false">{{ t('librefinance', 'Cancel') }}</NcButton>
          <NcButton variant="primary" type="submit" :disabled="busy">{{ t('librefinance', 'Save draft') }}</NcButton>
        </div>
      </form>
    </NcDialog>
    <NcDialog size="normal" content-classes="lf-finance-dialog-content" :open="newRule" :name="t('librefinance', 'New tax rule')"
      @update:open="newRule=$event">
      <form class="lf-dialog-form" @submit.prevent="createRule">
        <NcTextField v-model="rule.code" :label="t('librefinance', 'Code')" required maxlength="40" />
        <NcTextField v-model="rule.label" :label="t('librefinance', 'Name')" required maxlength="150" />
        <NcTextField v-model="rule.country" :label="t('librefinance', 'Country (ISO 2)')" maxlength="2" required />
        <NcTextField v-model="rule.region" :label="t('librefinance', 'Optional region')" maxlength="32" />
        <LfSelect id="lf-tax-kind" v-model="rule.kind" :options="ruleKinds" :label="t('librefinance', 'Nature')" />
        <NcTextField v-model="rule.rate" :label="t('librefinance', 'Tax rate (%)')" inputmode="decimal"
          :placeholder="amountPlaceholder" required />
        <NcCheckboxRadioSwitch v-model="rule.inclusive" type="checkbox"
          :disabled="rule.kind==='withholding'">{{ t('librefinance', 'Included in price') }}</NcCheckboxRadioSwitch>
        <div class="lf-form-grid">
          <LfDateField id="lf-tax-from" v-model="rule.from" :label="t('librefinance', 'Start')" />
          <LfDateField id="lf-tax-through" v-model="rule.through" :label="t('librefinance', 'End (optional)')" />
        </div>
        <NcTextField v-model="rule.sourceRef" :label="t('librefinance', 'Regulatory source')"
          maxlength="255" required />
        <div class="lf-form-actions">
          <NcButton variant="tertiary" @click="newRule=false">{{ t('librefinance', 'Cancel') }}</NcButton>
          <NcButton variant="primary" type="submit" :disabled="busy">{{ t('librefinance', 'Register version') }}</NcButton>
        </div>
      </form>
    </NcDialog>

    <CashPaymentDialog :open="Boolean(cashTarget)" source="invoice"
      :record="cashTarget" :kind="cashTarget?.kind==='bill'?'payable':'receivable'"
      @update:open="cashTarget=$event?cashTarget:null"
      @paid="onCashPaid" />
    <NcDialog v-if="chosen" :key="chosen.id" size="normal" content-classes="lf-finance-dialog-content" :open="true" :name="t('librefinance', 'Invoice details')"
      @update:open="closeDetails($event)">
      <div v-if="chosen" class="lf-dialog-form">
        <h3>{{chosen.number}}</h3>
        <p>{{ t('librefinance', 'Status: {status}', { status: commercialStatus(chosen.status) }, { escape: false, sanitize: false }) }}</p>
        <p v-if="chosen.status==='draft'" class="lf-muted">
          {{ isPurchase ? t('librefinance', 'Confirming this document creates a planned outflow in Financial movements. It does not record a payment or issue a fiscal invoice.') : t('librefinance', 'Confirming this document creates a planned inflow in Financial movements. It does not record a receipt or issue a fiscal invoice.') }}
        </p>
        <p v-if="chosenObligationId">{{ isPurchase ? t('librefinance', 'Linked payable #{id}. Associating payment requires a valid journal entry; it does not create a second movement.', { id: chosenObligationId }, { escape: false, sanitize: false }) : t('librefinance', 'Linked receivable #{id}. Associating receipt requires a valid journal entry; it does not create a second movement.', { id: chosenObligationId }, { escape: false, sanitize: false }) }}</p>
        <NcButton v-if="chosenObligationId && canReadObligations" variant="tertiary"
          @click="openLinkedObligation">{{ t('librefinance', 'View linked obligation') }}</NcButton>
        <NcNoteCard v-else-if="chosen.status!=='draft'" type="warning">
          {{ t('librefinance', 'Historical invoice without a reconciled obligation. Payment associations require explicit reconciliation before continuing.') }}
        </NcNoteCard>
        <div class="lf-kpis">
          <div class="lf-kpi"><span>{{ t('librefinance', 'Total') }}</span>
            <strong>{{money(chosen.total_minor)}}</strong></div>
          <div class="lf-kpi"><span>{{ t('librefinance', 'Outstanding') }}</span>
            <strong>{{money(chosen.total_minor-chosen.paid_minor)}}</strong></div>
        </div>
        <NcButton v-if="manager&&chosen.status==='draft'" variant="primary"
          :disabled="busy || detailLoading" @click="issue">{{isPurchase?t('librefinance', 'Confirm purchase'):t('librefinance', 'Confirm sale')}}</NcButton>
        <template v-if="writable&&chosenObligationId&&['issued','partially_paid'].includes(chosen.status)">
          <NcNoteCard v-if="Number(chosen.tax_minor)||Number(chosen.withheld_minor)"
            type="warning">
            {{ t('librefinance', 'This document contains taxes or withholdings. It is not safe to post the gross total as income or expense. Record the correct tax entries in Accounting journals and use Link previously recorded movement.') }}
          </NcNoteCard>
          <NcButton v-else variant="primary" :disabled="busy || detailLoading" @click="beginCashPayment">
            {{isPurchase?t('librefinance', 'Record payment'):t('librefinance', 'Record receipt')}}
          </NcButton>
          <NcButton v-if="!allocated" variant="secondary" :disabled="busy || detailLoading" @click="allocated=true">
            {{ t('librefinance', 'Link previously recorded movement') }}
          </NcButton>
          <form v-else class="lf-dialog-form" @submit.prevent="allocate">
            <NcNoteCard type="info">
              {{ t('librefinance', 'Select a posted movement with the same amount and financial account. The association does not move money again.') }}
            </NcNoteCard>
            <LfSelect id="lf-invoice-bank" v-model="allocation.bankAccountId"
              :label="t('librefinance', 'Financial account')" :options="bankChoices" />
            <NcTextField v-model="allocation.amount" :label="t('librefinance', 'Movement amount')"
              inputmode="decimal" required />
            <LfSelect id="lf-invoice-journal" v-model="allocation.journalId"
              :label="t('librefinance', 'Posted journal entry')" :options="journalChoices" />
            <NcNoteCard v-if="!journalChoices.length" type="warning">
              {{ t('librefinance', 'No matching entry on loaded pages. If payment has not been posted, record it in Accounting journals and return to this document.') }}
            </NcNoteCard>
            <div class="lf-form-actions">
              <NcButton v-if="nextBeforeId" variant="tertiary"
                :disabled="busy" @click="loadMore">{{ t('librefinance', 'Load earlier entries') }}</NcButton>
              <NcButton variant="tertiary" @click="closeDetails(false);navigate('transactions')">
                {{ t('librefinance', 'Open accounting journals') }}
              </NcButton>
              <NcButton variant="primary" type="submit"
                :disabled="busy||detailLoading||!journalChoices.some(row=>row.value===Number(allocation.journalId))">
                {{ t('librefinance', 'Associate without duplicating movement') }}
              </NcButton>
            </div>
          </form>
        </template>
        <NcButton variant="tertiary" @click="closeDetails(false)">
          {{ t('librefinance', 'Close details') }}
        </NcButton>
      </div>
    </NcDialog>
  </div>
</template>

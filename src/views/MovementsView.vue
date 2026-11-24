<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { computed, inject, onBeforeUnmount, ref, watch } from 'vue'
import { t } from '@nextcloud/l10n'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcPopover from '@nextcloud/vue/components/NcPopover'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcFilePicker from '@nextcloud/vue/components/NcFilePicker'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { mdiPlus, mdiFilterVariant, mdiRefresh, mdiBankOutline, mdiFileUploadOutline, mdiFileDelimitedOutline, mdiFileCodeOutline } from '@mdi/js'
import LfPageHeader from '../components/LfPageHeader.vue'
import LfDateField from '../components/LfDateField.vue'
import LfSelect from '../components/LfSelect.vue'
import CashPaymentDialog from '../components/CashPaymentDialog.vue'
import CustomFieldsPanel from '../components/CustomFieldsPanel.vue'
import { financeApi, financeError } from '../services/api.js'
import { ledgerLocale, toMinorUnits } from '../money.js'
import { formatLedgerDate } from '../date.js'

const amountPlaceholder = new Intl.NumberFormat(ledgerLocale(), { minimumFractionDigits: 2 }).format(0)
const w = inject('lfWorkspace')
const navigate = inject('lfNavigate', () => {})
const focusedInvoice = inject('lfFocusedInvoice', ref(null))
const directionHint = inject('lfFinanceDirectionHint', ref('all'))
const bankContext = inject('lfBankContext', ref(0))
const accountFilterOpen = ref(false)
const moreActionsOpen = ref(false)
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
  { value:'all', label:t('librefinance', 'All') }, {value:'in',label:t('librefinance', 'Inflows')},
  {value:'out',label:t('librefinance', 'Outflows')},
]
const stateOptions = [
  {value:'all',label:t('librefinance', 'All')}, {value:'posted',label:t('librefinance', 'Posted movements')},
  {value:'planned',label:t('librefinance', 'Planned movements')}, {value:'pending',label:t('librefinance', 'Pending review')},
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
  const chosen = Number(id)
  accountFilter.value = chosen
  bankContext.value = chosen
  accountFilterOpen.value = false
}
// A bank statement is evidence inside the same activity feed. The bank
// extension is used only for scoped import/match operations, never as a
// second user-facing register.
const importOpen = ref(false)
const importFormat = ref('csv')
const allowedBankFormats = ref([])
const bankFormatsLoading = ref(true)
let bankFormatSequence = 0
const importAccount = ref(0)
const importFile = ref(null)
const csvExample = 'data:text/csv;charset=utf-8,' + encodeURIComponent(
  'id,date,amount,description\nexemplo-001,2026-11-22,-25.90,Pagamento de exemplo\n'
  + 'exemplo-002,2026-11-22,150.00,Recebimento de exemplo\n')
const importError = ref('')
const importing = ref(false)
const bankBusy = ref(false)
const reconcileRow = ref(null)
const journalId = ref(0)
const reconciliationError = ref('')
let bankActionVersion = 0
const journalCandidates = computed(() => {
  if (!reconcileRow.value) return []
  const evidence = reconcileRow.value
  return w.journals.value.filter(item => item.postings?.some(post =>
    Number(post.accountId)===Number(evidence.accountId)
    && Number(post.amountMinor)===Number(evidence.amountMinor)))
    .map(item => ({value:Number(item.id),
      label:formatLedgerDate(item.effective_on)+' · '+(item.memo || t('librefinance', 'Movement'))
        +' (#'+item.id+')'}))
})
async function loadAllowedBankFormats() {
  const token = ++bankFormatSequence
  const spaceId = Number(w.activeId.value)
  bankFormatsLoading.value = true
  allowedBankFormats.value = []
  if (!spaceId || Number(w.space.value?.id) !== spaceId) {
    bankFormatsLoading.value = false
    return
  }
  if (w.space.value.saasManaged !== true) {
    allowedBankFormats.value = ['csv', 'ofx']
    bankFormatsLoading.value = false
    return
  }
  try {
    const path = generateUrl('/apps/librefinance/api/saas/v1/workspaces/'
      + spaceId + '/policies/banking.allowedImportFormats')
    const response = await axios.get(path)
    if (token !== bankFormatSequence || Number(w.activeId.value) !== spaceId) return
    const actual = response.data.effectiveValue
    allowedBankFormats.value = Array.isArray(actual)
      ? ['csv', 'ofx'].filter(format => actual.includes(format)) : []
  } catch {
    if (token === bankFormatSequence) allowedBankFormats.value = []
  } finally {
    if (token === bankFormatSequence) bankFormatsLoading.value = false
  }
}
function openImport(format = 'csv') {
  if (!allowedBankFormats.value.includes(format) || bankFormatsLoading.value
    || !w.writable.value
    || !canReviewBank.value || !banks.value.length) return
  importFormat.value=format
  importError.value=''
  importFile.value=null
  importAccount.value = Number(accountFilter.value)
    || (banks.value.length===1 ? Number(banks.value[0].id) : 0)
  importOpen.value=true
}
function closeImport() {
  if (importing.value) return
  importOpen.value=false
  importFile.value=null
  importError.value=''
}
function chooseImportFile(files) {
  importError.value=''
  if (!Array.isArray(files) || files.length !== 1) {
    importFile.value=null
    importError.value=t('librefinance', 'Select only one file.')
    return
  }
  const file=files[0]
  if (!file.name.toLowerCase().endsWith('.'+importFormat.value)) {
    importFile.value=null
    importError.value=t('librefinance', 'Select a {format} file.', { format: importFormat.value.toUpperCase() }, { escape: false, sanitize: false })
    return
  }
  if(file.size>262144 || file.size===0) {
    importFile.value=null
    importError.value=t('librefinance', 'The file must not be empty or exceed 256 KiB.')
    return
  }
  importFile.value=file
}
async function importStatement() {
  if (!importFile.value || !importAccount.value || importing.value
    || !w.writable.value || !canReviewBank.value
    || !allowedBankFormats.value.includes(importFormat.value)) return
  importError.value=''
  const token=++bankActionVersion
  const spaceId=Number(w.activeId.value)
  const bankId=Number(importAccount.value)
  importing.value=true
  try {
    if(importFile.value.size>262144)throw new Error(t('librefinance', 'File exceeds the 256 KiB limit.'))
    // File.text() assumes UTF-8; bank OFX frequently declares Windows-1252.
    // Decode locally by the documented header, using fatal mode for UTF-8
    // rather than silently replacing accented descriptions.
    const bytes=new Uint8Array(await importFile.value.arrayBuffer())
    let charset='utf-8'
    if(importFormat.value==='ofx') {
      const header=new TextDecoder('windows-1252').decode(bytes.slice(0,512))
      const numeric=header.match(/^CHARSET:\s*(\d+)\s*$/mi)?.[1]
      const xml=header.match(/<\?xml[^>]*encoding=['"]([^'"]+)['"]/i)?.[1]?.toLowerCase()
      if(numeric==='1252' || ['windows-1252','cp1252'].includes(xml))charset='windows-1252'
      else if(numeric==='8859' || ['iso-8859-1','latin1'].includes(xml))charset='iso-8859-1'
      else if(numeric && !['65001'].includes(numeric))
        throw new Error(t('librefinance', 'Unknown OFX encoding: {encoding}', { encoding: numeric }, { escape: false, sanitize: false }))
      else if(xml && !['utf-8','utf8'].includes(xml))
        throw new Error(t('librefinance', 'Unknown OFX encoding: {encoding}', { encoding: xml }, { escape: false, sanitize: false }))
    }
    const csv=new TextDecoder(charset,{fatal:true}).decode(bytes)
    if(token!==bankActionVersion || spaceId!==Number(w.activeId.value))return
    const result=await financeApi('librefinance_banking',spaceId,
      '/imports','POST',importFormat.value==='ofx'
        ? {bankAccountId:bankId,format:'ofx',ofx:csv}
        : {bankAccountId:bankId,format:'csv',csv})
    if(token!==bankActionVersion || spaceId!==Number(w.activeId.value))return
    importOpen.value=false
    importFile.value=null
    success.value = t('librefinance', 'Imported movements: {imported}; already present: {skipped}. Review pending items in the list.', { imported: result.imported, skipped: result.skipped }, { escape: false, sanitize: false })
    if(accountFilter.value!==bankId)setAccount(bankId)
    else await load()
  } catch (cause) {
    if(token===bankActionVersion && spaceId===Number(w.activeId.value))
      importError.value=financeError(cause)
  } finally {
    if(token===bankActionVersion)importing.value=false
  }
}
function reviewStatement(row) {
  if (!w.writable.value || !canReviewBank.value
    || row.source!=='statement' || row.state!=='pending'
    || !banks.value.some(bank=>Number(bank.id)===Number(row.accountId))) return
  reconcileRow.value={...row}
  journalId.value=0
  reconciliationError.value=''
}
function closeReconciliation() {
  if(bankBusy.value)return
  reconcileRow.value=null
  journalId.value=0
  reconciliationError.value=''
}
async function matchStatement() {
  if(bankBusy.value || !w.writable.value || !canReviewBank.value || !reconcileRow.value)return
  const selected=Number(journalId.value)
  if(!Number.isSafeInteger(selected)
    || !journalCandidates.value.some(candidate=>candidate.value===selected)){
    reconciliationError.value=t('librefinance', 'Select the matching movement to review.')
    return
  }
  const token=++bankActionVersion
  const spaceId=Number(w.activeId.value)
  const evidenceId=Number(reconcileRow.value.sourceId)
  reconciliationError.value=''
  bankBusy.value=true
  try {
    await financeApi('librefinance_banking',spaceId,
      '/transactions/'+evidenceId+'/match','POST',{journalId:selected})
    if(token!==bankActionVersion || spaceId!==Number(w.activeId.value))return
    reconcileRow.value=null
    journalId.value=0
    success.value=t('librefinance', 'Movement reviewed. No new payment was recorded.')
    await w.refreshCurrent({preserveView:true})
    await load()
  } catch (cause) {
    if(token===bankActionVersion && spaceId===Number(w.activeId.value))
      reconciliationError.value=financeError(cause)
  } finally {
    if(token===bankActionVersion)bankBusy.value=false
  }
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
  success.value = t('librefinance', 'Movement recorded.')
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
  accountId:0, categoryId:0, customFields:{} })
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
    customFields:{},
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
    formError.value = t('librefinance', 'Enter a description and an amount greater than zero.')
    return
  }
  if (timing.value === 'posted' && (!form.value.accountId || !form.value.categoryId)) {
    formError.value = t('librefinance', 'Select where the money was received or paid and the category.')
    return
  }
  if (timing.value === 'planned' && !canPlan.value) {
    formError.value = t('librefinance', 'Scheduling is not available in this workspace.')
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
        customFields:form.value.customFields,
      })
    }
    if (spaceId !== Number(w.activeId.value)) return
    formOpen.value = false
    success.value = t('librefinance', 'Movement recorded.')
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
    date:today(), note:t('librefinance', 'Transfer between my accounts') }
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
    transferError.value = t('librefinance', 'Choose two different accounts and enter the amount.')
    return
  }
  transferSaving.value = true
  try {
    await financeApi('librefinance',current,'/journals','POST',{
      debitId:Number(toId),creditId:Number(fromId),
      amountMinor,date,memo:note.trim()||t('librefinance', 'Transfer between my accounts'),
      externalKey:transferKey.value,
    })
    if (current !== Number(w.activeId.value)) return
    transferOpen.value = false
    success.value = t('librefinance', 'Transfer recorded between your accounts. No operation was sent to the bank.')
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
  bankActionVersion++
  importOpen.value=false
  importFile.value=null
  importError.value=''
  importing.value=false
  reconcileRow.value=null
  journalId.value=0
  reconciliationError.value=''
  bankBusy.value=false
  formOpen.value = false
  transferOpen.value = false
  payment.value = null
  everHadRows.value = false
  clearFilters()
})
watch(() => [Number(w.activeId.value), w.space.value?.saasManaged],
  loadAllowedBankFormats, { immediate: true })
watch([w.activeId,w.modulesLoadedFor,accountFilter,directionFilter,statusFilter,search], load,
  {immediate:true})
onBeforeUnmount(() => {
  requestVersion++
  bankActionVersion++
  ++bankFormatSequence
  if (searchTimer.value) clearTimeout(searchTimer.value)
})
const stateLabel = row => ({
  planned:t('librefinance', 'Planned movement'),posted:row.reversal?t('librefinance', 'Reversal'):t('librefinance', 'Posted movement'),
  pending:t('librefinance', 'Pending review'),
})[row.state] || row.state
const directionLabel = row => row.kind==='transfer'?t('librefinance', 'Transfer'):
  row.kind==='in'?t('librefinance', 'Inflow'):t('librefinance', 'Outflow')
const currency = row => w.money(row.amountMinor)
</script>

<template>
  <div class="lf-view lf-activity-view">
    <LfPageHeader :title="t('librefinance', 'Financial movements')" :eyebrow="t('librefinance', 'FINANCE')"
      :description="t('librefinance', 'All expected and completed inflows and outflows in one list. Bank entries requiring review are identified.')">
      <template #actions>
        <NcActions v-if="w.writable.value && canReviewBank && banks.length && allowedBankFormats.length && !bankFormatsLoading"
          id="lf-import-menu" :menu-name="t('librefinance', 'Import statement')"
          :aria-label="t('librefinance', 'Import bank statement')" :default-icon="mdiFileUploadOutline"
          variant="secondary" force-menu>
          <NcActionButton v-if="allowedBankFormats.includes('csv')" close-after-click @click="openImport('csv')">
            <template #icon><NcIconSvgWrapper :path="mdiFileDelimitedOutline" :size="20" /></template>
            {{ t('librefinance', 'CSV file') }}
          </NcActionButton>
          <NcActionButton v-if="allowedBankFormats.includes('ofx')" close-after-click @click="openImport('ofx')">
            <template #icon><NcIconSvgWrapper :path="mdiFileCodeOutline" :size="20" /></template>
            {{ t('librefinance', 'OFX file') }}
          </NcActionButton>
        </NcActions>
        <NcPopover v-if="w.writable.value && banks.length>1"
          v-model:shown="moreActionsOpen" popup-role="dialog">
          <template #trigger>
            <NcButton variant="tertiary">{{ t('librefinance', 'More actions') }}</NcButton>
          </template>
          <div class="lf-agenda-filter-content" role="group" :aria-label="t('librefinance', 'Other financial actions')">
            <NcButton variant="tertiary" @click="moreActionsOpen=false;startTransfer()">
              {{ t('librefinance', 'Transfer between accounts') }}
            </NcButton>
          </div>
        </NcPopover>
        <NcButton v-if="w.writable.value" variant="primary" @click="start">
          <template #icon><NcIconSvgWrapper :path="mdiPlus" :size="20" /></template>
          {{ t('librefinance', 'Record') }}
        </NcButton>
      </template>
    </LfPageHeader>
    <NcNoteCard v-if="error" type="error" role="alert">{{error}}</NcNoteCard>
    <NcNoteCard v-if="paymentError" type="error" role="alert">{{paymentError}}</NcNoteCard>
    <NcNoteCard v-if="success" type="success" role="status">{{success}}</NcNoteCard>

    <div v-if="!banks.length" class="lf-activity-first-account">
      <NcButton variant="secondary" @click="navigate('bankAccounts')">
        <template #icon><NcIconSvgWrapper :path="mdiPlus" :size="18" /></template>
        {{ t('librefinance', 'Add bank or cash account') }}
      </NcButton>
    </div>

    <div v-if="showFilters" class="lf-section-heading lf-activity-toolbar">
      <NcTextField v-model="searchDraft" type="search" class="lf-native-finance-search"
        :label="t('librefinance', 'Search movements')" :placeholder="t('librefinance', 'Search by description')"
        maxlength="120" @update:model-value="debounceSearch" @keyup.enter="setSearch" />
      <div class="lf-native-agenda-filters">
        <NcPopover v-if="banks.length" v-model:shown="accountFilterOpen" popup-role="dialog">
          <template #trigger>
            <NcButton variant="tertiary" id="lf-activity-bank"
              :aria-pressed="accountFilter!==0">
              <template #icon><NcIconSvgWrapper :path="mdiBankOutline" :size="18" /></template>
              {{selectedBank?.name || t('librefinance', 'All accounts')}}
            </NcButton>
          </template>
          <div class="lf-agenda-filter-content" role="group" :aria-label="t('librefinance', 'Filter by account')">
            <NcButton variant="tertiary" :aria-pressed="accountFilter===0"
              @click="setAccount(0)">{{ t('librefinance', 'All accounts') }}</NcButton>
            <NcButton v-for="bank in banks" :key="bank.id" variant="tertiary"
              :aria-pressed="Number(accountFilter)===Number(bank.id)"
              @click="setAccount(bank.id)">{{bank.name}}</NcButton>
            <NcButton variant="tertiary" @click="accountFilterOpen=false;navigate('bankAccounts')">
              {{ t('librefinance', 'Manage accounts') }}
            </NcButton>
          </div>
        </NcPopover>
        <NcPopover popup-role="dialog">
          <template #trigger>
            <NcButton variant="tertiary" id="lf-activity-direction"
              :pressed="directionFilter!=='all'">
              <template #icon><NcIconSvgWrapper :path="mdiFilterVariant" :size="18" /></template>
              {{filterOptions.find(o=>o.value===directionFilter)?.label}}
            </NcButton>
          </template>
          <div class="lf-agenda-filter-content" role="group" :aria-label="t('librefinance', 'Filter inflows and outflows')">
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
          <div class="lf-agenda-filter-content" role="group" :aria-label="t('librefinance', 'Filter financial status')">
            <NcButton v-for="option in stateOptions" :key="option.value" variant="tertiary"
              :aria-pressed="statusFilter===option.value"
              @click="statusFilter=option.value">{{option.label}}</NcButton>
          </div>
        </NcPopover>
        <NcButton v-if="accountFilter||statusFilter!=='all'||directionFilter!=='all'||search"
          variant="tertiary" @click="clearFilters">{{ t('librefinance', 'Clear filters') }}</NcButton>
      </div>
    </div>
    <p v-if="showFilters && selectedBank" class="lf-muted lf-activity-balance">
      {{ t('librefinance', 'Recorded balance in {account}:', { account: selectedBank.name }, { escape: false, sanitize: false }) }}
      <strong>{{w.money(selectedBank.balance_minor)}}</strong>
    </p>

    <div v-if="loading && !rows.length" role="status" class="lf-loading">
      <NcLoadingIcon :size="32" /> {{ t('librefinance', 'Loading movements…') }}
    </div>
    <NcEmptyContent v-else-if="!error && !rows.length"
      :name="showFilters ? t('librefinance', 'No movements match these filters') : t('librefinance', 'No movements recorded')"
      :description="showFilters
        ? t('librefinance', 'Change the filters to view other movements.')
        : t('librefinance', 'Record your first actual or planned movement.')"/>
    <div v-else-if="rows.length" class="lf-table-scroll">
      <table class="lf-data-table lf-activity-table">
        <thead><tr>
          <th>{{ t('librefinance', 'Date') }}</th>
          <th>{{ t('librefinance', 'Description') }}</th>
          <th>{{ t('librefinance', 'Type') }}</th>
          <th>{{ t('librefinance', 'Account') }}</th>
          <th>{{ t('librefinance', 'Status') }}</th>
          <th class="lf-numeric">{{ t('librefinance', 'Amount') }}</th>
          <th>{{ t('librefinance', 'Actions') }}</th>
        </tr></thead>
        <tbody>
          <tr v-for="row in rows" :key="row.source+':'+row.sourceId">
            <td><time :datetime="row.date">{{ formatLedgerDate(row.date) }}</time></td>
            <td><strong>{{row.description}}</strong>
              <span v-if="row.source==='statement'" class="lf-muted lf-activity-sub">
                {{ t('librefinance', 'Statement not yet reviewed; does not change the balance.') }}
              </span>
              <span v-if="row.invoiceId" class="lf-muted lf-activity-sub">{{ t('librefinance', 'Linked to invoice') }}</span>
              <span v-if="row.kind==='transfer'" class="lf-muted lf-activity-sub">
                {{ t('librefinance', 'Between own accounts · neither income nor expense') }}
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
                {{row.kind==='in'?t('librefinance', 'Receive'):t('librefinance', 'Pay')}}
              </NcButton>
              <NcButton v-if="row.invoiceId && canInvoice" variant="tertiary"
                size="small" @click="viewInvoice(row)">{{ t('librefinance', 'Invoice') }}</NcButton>
              <NcButton v-if="row.state==='pending' && canReviewBank && w.writable.value"
                variant="tertiary" size="small"
                @click="reviewStatement(row)">{{ t('librefinance', 'Review') }}</NcButton>
              <span v-if="row.state==='posted'" class="lf-muted">{{ t('librefinance', 'Recorded movement') }}</span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <div v-if="nextCursor" class="lf-table-footer">
      <NcButton variant="tertiary" :disabled="loading" @click="more">{{ t('librefinance', 'Load more movements') }}</NcButton>
    </div>
    <NcButton v-if="rows.length" variant="tertiary" size="small" @click="load">
      <template #icon><NcIconSvgWrapper :path="mdiRefresh" :size="18" /></template>
      {{ t('librefinance', 'Refresh') }}
    </NcButton>

    <NcDialog :open="importOpen" size="normal"
      content-classes="lf-finance-dialog-content" :name="t('librefinance', 'Import {format} statement', { format: importFormat.toUpperCase() }, { escape: false, sanitize: false })"
      @update:open="!$event && closeImport()">
      <form class="lf-dialog-form" @submit.prevent="importStatement">
        <p>{{ t('librefinance', 'Import the {format} bank statement. Movements will appear here as Pending review, without duplicating payments.', { format: importFormat.toUpperCase() }, { escape: false, sanitize: false }) }}</p>
        <NcNoteCard v-if="importError" type="error" role="alert">{{importError}}</NcNoteCard>
        <LfSelect v-if="banks.length>1" id="lf-import-bank"
          v-model="importAccount" :label="t('librefinance', 'Statement account')"
          :options="bankOptions" :disabled="importing" />
        <p v-else-if="banks.length===1" class="lf-muted">
          {{ t('librefinance', 'Account: {account}', { account: banks[0].name }, { escape: false, sanitize: false }) }}
        </p>
        <NcFilePicker :key="importFormat" :disabled="importing"
          :label="t('librefinance', 'Select {format} file', { format: importFormat.toUpperCase() }, { escape: false, sanitize: false })" variant="secondary"
          :accept="importFormat==='csv'?['.csv','text/csv']:['.ofx','application/x-ofx']"
          @pick="chooseImportFile" />
        <p class="lf-muted" role="status">{{importFile?.name||t('librefinance', 'No file selected')}}</p>
        <template v-if="importFormat==='csv'">
          <p class="lf-muted">{{ t('librefinance', 'CSV: columns {columns}, with a decimal point and negative amounts for outflows.', { columns: 'id,date,amount,description' }, { escape: false, sanitize: false }) }}</p>
          <NcButton variant="tertiary" :href="csvExample" download="exemplo-extrato.csv">
            {{ t('librefinance', 'Download CSV example') }}
          </NcButton>
        </template>
        <p v-else class="lf-muted">{{ t('librefinance', 'OFX: bank statement with FITID identifiers, date and amount. OFX 1.x and 2.x are accepted, one statement per file.') }}</p>
        <p class="lf-muted">{{ t('librefinance', 'The file is read only for import; it is not stored in Files. Limit: 256 KiB and 500 movements.') }}</p>
        <div class="lf-form-actions">
          <NcButton variant="tertiary" :disabled="importing"
            @click="closeImport">{{ t('librefinance', 'Cancel') }}</NcButton>
          <NcButton variant="primary" type="submit"
            :disabled="importing||!importAccount||!importFile||!allowedBankFormats.includes(importFormat)">
            {{importing?t('librefinance', 'Importing…'):t('librefinance', 'Import {format} for review', { format: importFormat.toUpperCase() }, { escape: false, sanitize: false })}}
          </NcButton>
        </div>
      </form>
    </NcDialog>

    <NcDialog :open="Boolean(reconcileRow)" size="normal"
      content-classes="lf-finance-dialog-content" :name="t('librefinance', 'Review movement')"
      @update:open="!$event && closeReconciliation()">
      <form class="lf-dialog-form" @submit.prevent="matchStatement">
        <p>{{ t('librefinance', 'This amount came from the statement. Match it with money already recorded, without creating another payment or receipt.') }}</p>
        <NcNoteCard v-if="reconciliationError" type="error" role="alert">
          {{reconciliationError}}
        </NcNoteCard>
        <div v-if="reconcileRow" class="lf-dialog-summary">
          <strong>{{reconcileRow.description}}</strong>
          <div class="lf-reconciliation-details">
            <span>{{ t('librefinance', 'Date') }}</span>
            <strong>{{ formatLedgerDate(reconcileRow.date) }}</strong>
            <span>{{ t('librefinance', 'Account') }}</span>
            <strong>{{w.accountNameById(reconcileRow.accountId)}}</strong>
            <span>{{ t('librefinance', 'Amount') }}</span>
            <strong>{{currency(reconcileRow)}}</strong>
          </div>
        </div>
        <LfSelect id="lf-bank-journal" v-model="journalId"
          :label="t('librefinance', 'Matching movement')" :options="journalCandidates"
          :placeholder="t('librefinance', 'Select by date and description')" :disabled="bankBusy" />
        <NcNoteCard v-if="!journalCandidates.length" type="info">
          {{ t('librefinance', 'No matching movement was found among the loaded records. Check whether you already recorded this money; do not create another operation just to review it.') }}
        </NcNoteCard>
        <NcButton v-if="w.nextBeforeId.value" variant="tertiary" :disabled="bankBusy"
          @click="w.loadMore()">{{ t('librefinance', 'Search older movements') }}</NcButton>
        <div class="lf-form-actions">
          <NcButton variant="tertiary" :disabled="bankBusy"
            @click="closeReconciliation">{{ t('librefinance', 'Cancel') }}</NcButton>
          <NcButton variant="primary" type="submit"
            :disabled="bankBusy||!journalCandidates.some(row=>row.value===Number(journalId))">
            {{bankBusy?t('librefinance', 'Reviewing…'):t('librefinance', 'Confirm review')}}
          </NcButton>
        </div>
      </form>
    </NcDialog>

    <NcDialog :open="formOpen" size="normal" content-classes="lf-finance-dialog-content"
      :name="t('librefinance', 'Record movement')" @update:open="!$event && (formOpen=false)">
      <form class="lf-dialog-form lf-activity-entry" @submit.prevent="save">
        <NcNoteCard v-if="formError" type="error" role="alert">{{formError}}</NcNoteCard>
        <fieldset class="lf-entry-direction">
          <legend>{{ t('librefinance', 'Will money come in or go out?') }}</legend>
          <div class="lf-entry-direction-options">
            <button type="button" :class="{selected:kind==='in'}" :aria-pressed="kind==='in'"
              @click="kind='in'">
              <span><strong>{{ t('librefinance', 'Inflow') }}</strong>
                <small>{{ t('librefinance', 'Money I receive') }}</small></span>
            </button>
            <button type="button" :class="{selected:kind==='out'}" :aria-pressed="kind==='out'"
              @click="kind='out'">
              <span><strong>{{ t('librefinance', 'Outflow') }}</strong>
                <small>{{ t('librefinance', 'Money I pay') }}</small></span>
            </button>
          </div>
        </fieldset>
        <fieldset class="lf-entry-direction">
          <legend>{{ t('librefinance', 'When?') }}</legend>
          <div class="lf-entry-direction-options">
            <button type="button" :class="{selected:timing==='posted'}"
              :aria-pressed="timing==='posted'" @click="timing='posted'">
              <span><strong>{{ t('librefinance', 'Already happened') }}</strong>
                <small>{{ t('librefinance', 'I recorded the money') }}</small></span>
            </button>
            <button v-if="canPlan" type="button" :class="{selected:timing==='planned'}"
              :aria-pressed="timing==='planned'" @click="timing='planned'">
              <span><strong>{{ t('librefinance', 'Will happen') }}</strong>
                <small>{{ t('librefinance', 'I will pay or receive') }}</small></span>
            </button>
          </div>
        </fieldset>
        <NcTextField v-model="form.description" :label="t('librefinance', 'Description')" maxlength="255"
          required :placeholder="t('librefinance', 'E.g. electricity bill or customer payment')"/>
        <NcTextField v-model="form.amount" :label="t('librefinance', 'Amount')" inputmode="decimal"
          :placeholder="amountPlaceholder" required />
        <LfDateField v-if="timing==='planned'" id="lf-activity-due"
          v-model="form.dueOn" :label="t('librefinance', 'When is it due?')" />
        <template v-else>
          <LfDateField id="lf-activity-date" v-model="form.date" :label="t('librefinance', 'When did it happen?')" />
          <LfSelect v-if="bankOptions.length" id="lf-activity-payment-account"
            v-model="form.accountId" :options="bankOptions" :label="t('librefinance', 'Which account?')" />
          <NcNoteCard v-else type="info">
            {{ t('librefinance', 'First register the bank or cash account where the money was received or paid.') }}
            <NcButton variant="tertiary" @click="formOpen=false;navigate('bankAccounts')">
              {{ t('librefinance', 'Add account') }}
            </NcButton>
          </NcNoteCard>
          <LfSelect v-if="counterpartOptions.length>1" id="lf-activity-category"
            v-model="form.categoryId" :options="counterpartOptions" :label="t('librefinance', 'Category')" />
          <NcNoteCard v-if="!counterpartOptions.length" type="warning">
            {{ kind === 'in' ? t('librefinance', 'No income category is configured yet. Configure one in Manage to record this movement.') : t('librefinance', 'No expense category is configured yet. Configure one in Manage to record this movement.') }}
          </NcNoteCard>
          <CustomFieldsPanel :key="w.activeId.value+':'+formOpen" v-model="form.customFields"
            entity-kind="journal" />
        </template>
        <div class="lf-form-actions">
          <NcButton variant="tertiary" :disabled="formSaving" @click="formOpen=false">
            {{ t('librefinance', 'Cancel') }}
          </NcButton>
          <NcButton variant="primary" type="submit" :disabled="formSaving
            || (timing==='posted'&&(!form.accountId||!form.categoryId))">
            {{formSaving?t('librefinance', 'Saving…'):t('librefinance', 'Save')}}
          </NcButton>
        </div>
      </form>
    </NcDialog>
    <NcDialog :open="transferOpen" size="normal"
      content-classes="lf-finance-dialog-content" :name="t('librefinance', 'Transfer between accounts')"
      @update:open="!$event && !transferSaving && (transferOpen=false)">
      <form class="lf-dialog-form" @submit.prevent="saveTransfer">
        <NcNoteCard v-if="transferError" type="error" role="alert">{{transferError}}</NcNoteCard>
        <p class="lf-muted">{{ t('librefinance', 'Record a transfer you made between your accounts. No money will be sent by the application.') }}</p>
        <LfSelect id="lf-transfer-from" v-model="transferForm.fromId"
          :label="t('librefinance', 'From account')" :options="bankOptions" :disabled="transferSaving" />
        <LfSelect id="lf-transfer-to" v-model="transferForm.toId"
          :label="t('librefinance', 'To account')" :options="bankOptions" :disabled="transferSaving" />
        <NcTextField v-model="transferForm.amount" :label="t('librefinance', 'Amount')" inputmode="decimal"
          :disabled="transferSaving" required/>
        <LfDateField id="lf-transfer-date" v-model="transferForm.date"
          :label="t('librefinance', 'When?')" />
        <NcTextField v-model="transferForm.note" :label="t('librefinance', 'Description')"
          :disabled="transferSaving" maxlength="255"/>
        <div class="lf-form-actions">
          <NcButton variant="tertiary" :disabled="transferSaving"
            @click="transferOpen=false">{{ t('librefinance', 'Cancel') }}</NcButton>
          <NcButton variant="primary" type="submit"
            :disabled="transferSaving||!transferForm.fromId||!transferForm.toId
              ||transferForm.fromId===transferForm.toId">
            {{transferSaving?t('librefinance', 'Saving…'):t('librefinance', 'Record transfer')}}
          </NcButton>
        </div>
      </form>
    </NcDialog>
    <CashPaymentDialog :open="Boolean(payment)" :source="payment?.source||'obligation'"
      :record="payment?.record" :kind="payment?.kind||'payable'"
      @update:open="!$event && (payment=null)" @paid="onPaid" />
  </div>
</template>

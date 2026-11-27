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
import NcFilePicker from '@nextcloud/vue/components/NcFilePicker'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcPopover from '@nextcloud/vue/components/NcPopover'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { mdiFileUploadOutline, mdiRefresh, mdiLinkVariant, mdiFilterVariant } from '@mdi/js'
import LfPageHeader from '../components/LfPageHeader.vue'
import LfSortableHeader from '../components/LfSortableHeader.vue'
import { useLoadedSort } from '../composables/useLoadedSort.js'
import LfSelect from '../components/LfSelect.vue'
import StatementImportPreview from '../components/StatementImportPreview.vue'
import { useStatementImportPreview } from '../composables/useStatementImportPreview.js'
import LfDateField from '../components/LfDateField.vue'
import { financeApi, financeError } from '../services/api.js'
import { formatLedgerDate } from '../date.js'
const props=defineProps({ embedded:{type:Boolean,default:false} })
const w=inject('lfWorkspace')
const navigate=inject('lfNavigate')
const focusedBank=inject('lfBankFocus',ref(null))
const bankContext=inject('lfBankContext',ref(0))
const { activeId, accounts, journals, nextBeforeId,
  loadMore: loadMoreJournals, money, writable } = w
const chosenAccount=ref(0)
const filter=ref('all')
const draftFrom=ref('')
const draftThrough=ref('')
const period=ref({from:'',through:''})
const invalidPeriod=computed(()=>Boolean(draftFrom.value && draftThrough.value
  && draftFrom.value > draftThrough.value))
const hasFilters=computed(()=>chosenAccount.value!==0 || filter.value!=='all'
  || Boolean(period.value.from || period.value.through))
const transfers=ref([])
const transferCursor=ref(null)
const rows=ref([])
const localSort=useLoadedSort('')
const orderedRows=computed(()=>localSort.order(rows.value,{
  bank_account_id:row=>bankName(row.bank_account_id),
}))
const transferSort=useLoadedSort('')
const orderedTransfers=computed(()=>transferSort.order(transfers.value,{
  booking_date:row=>row.bookingDate,
  fromAccountId:row=>bankName(row.fromAccountId),
  toAccountId:row=>bankName(row.toAccountId),
  amount_minor:row=>Number(row.amountMinor),
}))
const next=ref(null)
const providers=ref([])
const busy=ref(false)
const error=ref('')
const success=ref('')
const importDialog=ref(false)
const importError=ref('')
const importAccount=ref(0)
const statementFile=ref(null)
const importFormat=ref('csv')
const csvDescription = (description) => /[",\r\n]/.test(description)
  ? '"' + description.replaceAll('"', '""') + '"' : description
const csvExample='data:text/csv;charset=utf-8,'+encodeURIComponent(
  'id,date,amount,description\n'
  + 'exemplo-001,2026-11-22,-25.90,' + csvDescription(t('librefinance', 'Example payment')) + '\n'
  + 'exemplo-002,2026-11-22,150.00,' + csvDescription(t('librefinance', 'Example receipt')) + '\n')
const allowedFormats=ref([])
const formatPolicyLoading=ref(true)
let formatPolicySequence=0
const reconcile=ref(null)
const journalId=ref(0)
const reconciliationError=ref('')
let generation=0
const banks=computed(()=>accounts.value.filter(a=>a.is_bank && a.active))
const canImport = computed(() => Number(activeId.value) > 0
  && Number(w.space.value?.id) === Number(activeId.value)
  && writable.value && banks.value.length > 0
  && !formatPolicyLoading.value && allowedFormats.value.length > 0)
const journalCandidates=computed(()=>{
  if (!reconcile.value) return []
  const target = reconcile.value
  return journals.value.filter(entry => entry.postings?.some(post =>
    Number(post.accountId) === Number(target.bank_account_id)
      && Number(post.amountMinor) === Number(target.amount_minor)))
    .map(entry => ({ value: entry.id,
      label: t('librefinance', '{date} · {description} (#{id})', {
        date: formatLedgerDate(entry.effective_on), description: String(entry.memo), id: entry.id,
      }, { escape: false, sanitize: false }) }))
})
const bankChoices=computed(()=>[{value:0,label:t('librefinance', 'All accounts')},
  ...banks.value.map(a=>({value:a.id,label:a.name}))])
const importChoices=computed(()=>banks.value.map(a=>({value:a.id,label:a.name})))
const formatChoices=computed(()=>allowedFormats.value.map(value=>({
  value, label:value === 'ofx' ? t('librefinance', 'OFX (Open Financial Exchange)') : t('librefinance', 'CSV (comma-separated values)'),
})))
const statuses=[
  {value:'all',label:t('librefinance', 'All statuses')},
  {value:'pending',label:t('librefinance', 'Pending movements')},
  {value:'matched',label:t('librefinance', 'Reconciled movements')},
]
function bankName(id) {
  return banks.value.find(a=>a.id===Number(id))?.name
    || t('librefinance', 'Account #{id}', { id: String(id) }, { escape: false, sanitize: false })
}
function chooseReconciliation(row) {
  reconcile.value = row
  journalId.value = 0
  reconciliationError.value = ''
}
function closeReconciliation() {
  reconcile.value = null
  journalId.value = 0
  reconciliationError.value = ''
}

async function loadFormatPolicy() {
  const token = ++formatPolicySequence
  const id = Number(activeId.value)
  const currentSpace = w.space.value
  formatPolicyLoading.value = true
  allowedFormats.value = []
  if (!id || !currentSpace || Number(currentSpace.id) !== id) {
    formatPolicyLoading.value = false
    return
  }
  if (currentSpace.saasManaged !== true) {
    allowedFormats.value = ['csv', 'ofx']
    formatPolicyLoading.value = false
    return
  }
  try {
    const url = generateUrl('/apps/librefinance/api/saas/v1/workspaces/'
      + id + '/policies/banking.allowedImportFormats')
    const response = await axios.get(url)
    if (token !== formatPolicySequence || Number(activeId.value) !== id) return
    const values = response.data.effectiveValue
    allowedFormats.value = Array.isArray(values)
      ? ['csv', 'ofx'].filter(format => values.includes(format)) : []
    if (!allowedFormats.value.includes(importFormat.value)) {
      importFormat.value = allowedFormats.value[0] || 'csv'
    }
  } catch {
    // Fail closed in the UI until the server can resolve effective policy;
    // server-side banking import authorization always remains authoritative.
    if (token === formatPolicySequence) allowedFormats.value = []
  } finally {
    if (token === formatPolicySequence) formatPolicyLoading.value = false
  }
}

function transactionQuery(afterId = 0) {
  const params = new URLSearchParams({
    status: filter.value, bankAccountId: String(chosenAccount.value),
  })
  if (period.value.from) params.set('from', period.value.from)
  if (period.value.through) params.set('through', period.value.through)
  if (afterId) params.set('afterId', String(afterId))
  return '/transactions?' + params.toString()
}
function applyPeriod() {
  if (busy.value || invalidPeriod.value) return
  period.value = {from:draftFrom.value,through:draftThrough.value}
}
function clearPeriod() {
  draftFrom.value=''
  draftThrough.value=''
  if (period.value.from || period.value.through) period.value={from:'',through:''}
}
function clearFilters() {
  chosenAccount.value=0
  filter.value='all'
  clearPeriod()
}
async function load() {
  const token=++generation
  busy.value=true
  error.value=''
  try {
    const query=transactionQuery()
    const [entryResult,providerResult,transferResult]=await Promise.allSettled([
      financeApi('librefinance_banking',activeId.value,query),
      financeApi('librefinance_banking',activeId.value,'/providers'),
      financeApi('librefinance_banking',activeId.value,'/transfers'),
    ])
    if(token!==generation)return
    if (entryResult.status==='rejected') throw entryResult.reason
    rows.value=entryResult.value.transactions
    next.value=entryResult.value.nextAfterId
    providers.value=providerResult.status==='fulfilled' ? providerResult.value.providers : []
    transfers.value=transferResult.status==='fulfilled' ? transferResult.value.transfers : []
    transferCursor.value=transferResult.status==='fulfilled' ? transferResult.value.nextAfterId : null
    if (providerResult.status==='rejected' || transferResult.status==='rejected') {
      error.value=t('librefinance', 'Some banking queries are unavailable. Loaded statements remain visible.')
    }
  }catch(e){if(token===generation)error.value=financeError(e)}
  finally{if(token===generation)busy.value=false}
}
async function more() {
  if (!next.value || busy.value) return
  const token=generation
  busy.value=true
  try {
    const result=await financeApi('librefinance_banking',activeId.value,
      transactionQuery(next.value))
    if(token!==generation)return
    rows.value=[...rows.value,...result.transactions];next.value=result.nextAfterId
  }catch(e){if(token===generation)error.value=financeError(e)}
  finally{if(token===generation)busy.value=false}
}
async function moreTransfers() {
  if (!transferCursor.value || busy.value) return
  const token=generation
  busy.value=true
  try {
    const result=await financeApi('librefinance_banking',activeId.value,
      '/transfers?afterId='+transferCursor.value)
    if(token!==generation)return
    transfers.value=[...transfers.value,...result.transfers]
    transferCursor.value=result.nextAfterId
  }catch(e){if(token===generation)error.value=financeError(e)}
  finally{if(token===generation)busy.value=false}
}
function openImportDialog() {
  if (!canImport.value) return
  importError.value = ''
  statementFile.value = null
  importFormat.value = allowedFormats.value[0]
  // Never silently import into the first account when several are present.
  // Use the explicitly selected account or require a choice.
  importAccount.value = chosenAccount.value > 0
    ? Number(chosenAccount.value)
    : banks.value.length === 1 ? Number(banks.value[0].id) : 0
  importDialog.value = true
}
function closeImportDialog() {
  importDialog.value = false
  statementFile.value = null
  importError.value = ''
}
function pickFile(files) {
  resetImportPreview()
  if (!Array.isArray(files) || files.length !== 1) {
    statementFile.value = null
    importError.value = t('librefinance', 'Select only one file.')
    return
  }
  const file = files[0]
  if (!file.name.toLowerCase().endsWith('.' + importFormat.value)) {
    statementFile.value = null
    importError.value = t('librefinance', 'Select a {format} file.', { format: importFormat.value.toUpperCase() }, { escape: false, sanitize: false })
    return
  }
  if (!file.size || file.size > 262144) {
    statementFile.value = null
    importError.value = t('librefinance', 'The file must not be empty or exceed 256 KiB.')
    return
  }
  statementFile.value = file
}
const importAllowed = computed(() => canImport.value && allowedFormats.value.includes(importFormat.value)
  && banks.value.some(bank => Number(bank.id) === Number(importAccount.value)))
const { preview: importPreview, previewing, confirming: importing, canConfirm: canConfirmImport,
  reset: resetImportPreview, review: reviewStatementImport, confirm: importStatement } =
  useStatementImportPreview({
    spaceId: activeId, account: importAccount, file: statementFile, format: importFormat,
    allowed: importAllowed, open: importDialog, error: importError,
    async onConfirmed(out) {
      success.value = t('librefinance', 'Imported movements: {imported}; already present: {skipped}.', {
        imported: out.imported, skipped: out.skipped,
      })
      closeImportDialog()
      await load()
    },
  })
async function match() {
  if(!reconcile.value||busy.value)return
  const id=Number(journalId.value)
  if(!Number.isSafeInteger(id)||id<1||!journalCandidates.value.some(j=>j.value===id)){
    reconciliationError.value=t('librefinance', 'Select a compatible journal entry to complete reconciliation.')
    return
  }
  const token=++generation
  busy.value=true;error.value='';success.value='';reconciliationError.value=''
  try{
    await financeApi('librefinance_banking',activeId.value,
      '/transactions/'+reconcile.value.id+'/match','POST',{journalId:id})
    if(token!==generation)return
    closeReconciliation()
    success.value=t('librefinance', 'Movement linked to the existing journal entry.')
    await load()
  }catch(e){if(token===generation)reconciliationError.value=financeError(e)}
  finally{if(token===generation)busy.value=false}
}
watch([bankContext,()=>w.accounts.value],([target,accounts])=>{
  if (Number(target)>0 && accounts.some(a=>Number(a.id)===Number(target)&&a.is_bank)) {
    chosenAccount.value=Number(target)
  }
},{immediate:true})
watch(chosenAccount, id => {
  if (Number(id) >= 0 && Number(bankContext.value) !== Number(id)) {
    bankContext.value = Number(id)
  }
})
watch([chosenAccount,filter,period],()=>{
  rows.value=[]
  next.value=null
  load()
})
watch(() => [Number(activeId.value), Number(w.space.value?.id), w.space.value?.saasManaged],
  loadFormatPolicy, { immediate: true, flush: 'sync' })
watch(canImport, allowed => {
  if (!allowed) closeImportDialog()
})
watch(activeId, closeImportDialog, { flush: 'sync' })
watch(importFormat, () => { statementFile.value = null; importError.value = '' })
// Opening from a pending evidence row lands on that exact authorized
// statement transaction; no payment or ledger entry is created here.
watch(focusedBank, focus => {
  if (!props.embedded || !focus
    || Number(focus.spaceId) !== Number(activeId.value)) return
  const row=focus.transaction
  focusedBank.value=null
  if (!row || !Number.isSafeInteger(Number(row.id)) || Number(row.id)<1
    || !banks.value.some(bank=>Number(bank.id)===Number(row.bank_account_id))
    || row.status!=='pending') return
  chosenAccount.value=Number(row.bank_account_id)
  filter.value='pending'
  clearPeriod()
  if(writable.value) chooseReconciliation(row)
}, { immediate:true })
// The embedded toolbar uses the same resolved authority as this dialog.
defineExpose({ canImport, openImportDialog })
onMounted(load)
onBeforeUnmount(() => { ++generation; ++formatPolicySequence })
</script>
<template>
  <div class="lf-view">
    <LfPageHeader v-if="!embedded" :title="t('librefinance', 'Banks and cash')" :eyebrow="t('librefinance', 'FINANCE')"
      :description="t('librefinance', 'Review statements and reconcile movements without counting internal transfers twice.')">
      <template #actions>
        <NcButton v-if="canImport && rows.length"
          variant="primary" :disabled="busy || importing || formatPolicyLoading"
          @click="openImportDialog">
          <template #icon><NcIconSvgWrapper :path="mdiFileUploadOutline" :size="20" /></template>
          {{ t('librefinance', 'Import statement') }}
        </NcButton>
        <NcButton variant="tertiary" :aria-label="t('librefinance', 'Refresh statements')" :disabled="busy" @click="load">
          <template #icon><NcIconSvgWrapper :path="mdiRefresh" :size="20" /></template>
        </NcButton>
      </template>
    </LfPageHeader>
    <p v-if="embedded" class="lf-muted lf-bank-intro">
      {{ t('librefinance', 'Review what appeared in the bank statement. If the money has already been recorded, just review it. It will not be posted twice.') }}
    </p>
    <details v-if="rows.length || transfers.length" class="lf-finance-explanation">
      <summary>{{ t('librefinance', 'How does reconciliation work?') }}</summary>
      <p>{{ t('librefinance', 'Imported movements do not change the ledger balance until they are reconciled. 1. Import a CSV or OFX statement for review. 2. Find a pending movement. 3. Link it to a journal entry with the same account and amount. Review both sides of historical transfers. Real-time bank synchronization is not supported here.') }}</p>
    </details>
    <NcNoteCard v-if="error" type="error" role="alert">{{error}}</NcNoteCard>
    <NcNoteCard v-if="success" type="success" role="status">{{success}}</NcNoteCard>
    <section class="lf-surface">
      <div v-if="banks.length && (rows.length || next || hasFilters)"
        class="lf-section-heading lf-banking-toolbar">
        <div
          class="lf-native-agenda-filters">
          <NcPopover popup-role="dialog">
            <template #trigger>
              <NcButton id="lf-bank-filter" variant="tertiary" :pressed="chosenAccount !== 0">
                <template #icon><NcIconSvgWrapper :path="mdiFilterVariant" :size="20" /></template>
                {{ t('librefinance', 'Account: {account}', { account: bankChoices.find(option => Number(option.value) === Number(chosenAccount))?.label ?? '' }, { escape: false, sanitize: false }) }}
              </NcButton>
            </template>
            <div class="lf-agenda-filter-content" role="group" :aria-label="t('librefinance', 'Filter by financial account')">
              <NcButton v-for="choice in bankChoices" :key="choice.value"
                variant="tertiary" :aria-pressed="Number(chosenAccount)===Number(choice.value)"
                @click="chosenAccount=Number(choice.value)">{{choice.label}}</NcButton>
            </div>
          </NcPopover>
          <NcPopover popup-role="dialog">
            <template #trigger>
              <NcButton id="lf-bank-status" variant="tertiary" :pressed="filter!=='all'">
                <template #icon><NcIconSvgWrapper :path="mdiFilterVariant" :size="20" /></template>
                {{ t('librefinance', 'Status: {status}', { status: statuses.find(option => option.value === filter)?.label ?? '' }, { escape: false, sanitize: false }) }}
              </NcButton>
            </template>
            <div class="lf-agenda-filter-content" role="group" :aria-label="t('librefinance', 'Filter movements by status')">
              <NcButton v-for="choice in statuses" :key="choice.value"
                variant="tertiary" :aria-pressed="filter===choice.value"
                @click="filter=choice.value">{{choice.label}}</NcButton>
            </div>
          </NcPopover>
        </div>
      </div>
      <form v-if="banks.length" class="lf-filter-row" @submit.prevent="applyPeriod">
        <LfDateField id="lf-bank-from" v-model="draftFrom" :label="t('librefinance', 'From')" />
        <LfDateField id="lf-bank-through" v-model="draftThrough" :label="t('librefinance', 'Through')" />
        <NcButton variant="secondary" type="submit" :disabled="busy || invalidPeriod">{{ t('librefinance', 'Apply period') }}</NcButton>
        <NcButton v-if="hasFilters || draftFrom || draftThrough" variant="tertiary" type="button"
          @click="clearFilters">{{ t('librefinance', 'Clear filters') }}</NcButton>
      </form>
      <p v-if="period.from || period.through" class="lf-muted">
        <span v-if="period.from">{{ t('librefinance', 'From') }}: <time :datetime="period.from">{{ formatLedgerDate(period.from) }}</time></span>
        <span v-if="period.from && period.through"> · </span>
        <span v-if="period.through">{{ t('librefinance', 'Through') }}: <time :datetime="period.through">{{ formatLedgerDate(period.through) }}</time></span>
      </p>
      <p v-if="rows.length" class="lf-dialog-help">
        {{ next
          ? t('librefinance', 'Movements loaded for this filter: {count}. More pages are available.', { count: rows.length }, { escape: false, sanitize: false })
          : t('librefinance', 'Movements loaded for this filter: {count}.', { count: rows.length }, { escape: false, sanitize: false }) }}
        {{ t('librefinance', 'This list is not used to estimate full statement totals.') }}
      </p>
      <div v-if="busy && !rows.length"
        class="lf-loading" role="status">
        <NcLoadingIcon :size="44" /> {{ t('librefinance', 'Loading banking data…') }}
      </div>
      <NcEmptyContent v-else-if="!banks.length"
        :name="t('librefinance', 'No bank or cash account registered')"
        :description="t('librefinance', 'Register where your money is held before importing a statement. Registration does not require connecting a bank.')">
        <template v-if="writable" #action>
          <NcButton variant="primary" @click="navigate('bankAccounts')">{{ t('librefinance', 'Register a bank or cash account') }}</NcButton>
        </template>
      </NcEmptyContent>
      <NcEmptyContent v-else-if="!rows.length"
        :name="hasFilters ? t('librefinance', 'No movements match this filter') : t('librefinance', 'No movements imported')"
        :description="t('librefinance', 'Import a CSV or OFX statement to review movements without changing the ledger balance.')">
        <template #action>
          <NcButton v-if="!hasFilters && canImport && !embedded"
            variant="primary" @click="openImportDialog">{{ t('librefinance', 'Import statement') }}</NcButton>
        </template>
      </NcEmptyContent>
      <div v-else class="lf-table-scroll">
        <table class="lf-data-table">
          <thead><tr>
            <th v-for="col in [
              {key:'booking_date',label:t('librefinance', 'Date')}, {key:'bank_account_id',label:t('librefinance', 'Account')},
              {key:'description',label:t('librefinance', 'Description')}, {key:'amount_minor',label:t('librefinance', 'Amount')},
              {key:'status',label:t('librefinance', 'State')}]" :key="col.key"
              :class="{'lf-numeric':col.key==='amount_minor'}"
              :aria-sort="localSort.ariaSort(col.key)">
              <LfSortableHeader :label="col.label" :column="col.key"
                :active-column="localSort.column.value" :direction="localSort.direction.value"
                scope="loaded" @sort="localSort.sort" />
            </th><th v-if="writable">{{ t('librefinance', 'Actions') }}</th>
          </tr></thead>
          <tbody><tr v-for="row in orderedRows" :key="row.id">
            <td><time :datetime="row.booking_date">{{ formatLedgerDate(row.booking_date) }}</time></td>
            <td>{{bankName(row.bank_account_id)}}</td>
            <td><strong>{{row.description}}</strong></td>
            <td class="lf-numeric">{{money(row.amount_minor)}}</td>
            <td><span :class="row.status==='matched'?'lf-state lf-state-good':'lf-state lf-state-warning'">
              {{row.status==='matched'?t('librefinance', 'Reconciled'):t('librefinance', 'Pending')}}</span></td>
            <td v-if="writable"><NcButton v-if="row.status==='pending'"
              variant="tertiary" :aria-label="t('librefinance', 'Reconcile movement {id}', { id: row.id }, { escape: false, sanitize: false })"
              @click="chooseReconciliation(row)">
              <template #icon><NcIconSvgWrapper :path="mdiLinkVariant" :size="20" /></template>
              {{ t('librefinance', 'Reconcile') }}
            </NcButton></td>
          </tr></tbody>
        </table>
      </div>
      <details v-if="transfers.length" class="lf-finance-explanation lf-transfer-history">
        <summary>{{ t('librefinance', 'Transfers between accounts ({count})', { count: transferCursor ? transfers.length + '+' : transfers.length }, { escape: false, sanitize: false }) }}</summary>
        <p class="lf-muted">{{ t('librefinance', 'Review both sides in the statements. Transfers between your own accounts do not represent income or expenses.') }}</p>
        <div class="lf-table-scroll"><table class="lf-data-table">
          <thead><tr>
            <th v-for="col in [
              {key:'booking_date',label:t('librefinance', 'Date')}, {key:'fromAccountId',label:t('librefinance', 'Source account')},
              {key:'toAccountId',label:t('librefinance', 'Destination account')}, {key:'amount_minor',label:t('librefinance', 'Amount')}]"
              :key="col.key" :class="{'lf-numeric':col.key==='amount_minor'}"
              :aria-sort="transferSort.ariaSort(col.key)">
              <LfSortableHeader :label="col.label" :column="col.key"
                :active-column="transferSort.column.value" :direction="transferSort.direction.value"
                scope="loaded" @sort="transferSort.sort" />
            </th><th>{{ t('librefinance', 'Status') }}</th>
          </tr></thead>
          <tbody><tr v-for="item in orderedTransfers" :key="item.id">
            <td><time :datetime="item.bookingDate">{{ formatLedgerDate(item.bookingDate) }}</time></td><td>{{bankName(item.fromAccountId)}}</td>
            <td>{{bankName(item.toAccountId)}}</td>
            <td class="lf-numeric">{{money(item.amountMinor)}}</td>
            <td><span class="lf-state lf-state-warning">{{ t('librefinance', 'Awaiting review') }}</span></td>
          </tr></tbody>
        </table></div>
        <NcButton v-if="transferCursor" :disabled="busy" @click="moreTransfers">
          {{ t('librefinance', 'Load more transfers') }}
        </NcButton>
      </details>
      <p v-if="localSort.column.value && next" class="lf-muted">
        {{ t('librefinance', 'Sorting applies only to loaded movements.') }}</p>
      <div v-if="next" class="lf-table-footer">
        <NcButton :disabled="busy" @click="more">{{ t('librefinance', 'Load more movements') }}</NcButton>
      </div>
    </section>
    <NcDialog size="normal" content-classes="lf-finance-dialog-content" :open="importDialog" :name="t('librefinance', 'Import bank statement')"
      @update:open="!$event && !importing && closeImportDialog()">
      <form class="lf-dialog-form" @submit.prevent="reviewStatementImport">
        <p v-if="!importPreview">{{ t('librefinance', 'Import movements for review. The file will be analyzed and no journal entries will be created automatically.') }}</p>
        <NcNoteCard v-if="importError" type="error" role="alert">{{ importError }}</NcNoteCard>
        <LfSelect id="lf-import-bank" v-model="importAccount" :label="t('librefinance', 'Destination account')"
          :options="importChoices" :disabled="busy || importing" />
        <LfSelect id="lf-import-format" v-model="importFormat" :label="t('librefinance', 'Statement format')"
          :options="formatChoices" :disabled="busy || importing || formatPolicyLoading" />
        <NcFilePicker :key="importFormat" :disabled="busy || importing || !allowedFormats.includes(importFormat)"
          :label="t('librefinance', 'Select {format} file', { format: importFormat.toUpperCase() }, { escape: false, sanitize: false })" variant="secondary"
          :accept="importFormat === 'ofx' ? ['.ofx','application/x-ofx'] : ['.csv','text/csv']"
          @pick="pickFile" />
        <p class="lf-muted">{{statementFile?.name || t('librefinance', 'No file selected')}}</p>
        <template v-if="!importPreview">
          <details v-if="importFormat === 'csv'" class="lf-finance-explanation">
            <summary>{{ t('librefinance', 'Expected CSV format') }}</summary>
            <p>{{ t('librefinance', 'Use the columns {columns} in this order. Example: {example}. Use a decimal point and a negative sign for outflows. The limit is 256 KiB per file.', {
              columns: 'id,date,amount,description',
              example: 'mov-001,2026-11-20,-25.90,' + csvDescription(t('librefinance', 'Test payment')),
            }, { escape: false, sanitize: false }) }}</p>
            <NcButton variant="tertiary" :href="csvExample" download="exemplo-extrato.csv">
              {{ t('librefinance', 'Download CSV example') }}
            </NcButton>
          </details>
          <p v-else class="lf-muted">{{ t('librefinance', 'Use an OFX bank statement with FITID identifiers, movement dates and amounts. Limit: 256 KiB.') }}</p>
        </template>
        <StatementImportPreview v-if="importPreview" :preview="importPreview" />
        <div class="lf-form-actions">
          <NcButton variant="tertiary" type="button" :disabled="importing" @click="closeImportDialog">{{ t('librefinance', 'Cancel') }}</NcButton>
          <NcButton :variant="canConfirmImport ? 'secondary' : 'primary'" type="submit"
            :disabled="busy || importing || previewing || !importAllowed || !statementFile">
            {{ previewing ? t('librefinance', 'Reviewing statement…') : t('librefinance', 'Preview statement') }}
          </NcButton>
          <NcButton v-if="importPreview?.ready" variant="primary" type="button"
            :disabled="busy || !canConfirmImport" @click="importStatement">
            {{ t('librefinance', 'Confirm import') }}
          </NcButton>
        </div>
      </form>
    </NcDialog>
    <NcDialog size="normal" content-classes="lf-finance-dialog-content" :open="Boolean(reconcile)" :name="t('librefinance', 'Reconcile movement')"
      @update:open="!$event && closeReconciliation()">
      <form class="lf-dialog-form" @submit.prevent="match">
        <p>{{ t('librefinance', 'Review the selected movement before linking it to a journal entry. This step does not record new income or expenses.') }}</p>
        <NcNoteCard v-if="reconciliationError" type="error" role="alert">{{ reconciliationError }}</NcNoteCard>
        <div v-if="reconcile" class="lf-dialog-summary">
          <strong>{{ reconcile.description }}</strong>
          <div class="lf-reconciliation-details">
            <span>{{ t('librefinance', 'Date') }}</span><strong><time :datetime="reconcile.booking_date">{{ formatLedgerDate(reconcile.booking_date) }}</time></strong>
            <span>{{ t('librefinance', 'Financial account') }}</span><strong>{{ bankName(reconcile.bank_account_id) }}</strong>
            <span>{{ t('librefinance', 'Movement') }}</span><strong>{{ Number(reconcile.amount_minor) >= 0 ? t('librefinance', 'Inflow') : t('librefinance', 'Outflow') }}</strong>
            <span>{{ t('librefinance', 'Statement amount') }}</span><strong>{{ money(reconcile.amount_minor) }}</strong>
          </div>
          <small>{{ t('librefinance', 'In review. The corresponding journal entry must use the same account and amount.') }}</small>
        </div>
        <LfSelect id="lf-bank-journal" v-model="journalId"
          :label="t('librefinance', 'Select a posted journal entry')" :options="journalCandidates"
          :placeholder="t('librefinance', 'Select by date and description')" />
        <NcNoteCard v-if="journalCandidates.length===0" type="info">
          {{ t('librefinance', 'No compatible journal entry was found among loaded records. Check the account and amount. Do not create a duplicate journal entry to force reconciliation.') }}
        </NcNoteCard>
        <NcButton v-if="nextBeforeId" variant="tertiary" :disabled="busy"
          @click="loadMoreJournals">{{ t('librefinance', 'Search older journal entries') }}</NcButton>
        <p v-else-if="journalCandidates.length===0" class="lf-dialog-help">
          {{ t('librefinance', 'If there is no corresponding journal entry, record the money received or paid once in Accounting journals, then return to reconcile it.') }}
        </p>
        <div class="lf-form-actions">
          <NcButton variant="tertiary" @click="closeReconciliation">{{ t('librefinance', 'Cancel') }}</NcButton>
          <NcButton variant="primary" type="submit"
            :disabled="busy||!journalCandidates.some(j=>j.value===Number(journalId))">
            {{ t('librefinance', 'Confirm reconciliation') }}
          </NcButton>
        </div>
      </form>
    </NcDialog>
  </div>
</template>

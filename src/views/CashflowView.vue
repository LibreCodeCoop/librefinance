<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { computed, inject, onBeforeUnmount, provide, ref, watch } from 'vue'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { mdiPlus, mdiFileUploadOutline, mdiArrowLeft, mdiRefresh } from '@mdi/js'
import LfPageHeader from '../components/LfPageHeader.vue'
import ObligationsView from './ObligationsView.vue'
import InvoicesView from './InvoicesView.vue'
import BankingView from './BankingView.vue'
import { financeApi, financeError } from '../services/api.js'
import { workQueueItems } from '../financeWorklist.js'
import { formatLedgerDate } from '../date.js'

const props = defineProps({
  // Old URLs remain valid, but these are contextual detail views, not tabs.
  section: { type: String, default: 'finance' },
})
const w = inject('lfWorkspace')
const navigate = inject('lfNavigate', () => {})
const routeHint = inject('lfFinanceDirectionHint', ref('all'))
const focusedInvoice = inject('lfFocusedInvoice')
const focusedObligation = inject('lfFocusedObligation')
const focusedBank = inject('lfBankFocus', ref(null))
const newDetailed = inject('lfNewDetailed', ref(false))
const bankingView = ref(null)
const newSimple = inject('lfNewSimple', ref(false))
const journalOpenRequest = inject('lfJournalOpenRequest', ref(0))
// Context is part of the selected route, never inferred from an untrusted
// document ID. Legacy source links can still supply the preserved hint.
const direction = computed(() => {
  if (['payables','purchases'].includes(props.section)) return 'payable'
  if (['receivables','sales'].includes(props.section)) return 'receivable'
  return ['payable','receivable'].includes(routeHint.value) ? routeHint.value : 'all'
})
const invoiceKind = computed(() => direction.value === 'payable' ? 'bill' : 'invoice')
provide('lfInvoiceKind', invoiceKind)

function enabled(id) {
  return w.modulesLoadedFor.value === Number(w.activeId.value)
    && w.modules.value.some(module => module.id === id && module.enabled)
}
const hasTitles = computed(() => enabled('librefinance_obligations'))
const hasDocuments = computed(() => enabled('librefinance_invoicing'))
const hasBank = computed(() => enabled('librefinance_banking'))
const isOverview = computed(() => props.section === 'finance')
const isSales = computed(() => props.section === 'sales')
const isPurchase = computed(() => props.section === 'purchases')
const isBanking = computed(() => props.section === 'banking')
const isDocument = computed(() => isSales.value || isPurchase.value)
const pageTitle = computed(() => ({
  finance:t('librefinance', 'Payables and receivables'), sales:t('librefinance', 'Sales'), purchases:t('librefinance', 'Purchases'),
  banking:t('librefinance', 'Account statement'),
})[props.section] || t('librefinance', 'Finance'))
const pageDescription = computed(() => ({
  finance:t('librefinance', 'A list of bills and collections, with inflows, outflows, due dates and status. Filter to show only payables or receivables.'),
  sales:t('librefinance', 'Record sales with items and amounts. On confirmation, the receivable appears automatically in Payables and receivables.'),
  purchases:t('librefinance', 'Record purchases with items and amounts. On confirmation, the payable appears automatically in Payables and receivables.'),
  banking:t('librefinance', 'Review the movements reported by the bank. Existing entries do not need to be registered again.'),
})[props.section] || '')

const drafts = ref([])
const draftNext = ref(null)
const movements = ref([])
const movementNext = ref(null)
const queueLoading = ref(false)
const queueError = ref('')
let generation = 0
const pending = computed(() => workQueueItems(drafts.value, movements.value))
const queueLabels = {
  purchase_document: t('librefinance', 'Purchase document'),
  sales_document: t('librefinance', 'Sales document'),
  historic_unlinked: t('librefinance', 'Unlinked historical document'),
  bank_inflow: t('librefinance', 'Statement inflow'),
  bank_outflow: t('librefinance', 'Statement outflow'),
}
const queueStatuses = {
  confirm: t('librefinance', 'To confirm'),
  link_review: t('librefinance', 'Review link'),
  reconcile: t('librefinance', 'To reconcile'),
}
async function loadQueue() {
  const token = ++generation
  drafts.value = []
  movements.value = []
  draftNext.value = null
  movementNext.value = null
  queueError.value = ''
  if (!isOverview.value || !w.activeId.value || (!hasDocuments.value && !hasBank.value)) {
    queueLoading.value = false
    return
  }
  queueLoading.value = true
  const spaceId = Number(w.activeId.value)
  // The canonical obligation list already owns linked invoices. Drafts and
  // legacy documents without a verified link require a human action.
  const requests = [
    hasDocuments.value && hasTitles.value
      ? financeApi('librefinance_invoicing', spaceId, '/invoices?status=attention')
      : Promise.resolve(null),
    hasBank.value
      ? financeApi('librefinance_banking', spaceId, '/transactions?status=pending')
      : Promise.resolve(null),
  ]
  const [docs, bank] = await Promise.allSettled(requests)
  if (token !== generation || spaceId !== Number(w.activeId.value)) return
  if (docs.status === 'fulfilled' && docs.value) {
    drafts.value = docs.value.invoices
    draftNext.value = docs.value.nextAfterId
  } else if (docs.status === 'rejected') {
    queueError.value = t('librefinance', 'Draft documents could not be retrieved.')
  }
  if (bank.status === 'fulfilled' && bank.value) {
    movements.value = bank.value.transactions
    movementNext.value = bank.value.nextAfterId
  } else if (bank.status === 'rejected') {
    queueError.value = [queueError.value, t('librefinance', 'Pending movements could not be retrieved.')].filter(Boolean).join(' ')
  }
  queueLoading.value = false
}
async function moreQueue(kind) {
  const cursor = kind === 'draft' ? draftNext.value : movementNext.value
  if (!cursor || queueLoading.value || !isOverview.value) return
  const token = generation
  const spaceId = Number(w.activeId.value)
  queueLoading.value = true
  queueError.value = ''
  try {
    const result = kind === 'draft'
      ? await financeApi('librefinance_invoicing', spaceId,
        '/invoices?status=attention&afterId=' + cursor)
      : await financeApi('librefinance_banking', spaceId,
        '/transactions?status=pending&afterId=' + cursor)
    if (token !== generation || spaceId !== Number(w.activeId.value)) return
    if (kind === 'draft') {
      drafts.value = [...drafts.value, ...result.invoices]
      draftNext.value = result.nextAfterId
    } else {
      movements.value = [...movements.value, ...result.transactions]
      movementNext.value = result.nextAfterId
    }
  } catch (error) {
    if (token === generation) queueError.value = financeError(error)
  } finally {
    if (token === generation) queueLoading.value = false
  }
}
function review(item) {
  if (['draft','unlinked'].includes(item.type) && hasDocuments.value) {
    routeHint.value = item.kind === 'bill' ? 'payable' : 'receivable'
    focusedInvoice.value = {
      spaceId: Number(w.activeId.value), id: item.id, kind: item.kind,
    }
    navigate(item.kind === 'bill' ? 'purchases' : 'sales')
  } else if (item.type === 'bank' && hasBank.value) {
    focusedBank.value = {
      spaceId: Number(w.activeId.value), transaction: item.transaction,
    }
    navigate('banking')
  }
}
function startSimple(kind='all') {
  if (!w.writable.value || !hasTitles.value
    || !['all','payable','receivable'].includes(kind)) return
  routeHint.value = kind
  newSimple.value = true
}
function startPosted() {
  if (!w.writable.value || !w.activeId.value) return
  // Do not open a dialog while destroying the source view. The destination
  // consumes a one-shot request after mounting.
  journalOpenRequest.value += 1
  navigate('transactions')
}
function startInvoice(kind) {
  if (!w.writable.value || !hasDocuments.value || !['invoice','bill'].includes(kind)) return
  routeHint.value = kind === 'bill' ? 'payable' : 'receivable'
  newDetailed.value = true
}
function startImport() {
  if (!isBanking.value || !hasBank.value || !bankingView.value?.canImport) return
  bankingView.value.openImportDialog()
}
const flow = {
  newSimple,
  newDetailed,
  showDocument(row) {
    const kind = row?.kind === 'payable' ? 'payable' : 'receivable'
    navigate(kind === 'payable' ? 'purchases' : 'sales')
  },
  showAccounts() { navigate('finance') },
  setDocumentDirection(kind) {
    if (['payable','receivable'].includes(kind)) {
      navigate(kind === 'payable' ? 'purchases' : 'sales')
    }
  },
}
provide('lfFlowContext', flow)
watch([w.activeId, hasTitles, hasDocuments, hasBank, () => props.section], loadQueue, { immediate: true })
watch(w.activeId, () => {
  newSimple.value = false
  newDetailed.value = false
  focusedBank.value = null
  if (focusedInvoice) focusedInvoice.value = null
  if (focusedObligation) focusedObligation.value = null
})
onBeforeUnmount(() => { ++generation })
</script>

<template>
  <div class="lf-view lf-unified-finance">
    <LfPageHeader :title="pageTitle" :eyebrow="t('librefinance', 'FINANCE')" :description="pageDescription">
      <template #actions>
        <NcButton v-if="w.writable.value && isOverview" variant="tertiary"
          @click="startPosted">
          {{ t('librefinance', 'Money already came in or went out') }}
        </NcButton>
        <NcButton v-if="w.writable.value && isOverview && hasTitles" variant="primary"
          @click="startSimple('all')">
          <template #icon><NcIconSvgWrapper :path="mdiPlus" :size="20" /></template>
          {{ t('librefinance', 'New bill or collection') }}
        </NcButton>
        <NcButton v-if="w.writable.value && isSales && hasDocuments" variant="primary"
          @click="startInvoice('invoice')">
          <template #icon><NcIconSvgWrapper :path="mdiPlus" :size="20" /></template>
          {{ t('librefinance', 'New sale') }}
        </NcButton>
        <NcButton v-if="w.writable.value && isPurchase && hasDocuments" variant="primary"
          @click="startInvoice('bill')">
          <template #icon><NcIconSvgWrapper :path="mdiPlus" :size="20" /></template>
          {{ t('librefinance', 'New purchase') }}
        </NcButton>
        <NcButton v-if="isBanking" variant="tertiary" @click="navigate('finance')">
          {{ t('librefinance', 'Back to transactions') }}
        </NcButton>
        <NcButton v-if="isBanking && hasBank && bankingView?.canImport"
          variant="primary" @click="startImport">
          <template #icon><NcIconSvgWrapper :path="mdiFileUploadOutline" :size="20" /></template>
          {{ t('librefinance', 'Import statement') }}
        </NcButton>
      </template>
    </LfPageHeader>

    <template v-if="isOverview">
      <ObligationsView v-if="hasTitles" :kind="direction" :embedded="true" />
      <section v-if="(hasDocuments || hasBank) && (pending.length || queueError)"
        class="lf-finance-work-queue" :aria-label="t('librefinance', 'Pending items to review')">
        <div class="lf-section-heading">
          <div>
            <h2>{{ t('librefinance', 'Pending items to review') }}</h2>
            <p class="lf-muted">{{ t('librefinance', 'Drafts are not yet receivables; imported movements are not yet payments.') }}</p>
          </div>
          <NcButton variant="tertiary" :aria-label="t('librefinance', 'Refresh pending tasks')"
            :disabled="queueLoading" @click="loadQueue">
            <template #icon><NcIconSvgWrapper :path="mdiRefresh" :size="20" /></template>
          </NcButton>
        </div>
        <NcNoteCard v-if="queueError" type="warning" role="alert">{{ queueError }}</NcNoteCard>
        <div v-if="queueLoading && !pending.length" role="status" class="lf-loading">
          <NcLoadingIcon :size="28" /> {{ t('librefinance', 'Loading pending items…') }}
        </div>
        <div v-else-if="pending.length" class="lf-table-scroll">
          <table class="lf-data-table">
            <thead><tr><th>{{ t('librefinance', 'Date') }}</th>
              <th>{{ t('librefinance', 'Type') }}</th>
              <th>{{ t('librefinance', 'Description') }}</th>
              <th class="lf-numeric">{{ t('librefinance', 'Amount') }}</th>
              <th>{{ t('librefinance', 'Status') }}</th>
              <th>{{ t('librefinance', 'Action') }}</th></tr></thead>
            <tbody>
              <tr v-for="item in pending" :key="item.key">
                <td><time :datetime="item.date">{{ formatLedgerDate(item.date) }}</time></td>
                <td>{{ queueLabels[item.label] }}</td>
                <td><strong>{{item.description}}</strong></td>
                <td class="lf-numeric">{{ w.money(item.amountMinor) }}</td>
                <td><span class="lf-state lf-state-warning">{{ queueStatuses[item.status] }}</span></td>
                <td>
                  <NcButton variant="tertiary" size="small" @click="review(item)">
                    {{ item.type === 'bank' ? t('librefinance', 'Reconcile')
                      : item.type === 'unlinked' ? t('librefinance', 'Review document') : t('librefinance', 'Continue document') }}
                  </NcButton>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <p v-else-if="!queueError && !queueLoading" class="lf-muted">
          {{ t('librefinance', 'No draft documents or bank movements awaiting reconciliation.') }}
        </p>
        <div v-if="draftNext || movementNext" class="lf-table-footer">
          <NcButton v-if="draftNext" :disabled="queueLoading" @click="moreQueue('draft')">{{ t('librefinance', 'Load more drafts') }}</NcButton>
          <NcButton v-if="movementNext" :disabled="queueLoading" @click="moreQueue('bank')">{{ t('librefinance', 'Load more bank movements') }}</NcButton>
        </div>
      </section>
    </template>
    <InvoicesView v-else-if="isDocument && hasDocuments"
      :key="'document-' + direction" :embedded="true" />
    <BankingView v-else-if="isBanking && hasBank" ref="bankingView" :embedded="true" />
    <NcEmptyContent v-else :name="t('librefinance', 'Feature unavailable in this workspace')"
      :description="t('librefinance', 'This feature is not enabled for the selected workspace.')" />
  </div>
</template>

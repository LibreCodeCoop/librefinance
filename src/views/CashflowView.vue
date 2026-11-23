<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { computed, inject, onBeforeUnmount, provide, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcPopover from '@nextcloud/vue/components/NcPopover'
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
const importRequest = inject('lfBankImportRequest', ref(0))
const newSimple = inject('lfNewSimple', ref(false))
const entryDialog = inject('lfEntryDialog', ref(false))
const menuOpen = ref(false)
const browseOpen = ref(false)
const direction = ref(['payable', 'receivable'].includes(routeHint.value) ? routeHint.value : 'all')
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

const drafts = ref([])
const draftNext = ref(null)
const movements = ref([])
const movementNext = ref(null)
const queueLoading = ref(false)
const queueError = ref('')
let generation = 0
const pending = computed(() => workQueueItems(drafts.value, movements.value))
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
    queueError.value = 'Não foi possível consultar documentos em elaboração.'
  }
  if (bank.status === 'fulfilled' && bank.value) {
    movements.value = bank.value.transactions
    movementNext.value = bank.value.nextAfterId
  } else if (bank.status === 'rejected') {
    queueError.value += ' Não foi possível consultar movimentos pendentes.'
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
    direction.value = routeHint.value
    focusedInvoice.value = {
      spaceId: Number(w.activeId.value), id: item.id, kind: item.kind,
    }
    navigate('documents', { preserveDirection: true })
  } else if (item.type === 'bank' && hasBank.value) {
    focusedBank.value = {
      spaceId: Number(w.activeId.value), transaction: item.transaction,
    }
    navigate('banking')
  }
}
// A paid expense or a received income is a journal, NOT a new obligation.
function startPosted(kind) {
  if (!w.writable.value || !w.activeId.value
    || !['expense','income'].includes(kind)) return
  menuOpen.value = false
  w.direction.value = kind
  navigate('transactions')
  entryDialog.value = true
}
function startSimple(kind) {
  if (!w.writable.value || !hasTitles.value) return
  menuOpen.value = false
  routeHint.value = kind
  direction.value = kind
  newSimple.value = true
  if (!isOverview.value) navigate('finance', { preserveDirection: true })
}
function startInvoice(kind) {
  if (!w.writable.value || !hasDocuments.value) return
  menuOpen.value = false
  routeHint.value = kind === 'bill' ? 'payable' : 'receivable'
  direction.value = routeHint.value
  newDetailed.value = true
  navigate('documents', { preserveDirection: true })
}
function startImport() {
  if (!w.writable.value || !hasBank.value) return
  menuOpen.value = false
  importRequest.value += 1
  navigate('banking')
}
function browse(section) {
  browseOpen.value = false
  if (section === 'documents' && hasDocuments.value) navigate('documents')
  if (section === 'banking' && hasBank.value) navigate('banking')
}
const flow = {
  newSimple,
  newDetailed,
  showDocument(row) {
    routeHint.value = row?.kind === 'payable' ? 'payable' : 'receivable'
    navigate('documents', { preserveDirection: true })
  },
  showAccounts() { navigate('finance') },
  setDocumentDirection(kind) {
    if (['payable', 'receivable'].includes(kind)) {
      routeHint.value = kind
      direction.value = kind
    }
  },
}
provide('lfFlowContext', flow)
watch([w.activeId, hasTitles, hasDocuments, hasBank, () => props.section], loadQueue, { immediate: true })
watch(routeHint, kind => {
  direction.value = ['receivable', 'payable'].includes(kind) ? kind : 'all'
})
watch(w.activeId, () => {
  menuOpen.value = false
  browseOpen.value = false
  newSimple.value = false
  newDetailed.value = false
  focusedBank.value = null
  if (focusedInvoice) focusedInvoice.value = null
  if (focusedObligation) focusedObligation.value = null
})
onBeforeUnmount(() => { ++generation; menuOpen.value = false; browseOpen.value = false })
</script>

<template>
  <div class="lf-view lf-unified-finance">
    <LfPageHeader :title="isOverview ? 'Financeiro'
      : props.section === 'documents' ? 'Documento comercial' : 'Conferência bancária'"
      eyebrow="FINANCEIRO"
      :description="isOverview
        ? 'Um registro para acompanhar contas e cobranças. Documentos e extratos aparecem como tarefas vinculadas, sem duplicar valores.'
        : props.section === 'documents'
          ? 'Prepare ou consulte o documento de origem. Depois de confirmado, o compromisso é acompanhado no Financeiro.'
          : 'Confronte o extrato com lançamentos já registrados, sem gerar pagamentos duplicados.'">
      <template #actions>
        <NcButton v-if="!isOverview" variant="tertiary" @click="navigate('finance')">
          <template #icon><NcIconSvgWrapper :path="mdiArrowLeft" :size="20" /></template>
          Voltar ao Financeiro
        </NcButton>
        <NcPopover v-if="w.writable.value && w.activeId.value"
          v-model:shown="menuOpen" popup-role="dialog">
          <template #trigger>
            <NcButton variant="primary" class="lf-finance-create">
              <template #icon><NcIconSvgWrapper :path="mdiPlus" :size="20" /></template>
              Novo
            </NcButton>
          </template>
          <div class="lf-finance-action-menu" role="group" aria-label="Registrar no Financeiro">
            <p class="lf-muted">Dinheiro que já entrou ou saiu</p>
            <NcButton variant="tertiary" block @click="startPosted('expense')">
              Despesa já paga
            </NcButton>
            <NcButton variant="tertiary" block @click="startPosted('income')">
              Receita já recebida
            </NcButton>
            <p v-if="hasTitles" class="lf-muted">Dinheiro a pagar ou receber no futuro</p>
            <NcButton v-if="hasTitles" variant="tertiary" block
              @click="startSimple('payable')">Conta a pagar</NcButton>
            <NcButton v-if="hasTitles" variant="tertiary" block
              @click="startSimple('receivable')">Cobrança a receber</NcButton>
            <p v-if="hasDocuments" class="lf-muted">Documento com itens e tributos</p>
            <NcButton v-if="hasDocuments" variant="tertiary" block
              @click="startInvoice('invoice')">Fatura de venda</NcButton>
            <NcButton v-if="hasDocuments" variant="tertiary" block
              @click="startInvoice('bill')">Fatura de compra</NcButton>
          </div>
        </NcPopover>
        <NcPopover v-if="isOverview && (hasDocuments || hasBank)"
          v-model:shown="browseOpen" popup-role="dialog">
          <template #trigger>
            <NcButton variant="tertiary">Consultar</NcButton>
          </template>
          <div class="lf-finance-action-menu" role="group" aria-label="Consultar registros financeiros">
            <NcButton v-if="hasDocuments" variant="tertiary" block
              @click="browse('documents')">Documentos comerciais</NcButton>
            <NcButton v-if="hasBank" variant="tertiary" block
              @click="browse('banking')">Histórico de extratos</NcButton>
          </div>
        </NcPopover>
        <NcButton v-if="w.writable.value && hasBank" variant="secondary"
          class="lf-finance-import" @click="startImport">
          <template #icon><NcIconSvgWrapper :path="mdiFileUploadOutline" :size="20" /></template>
          Importar extrato
        </NcButton>
      </template>
    </LfPageHeader>

    <template v-if="isOverview">
      <section v-if="hasDocuments || hasBank" class="lf-finance-work-queue" aria-label="Tarefas para resolver">
        <div class="lf-section-heading">
          <div>
            <h2>Para resolver</h2>
            <p class="lf-muted">Rascunhos ainda não são cobranças; movimentos importados ainda não são pagamentos.</p>
          </div>
          <NcButton variant="tertiary" aria-label="Atualizar tarefas pendentes"
            :disabled="queueLoading" @click="loadQueue">
            <template #icon><NcIconSvgWrapper :path="mdiRefresh" :size="20" /></template>
          </NcButton>
        </div>
        <NcNoteCard v-if="queueError" type="warning" role="alert">{{ queueError }}</NcNoteCard>
        <div v-if="queueLoading && !pending.length" role="status" class="lf-loading">
          <NcLoadingIcon :size="28" /> Buscando pendências…
        </div>
        <div v-else-if="pending.length" class="lf-table-scroll">
          <table class="lf-data-table">
            <thead><tr><th>Data</th><th>Tipo</th><th>Descrição</th><th class="lf-numeric">Valor</th><th>Situação</th><th>Ação</th></tr></thead>
            <tbody>
              <tr v-for="item in pending" :key="item.key">
                <td>{{item.date}}</td>
                <td>{{item.label}}</td>
                <td><strong>{{item.description}}</strong></td>
                <td class="lf-numeric">{{ w.money(item.amountMinor) }}</td>
                <td><span class="lf-state lf-state-warning">{{item.status}}</span></td>
                <td>
                  <NcButton variant="tertiary" size="small" @click="review(item)">
                    {{ item.type === 'bank' ? 'Conciliar'
                      : item.type === 'unlinked' ? 'Revisar documento' : 'Continuar documento' }}
                  </NcButton>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <p v-else-if="!queueError && !queueLoading" class="lf-muted">
          Nenhum documento em elaboração nem movimento bancário pendente de conciliação.
        </p>
        <div v-if="draftNext || movementNext" class="lf-table-footer">
          <NcButton v-if="draftNext" :disabled="queueLoading" @click="moreQueue('draft')">Carregar mais rascunhos</NcButton>
          <NcButton v-if="movementNext" :disabled="queueLoading" @click="moreQueue('bank')">Carregar mais movimentos bancários</NcButton>
        </div>
      </section>
      <ObligationsView v-if="hasTitles" :kind="direction" :embedded="true" />
      <InvoicesView v-else-if="hasDocuments" :embedded="true" />
      <NcEmptyContent v-else-if="!hasBank"
        name="Nenhum recurso financeiro habilitado"
        description="Ative os recursos necessários em Gerenciar → Recursos instalados." />
    </template>
    <InvoicesView v-else-if="props.section === 'documents' && hasDocuments"
      :key="'documents-' + direction" :embedded="true" />
    <BankingView v-else-if="props.section === 'banking' && hasBank" :embedded="true" />
    <NcEmptyContent v-else name="Recurso indisponível nesta gestão"
      description="Este recurso não está habilitado para a gestão selecionada." />
  </div>
</template>

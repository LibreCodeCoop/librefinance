<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { computed, inject, onMounted, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { mdiPlus, mdiArrowUpRight, mdiArrowDownLeft } from '@mdi/js'
import LfSelect from '../components/LfSelect.vue'
import LfDateField from '../components/LfDateField.vue'
import LfPageHeader from '../components/LfPageHeader.vue'
import CustomFieldsPanel from '../components/CustomFieldsPanel.vue'
import FieldStudioShortcut from '../components/FieldStudioShortcut.vue'
import LfMarkdownEditor from '../components/LfMarkdownEditor.vue'
import { toMinorUnits } from '../money.js'
import { financeApi, financeError } from '../services/api.js'

const w = inject('lfWorkspace')
const dialog = inject('lfEntryDialog')
const { writable, activeId, journals, accounts, categoryRecords,
  direction, bankId, categoryId, transactionCategoryId, amount, memo,
  date, busy, banks, categories, nextBeforeId, createEntry, loadMore,
  money, accountNameById, space } = w
const search = ref('')
const filtered = computed(() => journals.value.filter(row => (
  (row.memo || '').toLowerCase().includes(search.value.trim().toLowerCase())
  || (row.effective_on || '').includes(search.value.trim())
)))
const bankChoices = computed(() => banks.value.map(a => ({ value: a.id, label: a.name })))
const counterpartChoices = computed(() => categories.value.map(a =>
  ({ value: a.id, label: a.name + ' · ' + a.code })))
const categoryChoices = computed(() => [
  { value: 0, label: 'Sem categoria' },
  ...categoryRecords.value.filter(c => c.kind === direction.value && c.active && c.effective_account_id)
    .map(c => ({ value: c.id, label: c.code + ' · ' + c.name })),
])
const entryError = ref('')
const entryFields = ref({})
const notesMarkdown = ref('')
const paymentMethods = ref([])
const paymentMethodId = ref(0)
const paymentError = ref('')
let paymentRequest = 0
async function loadPaymentMethods() {
  const token = ++paymentRequest
  paymentMethods.value = []
  paymentError.value = ''
  try {
    let afterId = 0
    for (let page = 0; page < 10; page++) {
      const response = await financeApi('librefinance', Number(activeId.value),
        '/payment-methods' + (afterId ? '?afterId=' + afterId : ''))
      if (token !== paymentRequest) return
      paymentMethods.value = [...paymentMethods.value, ...response.methods]
      afterId = response.nextAfterId
      if (!afterId) break
    }
  } catch (e) { if (token === paymentRequest) paymentError.value = financeError(e) }
}
onMounted(loadPaymentMethods)
watch(activeId, loadPaymentMethods)
const paymentChoices = computed(() => [
  { value:0, label:'Não informar' },
  ...paymentMethods.value.filter(m => m.active && (
    m.bank_account_id === null || Number(m.bank_account_id) === Number(bankId.value)))
    .map(m => ({ value:m.id, label:m.name })),
])
watch(bankId, () => {
  if (!paymentChoices.value.some(m => Number(m.value) === Number(paymentMethodId.value))) {
    paymentMethodId.value = 0
  }
})
const paymentLabel = id => paymentMethods.value.find(m => m.id === id)?.name || ''

const entryMinor = computed(() => {
  try { return toMinorUnits(amount.value) } catch { return null }
})
const selectedBank = computed(() => banks.value.find(item => Number(item.id) === Number(bankId.value)))
const selectedCounterpart = computed(() => counterpartChoices.value.find(item => Number(item.value) === Number(categoryId.value)))
const entrySummary = computed(() => {
  if (!entryMinor.value || !selectedBank.value || !selectedCounterpart.value) return ''
  return (direction.value === 'expense' ? 'Saída de ' : 'Entrada de ')
    + money(entryMinor.value) + (direction.value === 'expense' ? ' da conta ' : ' na conta ')
    + selectedBank.value.name + '. Classificação: ' + selectedCounterpart.value.label + '.'
})
// Picking the only valid account is safe; guessing when there are multiple is not.
watch([dialog, counterpartChoices], ([opened, choices]) => {
  if (!opened) return
  entryError.value = ''
  if (!choices.some(item => Number(item.value) === Number(categoryId.value))) {
    categoryId.value = choices.length === 1 ? Number(choices[0].value) : 0
  } else if (!categoryId.value && choices.length === 1) {
    categoryId.value = Number(choices[0].value)
  }
})
function closeDialog() { entryError.value = ''; dialog.value = false }
async function submit() {
  entryError.value = ''
  try { toMinorUnits(amount.value) } catch (cause) {
    entryError.value = cause.message
    return
  }
  if (!bankId.value || !categoryId.value || !date.value || !memo.value.trim()) {
    entryError.value = 'Informe conta financeira, conta de receita/despesa, data e descrição antes de continuar.'
    return
  }
  await createEntry({ customFields: entryFields.value, notesMarkdown: notesMarkdown.value,
    paymentMethodId: paymentMethodId.value || null })
  if (w.error.value) {
    entryError.value = w.error.value
  } else {
    entryFields.value = {}
    notesMarkdown.value = ''
    paymentMethodId.value = 0
    closeDialog()
  }
}
const formatted = row => money(Math.abs(row.postings?.[0]?.amountMinor || 0))
</script>
<template>
  <div class="lf-view">
    <LfPageHeader title="Lançamentos contábeis" eyebrow="MOVIMENTAÇÕES FINANCEIRAS"
      description="Consulte o registro contábil de entradas e saídas já realizadas. Para valores ainda devidos, utilize Contas a pagar e receber.">
      <template #actions>
        <FieldStudioShortcut entity-kind="journal" />
        <NcButton v-if="writable" variant="primary" @click="dialog = true">
          <template #icon><NcIconSvgWrapper :path="mdiPlus" :size="20" /></template>
          Novo lançamento
        </NcButton>
      </template>
    </LfPageHeader>
    <section class="lf-surface">
      <div class="lf-section-heading">
        <h2>Movimentações contabilizadas</h2>
        <NcTextField v-model="search" label="Filtrar lançamentos carregados"
          placeholder="Descrição ou data" type="search"
          class="lf-search-field" />
      </div>
      <details class="lf-finance-explanation">
        <summary>Como interpretar os lançamentos?</summary>
        <p>Débito e crédito são os dois lados do registro contábil. Um lançamento concluído não é editado:
          diferenças precisam ser corrigidas por outro registro. A busca filtra apenas as páginas carregadas.
          Extratos importados e ainda pendentes são revisados em Bancos e conciliação.</p>
      </details>
      <div class="lf-table-scroll">
        <table class="lf-data-table">
          <thead><tr>
            <th>Data</th><th>Descrição</th><th>Classificação</th>
            <th>Débito</th><th>Crédito</th><th class="lf-numeric">Valor</th>
          </tr></thead>
          <tbody>
            <tr v-for="entry in filtered" :key="entry.id">
              <td>{{ entry.effective_on }}</td>
              <td><strong>{{ entry.memo }}</strong>
                <small v-if="entry.notes_md" class="lf-muted">Notas em Markdown anexadas</small><small v-if="entry.payment_method_id && paymentLabel(entry.payment_method_id)"
                  class="lf-muted"> · {{ paymentLabel(entry.payment_method_id) }}</small></td>
              <td>{{ entry.category_id
                ? (categoryRecords.find(c => c.id === entry.category_id)?.name || '#' + entry.category_id)
                : 'Não classificado' }}</td>
              <td>{{ accountNameById(entry.postings?.find(p => p.amountMinor > 0)?.accountId) }}</td>
              <td>{{ accountNameById(entry.postings?.find(p => p.amountMinor < 0)?.accountId) }}</td>
              <td class="lf-numeric lf-nowrap"><strong>{{ formatted(entry) }}</strong></td>
            </tr>
          </tbody>
        </table>
        <NcEmptyContent v-if="!filtered.length"
          :name="search ? 'Nenhum lançamento encontrado nesta página' : 'Nenhum lançamento contabilizado'"
          description="Utilize a ação Novo lançamento ou carregue mais páginas do histórico." />
      </div>
      <div v-if="nextBeforeId" class="lf-table-footer">
        <NcButton variant="secondary" :disabled="busy" @click="loadMore">Carregar mais lançamentos</NcButton>
      </div>
    </section>

    <NcDialog size="normal" content-classes="lf-finance-dialog-content" :open="dialog" name="Novo lançamento contábil"
      @update:open="dialog = $event">
      <form class="lf-dialog-form" @submit.prevent="submit">
        <p>Registre uma <strong>{{ direction === 'expense' ? 'despesa paga' : 'receita recebida' }}</strong>.
          O saldo contábil da conta financeira será atualizado imediatamente.
          Para apenas conferir um extrato, utilize Bancos e conciliação.</p>
        <NcNoteCard v-if="entryError" type="error" role="alert">{{ entryError }}</NcNoteCard>
        <fieldset class="lf-entry-direction">
          <legend>O que aconteceu?</legend>
          <div class="lf-entry-direction-options">
            <button type="button" :class="{ selected: direction === 'expense' }"
              :aria-pressed="direction === 'expense'" @click="direction = 'expense'">
              <NcIconSvgWrapper :path="mdiArrowUpRight" :size="22" aria-hidden="true" />
              <span><strong>Despesa</strong><small>Dinheiro saiu</small></span>
            </button>
            <button type="button" :class="{ selected: direction === 'income' }"
              :aria-pressed="direction === 'income'" @click="direction = 'income'">
              <NcIconSvgWrapper :path="mdiArrowDownLeft" :size="22" aria-hidden="true" />
              <span><strong>Receita</strong><small>Dinheiro entrou</small></span>
            </button>
          </div>
        </fieldset>
        <div class="lf-form-grid">
          <LfSelect id="lf-journal-bank" v-model="bankId"
            :label="direction === 'expense' ? 'De onde saiu o dinheiro?' : 'Onde o dinheiro entrou?'"
            :options="bankChoices" placeholder="Selecione uma conta" />
          <LfSelect id="lf-journal-counterpart" v-model="categoryId"
            :label="direction === 'expense' ? 'Conta de despesa' : 'Conta de receita'"
            :options="counterpartChoices" placeholder="Escolha a classificação contábil" />
          <LfSelect v-if="categoryChoices.length > 1" id="lf-journal-category"
            v-model="transactionCategoryId" label="Categoria (opcional)"
            :options="categoryChoices" />
          <NcTextField v-model="amount" label="Valor" inputmode="decimal"
            placeholder="0,00" />
          <LfSelect id="lf-journal-payment-method" v-model="paymentMethodId"
            label="Forma de pagamento (opcional)" :options="paymentChoices" />
          <LfDateField id="lf-journal-date" v-model="date" label="Data da movimentação" />
          <NcTextField v-model="memo" label="Descrição breve" maxlength="255"
            placeholder="Ex.: pagamento de material de escritório" class="lf-form-full" />
        </div>
        <NcNoteCard v-if="paymentError" type="warning">
          Formas de pagamento indisponíveis: {{ paymentError }}
        </NcNoteCard>
        <LfMarkdownEditor v-model="notesMarkdown" label="Descrição detalhada" />
        <CustomFieldsPanel v-model="entryFields" entity-kind="journal" />
        <p class="lf-dialog-help">A conta de receita ou despesa determina como a operação aparece nos relatórios.
          A categoria, quando disponível, permite detalhar a classificação.</p>
        <div v-if="entrySummary" class="lf-dialog-summary" role="status" aria-live="polite">
          <strong>Confira antes de contabilizar</strong>
          <p>{{ entrySummary }}</p>
          <small>O registro é definitivo. Corrija um erro por um novo lançamento, nunca alterando o histórico.</small>
        </div>
        <div class="lf-form-actions">
          <NcButton variant="tertiary" @click="closeDialog">Cancelar</NcButton>
          <NcButton variant="primary" type="submit" :disabled="busy">Contabilizar movimentação</NcButton>
        </div>
      </form>
    </NcDialog>
  </div>
</template>

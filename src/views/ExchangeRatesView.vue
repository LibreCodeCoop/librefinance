<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { computed, inject, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import LfPageHeader from '../components/LfPageHeader.vue'
import LfSelect from '../components/LfSelect.vue'
import LfDateField from '../components/LfDateField.vue'
import { financeApi, financeError } from '../services/api.js'
import { formatLedgerDate } from '../date.js'

const w = inject('lfWorkspace')
const target = Number(w.activeId.value)
const rows = ref([])
const currencies = ref({})
const cursor = ref(null)
const busy = ref(false)
const saving = ref(false)
const ready = ref(false)
const dialog = ref(false)
const error = ref('')
const formError = ref('')
const success = ref('')
let alive = true
let generation = 0
const current = () => alive && target === Number(w.activeId.value)
  && target === Number(w.space.value?.id)
const canRecord = computed(() => current() && w.manager.value && ready.value)
const blank = () => ({
  sourceCurrency: '', targetCurrency: '', rate: '',
  effectiveOn: new Date().toLocaleDateString('sv-SE'),
  sourceType: 'manual', sourceRef: '',
})
const form = ref(blank())
const choices = computed(() => Object.keys(currencies.value).sort()
  .map(value => ({ value, label: value })))
const targetChoices = computed(() => choices.value.filter(c => c.value !== form.value.sourceCurrency))
const sourceTypes = [
  { value: 'manual', label: t('librefinance', 'Manual rate') },
  { value: 'imported', label: t('librefinance', 'Imported rate evidence') },
]
const typeLabel = type => sourceTypes.find(s => s.value === type)?.label || type
const canonicalRate = computed(() => form.value.rate.trim().replace(',', '.'))
const valid = computed(() => choices.value.some(c => c.value === form.value.sourceCurrency)
  && targetChoices.value.some(c => c.value === form.value.targetCurrency)
  && /^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,12})?$/.test(canonicalRate.value)
  && /[1-9]/.test(canonicalRate.value)
  && /^\d{4}-\d{2}-\d{2}$/.test(form.value.effectiveOn)
  && sourceTypes.some(s => s.value === form.value.sourceType)
  && /^[A-Za-z0-9][A-Za-z0-9_.-]{0,95}$/.test(form.value.sourceRef.trim()))

function describe(cause) {
  const code = cause.response?.data?.error
  if (code === 'invalid_exchange_rate') return t('librefinance', 'Enter a positive decimal exchange rate.')
  if (code === 'invalid_rate_source') return t('librefinance', 'Enter a reference identifier, not a URL or credential.')
  if (code === 'invalid_exchange_pair') return t('librefinance', 'Choose two different currencies.')
  if (code === 'invalid_date') return t('librefinance', 'Enter a valid date.')
  return financeError(cause)
}

async function load(append = false) {
  if (!current() || busy.value || (append && !cursor.value)) return
  const token = ++generation
  const after = append ? cursor.value : 0
  busy.value = true
  error.value = ''
  if (!append) {
    ready.value = false
    rows.value = []
    cursor.value = null
  }
  try {
    const data = await financeApi('librefinance', target, '/exchange-rates?afterId=' + after)
    if (!current() || token !== generation) return
    if (data.contractVersion !== 1) {
      throw new Error(t('librefinance', 'Unsupported exchange-rate history version.'))
    }
    currencies.value = data.supportedCurrencies
    rows.value = append ? [...rows.value, ...data.rates] : data.rates
    cursor.value = data.nextAfterId
    ready.value = true
  } catch (cause) {
    if (!current() || token !== generation) return
    // Fail closed even if a subsequent page becomes unauthorized.
    rows.value = []
    currencies.value = {}
    cursor.value = null
    ready.value = false
    error.value = describe(cause)
  } finally {
    if (current() && token === generation) busy.value = false
  }
}

function openRecord() {
  if (!canRecord.value || busy.value || saving.value) return
  form.value = blank()
  formError.value = ''
  success.value = ''
  dialog.value = true
}
async function record() {
  if (!canRecord.value || !valid.value || saving.value || busy.value) return
  const payload = { ...form.value, rate: canonicalRate.value, sourceRef: form.value.sourceRef.trim() }
  saving.value = true
  formError.value = ''
  try {
    const data = await financeApi('librefinance', target, '/exchange-rates', 'POST', payload)
    if (!current()) return
    dialog.value = false
    success.value = data.replayed
      ? t('librefinance', 'This exact rate was already recorded.')
      : t('librefinance', 'Exchange rate recorded.')
    await load()
  } catch (cause) {
    if (current()) formError.value = describe(cause)
  } finally {
    if (current()) saving.value = false
  }
}
watch(() => form.value.sourceCurrency, value => {
  if (value === form.value.targetCurrency) form.value.targetCurrency = ''
})
watch(canRecord, allowed => {
  if (!allowed) dialog.value = false
})
onMounted(() => load())
onBeforeUnmount(() => { alive = false; ++generation })
</script>

<template>
  <div class="lf-view">
    <LfPageHeader :title="t('librefinance', 'Exchange rates')"
      :eyebrow="t('librefinance', 'Finance and tax')"
      :description="t('librefinance', 'Record and review exact historical exchange rates.')">
      <template #actions>
        <NcButton v-if="canRecord" variant="primary" :disabled="busy || saving" @click="openRecord">
          {{ t('librefinance', 'Record exchange rate') }}
        </NcButton>
      </template>
    </LfPageHeader>
    <NcNoteCard type="info">
      {{ t('librefinance', 'Recorded rates are historical evidence. They do not convert balances or post transactions.') }}
    </NcNoteCard>
    <NcNoteCard v-if="error" type="error" role="alert">{{ error }}</NcNoteCard>
    <NcNoteCard v-if="success" type="success" role="status">{{ success }}</NcNoteCard>
    <section class="lf-surface">
      <div class="lf-section-heading"><h2>{{ t('librefinance', 'Exchange-rate history') }}</h2></div>
      <div v-if="rows.length" class="lf-table-scroll">
        <table class="lf-data-table">
          <thead><tr>
            <th scope="col">{{ t('librefinance', 'Source currency') }}</th>
            <th scope="col">{{ t('librefinance', 'Target currency') }}</th>
            <th scope="col" class="lf-numeric">{{ t('librefinance', 'Exchange rate') }}</th>
            <th scope="col">{{ t('librefinance', 'Effective date') }}</th>
            <th scope="col">{{ t('librefinance', 'Source') }}</th>
            <th scope="col">{{ t('librefinance', 'Source reference') }}</th>
            <th scope="col">{{ t('librefinance', 'Recorded by') }}</th>
          </tr></thead>
          <tbody><tr v-for="row in rows" :key="row.id" :data-rate-id="row.id">
            <td>{{ row.sourceCurrency }}</td><td>{{ row.targetCurrency }}</td>
            <td class="lf-numeric">{{ row.rate }}</td>
            <td><time :datetime="row.effectiveOn">{{ formatLedgerDate(row.effectiveOn) }}</time></td>
            <td>{{ typeLabel(row.sourceType) }}</td><td>{{ row.sourceRef }}</td>
            <td>{{ row.createdBy }}</td>
          </tr></tbody>
        </table>
      </div>
      <NcLoadingIcon v-if="busy" :size="28" />
      <NcEmptyContent v-else-if="ready && !rows.length" :name="t('librefinance', 'No exchange rates recorded')" />
      <div class="lf-table-footer">
        <NcButton v-if="cursor" :disabled="busy || saving" @click="load(true)">{{ t('librefinance', 'Load more') }}</NcButton>
        <NcButton v-if="!ready && !busy" @click="load()">{{ t('librefinance', 'Try again') }}</NcButton>
      </div>
    </section>
    <NcDialog size="normal" content-classes="lf-finance-dialog-content"
      :open="dialog" :name="t('librefinance', 'Record exchange rate')"
      @update:open="value => { if (!saving) dialog = value }">
      <form class="lf-dialog-form" @submit.prevent="record">
        <NcNoteCard v-if="formError" type="error" role="alert">{{ formError }}</NcNoteCard>
        <LfSelect id="lf-fx-source" v-model="form.sourceCurrency" :options="choices"
          :label="t('librefinance', 'Source currency')" :disabled="saving" />
        <LfSelect id="lf-fx-target" v-model="form.targetCurrency" :options="targetChoices"
          :label="t('librefinance', 'Target currency')" :disabled="saving" />
        <NcTextField v-model="form.rate" :label="t('librefinance', 'Exchange rate')"
          required maxlength="25" inputmode="decimal" :disabled="saving" />
        <p>{{ t('librefinance', 'Enter target major units per one source major unit, without thousands separators.') }}</p>
        <LfDateField id="lf-fx-date" v-model="form.effectiveOn"
          :label="t('librefinance', 'Effective date')" :disabled="saving" />
        <LfSelect id="lf-fx-type" v-model="form.sourceType" :options="sourceTypes"
          :label="t('librefinance', 'Source')" :disabled="saving" />
        <NcTextField v-model="form.sourceRef" :label="t('librefinance', 'Source reference')"
          required maxlength="96" :disabled="saving" />
        <p>{{ t('librefinance', 'Enter a reference identifier, not a URL or credential.') }}</p>
        <div class="lf-form-actions">
          <NcButton variant="tertiary" :disabled="saving" @click="dialog = false">{{ t('librefinance', 'Cancel') }}</NcButton>
          <NcButton variant="primary" type="submit" :disabled="!canRecord || !valid || busy || saving">
            {{ t('librefinance', 'Save') }}
          </NcButton>
        </div>
      </form>
    </NcDialog>
  </div>
</template>

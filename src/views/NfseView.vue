<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { computed, inject, onBeforeUnmount, onMounted, ref } from 'vue'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { mdiPlus, mdiFileDocumentOutline } from '@mdi/js'
import LfPageHeader from '../components/LfPageHeader.vue'
import LfSelect from '../components/LfSelect.vue'
import LfDateField from '../components/LfDateField.vue'
import { financeApi, financeError } from '../services/api.js'
import { ledgerLocale, toMinorUnits } from '../money.js'
import { formatLedgerDate } from '../date.js'
const amountPlaceholder = new Intl.NumberFormat(ledgerLocale(), { minimumFractionDigits: 2 }).format(0)

const w = inject('lfWorkspace')
const { activeId, companies, writable, money } = w
const today = new Date().toLocaleDateString('sv-SE')
const drafts = ref([])
const next = ref(null)
const busy = ref(false)
const error = ref('')
const success = ref('')
const dialog = ref(false)
const form = ref({
  companyId: 0, municipalityCode: '', competence: today,
  serviceCode: '', description: '', amount: '',
})
const issuers = computed(() => companies.value.filter(c =>
  c.active && c.country === 'BR' && /^[0-9]{14}$/.test(c.tax_id))
  .map(c => ({ value: c.id, label: c.legal_name })))
let generation = 0
async function load() {
  const token = ++generation
  busy.value = true
  error.value = ''
  try {
    const result = await financeApi('librefinance_nfse', activeId.value, '/drafts')
    if (token !== generation) return
    drafts.value = result.drafts
    next.value = result.nextAfterId
  } catch (cause) {
    if (token === generation) error.value = financeError(cause)
  } finally {
    if (token === generation) busy.value = false
  }
}
async function more() {
  if (!next.value || busy.value) return
  const token = generation
  busy.value = true
  try {
    const result = await financeApi('librefinance_nfse', activeId.value,
      '/drafts?afterId=' + next.value)
    if (token !== generation) return
    drafts.value = [...drafts.value, ...result.drafts]
    next.value = result.nextAfterId
  } catch (cause) {
    if (token === generation) error.value = financeError(cause)
  } finally {
    if (token === generation) busy.value = false
  }
}
async function save() {
  const issuer = companies.value.find(c => c.id === Number(form.value.companyId)
    && c.active && c.country === 'BR' && /^[0-9]{14}$/.test(c.tax_id))
  if (!issuer || busy.value) {
    error.value = t('librefinance', 'Select a service provider company with a registered CNPJ.')
    return
  }
  const token = ++generation
  busy.value = true
  error.value = ''
  success.value = ''
  try {
    await financeApi('librefinance_nfse', activeId.value, '/drafts', 'POST', {
      operationKey: 'nfse:draft:' + crypto.randomUUID(),
      issuerCnpj: issuer.tax_id,
      companyId: issuer.id,
      municipalityCode: form.value.municipalityCode.trim(),
      competence: form.value.competence,
      serviceCode: form.value.serviceCode.trim(),
      description: form.value.description.trim(),
      amountMinor: toMinorUnits(form.value.amount),
    })
    if (token !== generation) return
    success.value = t('librefinance', 'Draft saved. No DPS was submitted and no fiscal invoice was issued.')
    dialog.value = false
    form.value = {companyId:0,municipalityCode:'',competence:today,
      serviceCode:'',description:'',amount:''}
    await load()
  } catch (cause) {
    if (token === generation) error.value = financeError(cause)
  } finally {
    if (token === generation) busy.value = false
  }
}
onMounted(load)
onBeforeUnmount(() => ++generation)
</script>
<template>
  <div class="lf-view">
    <LfPageHeader :title="t('librefinance', 'Brazilian NFS-e')" :eyebrow="t('librefinance', 'FISCAL EXTENSION')"
      :description="t('librefinance', 'Preparation of service tax information. Issuance is not yet qualified.')">
      <template #actions>
        <NcButton v-if="writable" variant="primary" :disabled="!issuers.length"
          @click="dialog = true">
          <template #icon><NcIconSvgWrapper :path="mdiPlus" :size="20" /></template>
          {{ t('librefinance', 'New draft') }}
        </NcButton>
      </template>
    </LfPageHeader>
    <NcNoteCard type="warning">
      {{ t('librefinance', 'This application does NOT yet sign XML, submit DPS or issue NFS-e. No record here can be treated as an authorized fiscal invoice.') }}
    </NcNoteCard>
    <NcNoteCard v-if="!issuers.length" type="info">
      {{ t('librefinance', 'To create drafts, register a Brazilian company with a valid CNPJ in Companies.') }}
    </NcNoteCard>
    <NcNoteCard v-if="error" type="error" role="alert">{{ error }}</NcNoteCard>
    <NcNoteCard v-if="success" type="success" role="status">{{ success }}</NcNoteCard>
    <section class="lf-surface">
      <div class="lf-section-heading">
        <h2>{{ t('librefinance', 'Service drafts') }}</h2>
        <p class="lf-muted">{{ t('librefinance', 'Documents not yet transmitted') }}</p>
      </div>
      <div v-if="drafts.length" class="lf-table-scroll">
        <table class="lf-data-table">
          <thead><tr><th>{{ t('librefinance', 'Identifier') }}</th>
            <th>{{ t('librefinance', 'Service provider') }}</th>
            <th>{{ t('librefinance', 'Tax competence date') }}</th>
            <th>{{ t('librefinance', 'Service code') }}</th>
            <th>{{ t('librefinance', 'Description') }}</th>
            <th class="lf-numeric">{{ t('librefinance', 'Amount') }}</th>
            <th>{{ t('librefinance', 'State') }}</th></tr></thead>
          <tbody><tr v-for="row in drafts" :key="row.id">
            <td class="lf-code">#{{ row.id }}</td>
            <td>{{ companies.find(c => c.id === row.company_id)?.legal_name || '—' }}</td>
            <td><time :datetime="row.competence">{{ formatLedgerDate(row.competence) }}</time></td>
            <td>{{ row.service_code }}</td>
            <td><strong>{{ row.description }}</strong></td>
            <td class="lf-numeric">{{ money(row.amount_minor) }}</td>
            <td><span class="lf-state lf-state-warning">{{ t('librefinance', 'Draft') }}</span></td>
          </tr></tbody>
        </table>
      </div>
      <NcEmptyContent v-else-if="!busy" :name="t('librefinance', 'No service drafts')"
        :description="t('librefinance', 'Create a draft using the button above, without submitting a fiscal invoice.')" />
      <div v-if="next" class="lf-table-footer">
        <NcButton :disabled="busy" @click="more">{{ t('librefinance', 'Load more') }}</NcButton>
      </div>
    </section>
    <NcDialog size="normal" content-classes="lf-finance-dialog-content" :open="dialog" :name="t('librefinance', 'Prepare service for NFS-e')"
      @update:open="dialog = $event">
      <form class="lf-dialog-form" @submit.prevent="save">
        <NcNoteCard type="warning">{{ t('librefinance', 'This action saves a draft without submission.') }}</NcNoteCard>
        <LfSelect id="lf-nfse-company" v-model="form.companyId"
          :options="issuers" :label="t('librefinance', 'Service provider company')" />
        <NcTextField v-model="form.municipalityCode" :label="t('librefinance', 'IBGE municipality code')"
          required maxlength="7" />
        <LfDateField id="lf-nfse-competence" v-model="form.competence"
          :label="t('librefinance', 'Tax competence date')" />
        <NcTextField v-model="form.serviceCode" :label="t('librefinance', 'Service tax code')"
          required maxlength="15" />
        <NcTextField v-model="form.description" :label="t('librefinance', 'Service description')"
          required maxlength="255" />
        <NcTextField v-model="form.amount" :label="t('librefinance', 'Amount (BRL)')"
          :placeholder="amountPlaceholder" inputmode="decimal" required />
        <div class="lf-form-actions">
          <NcButton variant="tertiary" @click="dialog=false">{{ t('librefinance', 'Cancel') }}</NcButton>
          <NcButton variant="primary" type="submit" :disabled="busy">
            {{ t('librefinance', 'Save draft') }}
          </NcButton>
        </div>
      </form>
    </NcDialog>
  </div>
</template>

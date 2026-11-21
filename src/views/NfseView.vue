<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { computed, inject, onBeforeUnmount, onMounted, ref } from 'vue'
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
import { toMinorUnits } from '../money.js'

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
    error.value = 'Selecione uma empresa prestadora com CNPJ cadastrado.'
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
    success.value = 'Rascunho salvo. Nenhuma DPS foi enviada nem nota fiscal emitida.'
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
    <LfPageHeader title="NFS-e Brasil" eyebrow="EXTENSÃO FISCAL"
      description="Preparação de informações fiscais de serviços. Emissão ainda não homologada.">
      <template #actions>
        <NcButton v-if="writable" variant="primary" :disabled="!issuers.length"
          @click="dialog = true">
          <template #icon><NcIconSvgWrapper :path="mdiPlus" :size="20" /></template>
          Novo rascunho
        </NcButton>
      </template>
    </LfPageHeader>
    <NcNoteCard type="warning">
      Este aplicativo ainda NÃO assina XML, envia DPS ou emite NFS-e.
      Nenhum registro aqui pode ser tratado como nota fiscal autorizada.
    </NcNoteCard>
    <NcNoteCard v-if="!issuers.length" type="info">
      Para criar rascunhos, cadastre uma empresa brasileira com CNPJ válido
      no menu Empresas.
    </NcNoteCard>
    <NcNoteCard v-if="error" type="error" role="alert">{{ error }}</NcNoteCard>
    <NcNoteCard v-if="success" type="success" role="status">{{ success }}</NcNoteCard>
    <section class="lf-surface">
      <div class="lf-section-heading">
        <h2>Rascunhos de serviços</h2>
        <p class="lf-muted">Documentos ainda não transmitidos</p>
      </div>
      <div class="lf-table-scroll">
        <table class="lf-data-table">
          <thead><tr><th>Identificador</th><th>Prestador</th><th>Competência</th>
            <th>Código serviço</th><th>Descrição</th>
            <th class="lf-numeric">Valor</th><th>Estado</th></tr></thead>
          <tbody><tr v-for="row in drafts" :key="row.id">
            <td class="lf-code">#{{ row.id }}</td>
            <td>{{ companies.find(c => c.id === row.company_id)?.legal_name || '—' }}</td>
            <td>{{ row.competence }}</td>
            <td>{{ row.service_code }}</td>
            <td><strong>{{ row.description }}</strong></td>
            <td class="lf-numeric">{{ money(row.amount_minor) }}</td>
            <td><span class="lf-state lf-state-warning">Rascunho</span></td>
          </tr></tbody>
        </table>
        <NcEmptyContent v-if="!drafts.length" name="Nenhum rascunho de serviço"
          description="Crie um rascunho pelo botão superior, sem transmitir uma nota fiscal." />
      </div>
      <div v-if="next" class="lf-table-footer">
        <NcButton :disabled="busy" @click="more">Carregar mais</NcButton>
      </div>
    </section>
    <NcDialog size="normal" content-classes="lf-finance-dialog-content" :open="dialog" name="Preparar serviço para NFS-e"
      @update:open="dialog = $event">
      <form class="lf-dialog-form" @submit.prevent="save">
        <NcNoteCard type="warning">A ação salva um rascunho, sem transmissão.</NcNoteCard>
        <LfSelect id="lf-nfse-company" v-model="form.companyId"
          :options="issuers" label="Empresa prestadora" />
        <NcTextField v-model="form.municipalityCode" label="Código município IBGE"
          required maxlength="7" />
        <LfDateField id="lf-nfse-competence" v-model="form.competence"
          label="Competência" />
        <NcTextField v-model="form.serviceCode" label="Código de serviço"
          required maxlength="15" />
        <NcTextField v-model="form.description" label="Descrição do serviço"
          required maxlength="255" />
        <NcTextField v-model="form.amount" label="Valor (BRL)"
          placeholder="0,00" inputmode="decimal" required />
        <div class="lf-form-actions">
          <NcButton variant="tertiary" @click="dialog=false">Cancelar</NcButton>
          <NcButton variant="primary" type="submit" :disabled="busy">
            Salvar rascunho
          </NcButton>
        </div>
      </form>
    </NcDialog>
  </div>
</template>

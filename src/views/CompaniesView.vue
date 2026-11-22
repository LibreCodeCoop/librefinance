<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { computed, inject, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { mdiPlus, mdiPencilOutline } from '@mdi/js'
import LfPageHeader from '../components/LfPageHeader.vue'
import LfSelect from '../components/LfSelect.vue'
import CustomFieldsPanel from '../components/CustomFieldsPanel.vue'
import FieldStudioShortcut from '../components/FieldStudioShortcut.vue'
import { financeApi, financeError } from '../services/api.js'
import { generateUrl } from '@nextcloud/router'
import { countryOptions } from '../countries.js'
const w = inject('lfWorkspace')
const { companies, companyNextAfterId, manager, busy, companyEditId,
  companyName, companyTradeName, companyCountry, companyTaxId,
  companyCustomValues,
  companyActive, editCompany, saveCompany, resetCompanyForm, loadMoreCompanies } = w
const dialog = ref(false)
const saveError = ref('')
const logoBusy = ref(false)
const logoError = ref('')
const logoVersion = ref(null)
const logoUrl = computed(() => companyEditId.value && logoVersion.value
  ? generateUrl('/apps/librefinance/api/spaces/' + Number(w.activeId.value)
    + '/companies/' + companyEditId.value + '/logo?v=' + logoVersion.value)
  : '')
async function updateLogo(event) {
  const file = event.target.files?.[0]
  event.target.value = ''
  if (!file || !companyEditId.value || logoBusy.value) return
  logoError.value = ''
  if (!['image/png', 'image/jpeg', 'image/webp'].includes(file.type) || file.size > 524288) {
    logoError.value = 'Escolha uma imagem PNG, JPG ou WebP de até 512 KB.'
    return
  }
  logoBusy.value = true
  try {
    const contents = await new Promise((resolve, reject) => {
      const reader = new FileReader()
      reader.onerror = () => reject(new Error('Falha ao ler imagem.'))
      reader.onload = () => resolve(reader.result)
      reader.readAsDataURL(file)
    })
    const match = /^data:image\/(?:png|jpeg|webp);base64,([a-zA-Z0-9+/=]+)$/.exec(String(contents))
    if (!match) throw new Error('Imagem inválida.')
    const response = await financeApi('librefinance', Number(w.activeId.value),
      '/companies/' + companyEditId.value + '/logo', 'POST', { imageBase64: match[1] })
    logoVersion.value = response.logoVersion
    const company = companies.value.find(row => row.id === companyEditId.value)
    if (company) company.logo_version = logoVersion.value
  } catch (e) { logoError.value = financeError(e) }
  finally { logoBusy.value = false }
}
async function removeLogo() {
  if (!companyEditId.value || logoBusy.value) return
  logoBusy.value = true
  logoError.value = ''
  try {
    await financeApi('librefinance', Number(w.activeId.value),
      '/companies/' + companyEditId.value + '/logo', 'DELETE')
    logoVersion.value = null
    const company = companies.value.find(row => row.id === companyEditId.value)
    if (company) company.logo_version = null
  } catch (e) { logoError.value = financeError(e) }
  finally { logoBusy.value = false }
}
const personal = computed(() => w.space.value?.kind === 'personal')
const countryChoices = countryOptions()
const countryValid = computed(() => /^[A-Za-z]{2}$/.test(companyCountry.value.trim()))
function beginCreate() { resetCompanyForm(); logoVersion.value = null; logoError.value = ''; saveError.value = ''; dialog.value = true }
function beginEdit(company) { editCompany(company); logoVersion.value = company.logo_version; logoError.value = ''; saveError.value = ''; dialog.value = true }
function closeCompany() { saveError.value = ''; dialog.value = false }
async function save() {
  saveError.value = ''
  if (!companyName.value.trim() || !countryValid.value) {
    saveError.value = 'Informe o nome legal e o país em duas letras (ISO 3166-1).'
    return
  }
  await saveCompany()
  if (w.error.value) saveError.value = w.error.value
  else closeCompany()
}
</script>
<template>
  <div class="lf-view">
    <LfPageHeader title="Empresas" eyebrow="CADASTROS"
      :description="personal
        ? 'Opcional para finanças da casa. Você pode controlar receitas e despesas sem cadastrar uma empresa.'
        : 'Identidades legais de organizações, com campos adicionais próprios de cada empresa.'">
      <template #actions>
        <FieldStudioShortcut entity-kind="company" />
        <NcButton v-if="manager" variant="primary" @click="beginCreate">
          <template #icon><NcIconSvgWrapper :path="mdiPlus" :size="20" /></template>
          Cadastrar empresa
        </NcButton>
      </template>
    </LfPageHeader>
    <section class="lf-surface">
      <div class="lf-section-heading">
        <div><h2>Entidades cadastradas</h2>
          <p>O país e a identificação fiscal não implicam validação por autoridades.</p></div>
      </div>
      <div class="lf-table-scroll">
        <table class="lf-data-table">
          <thead><tr><th>Nome legal</th><th>País</th><th>Identificador fiscal</th>
            <th>Situação</th><th v-if="manager">Ações</th></tr></thead>
          <tbody><tr v-for="company in companies" :key="company.id">
            <td><span class="lf-company-list-identity">
              <img v-if="company.logo_version" class="lf-company-mini-logo"
                :src="generateUrl('/apps/librefinance/api/spaces/' + Number(w.activeId.value)
                  + '/companies/' + company.id + '/logo?v=' + company.logo_version)"
                :alt="'Logotipo de ' + company.legal_name" />
              <strong>{{ company.legal_name }}</strong>
              </span>
              <small v-if="company.trade_name" class="lf-muted">{{ company.trade_name }}</small></td>
            <td>{{ company.country }}</td>
            <td>{{ company.tax_id || 'Não informado' }}</td>
            <td><span :class="company.active?'lf-state lf-state-good':'lf-state'">
              {{ company.active?'Ativa':'Inativa' }}</span></td>
            <td v-if="manager"><NcButton variant="tertiary"
              :aria-label="'Editar ' + company.legal_name" @click="beginEdit(company)">
              <template #icon><NcIconSvgWrapper :path="mdiPencilOutline" :size="20" /></template>
            </NcButton></td>
          </tr></tbody>
        </table>
        <NcEmptyContent v-if="!companies.length" name="Nenhuma empresa cadastrada"
          description="Comece com nome legal e país; os demais dados são opcionais ou configuráveis." />
      </div>
      <div v-if="companyNextAfterId" class="lf-table-footer">
        <NcButton variant="secondary" @click="loadMoreCompanies">Carregar mais empresas</NcButton>
      </div>
    </section>
    <NcDialog size="normal" content-classes="lf-finance-dialog-content" :open="dialog"
      :name="companyEditId ? 'Editar empresa' : 'Cadastrar empresa'"
      @update:open="!$event && closeCompany()">
      <form class="lf-dialog-form" @submit.prevent="save">
        <p>Comece com o mínimo necessário. País e identificadores são genéricos; dados regionais podem ser cadastrados como campos adicionais.</p>
        <NcNoteCard v-if="saveError" type="error" role="alert">{{ saveError }}</NcNoteCard>
        <NcTextField v-model="companyName" label="Nome legal" maxlength="180" required />
        <LfSelect id="lf-company-country" v-model="companyCountry" label="País de registro"
          :options="countryChoices" placeholder="Selecione o país" />
        <div class="lf-company-logo-settings">
          <label class="lf-field-label" for="lf-company-logo-file">Logotipo da empresa</label>
          <template v-if="companyEditId">
            <img v-if="logoVersion" class="lf-company-preview-logo"
              :src="logoUrl" alt="Logotipo atual da empresa" />
            <input id="lf-company-logo-file" type="file" accept="image/png,image/jpeg,image/webp"
              :disabled="logoBusy" aria-label="Selecionar logotipo da empresa"
              @change="updateLogo" />
            <small class="lf-muted">PNG, JPG ou WebP; máximo de 512 KB e 2048 × 2048 pixels.
              Guardado na área privada do LibreFinance, sem URL pública.</small>
            <NcButton v-if="logoVersion" variant="tertiary" :disabled="logoBusy"
              @click.prevent="removeLogo">Remover logotipo</NcButton>
          </template>
          <p v-else class="lf-muted">Salve a empresa para adicionar seu logotipo.</p>
          <NcNoteCard v-if="logoError" type="error" role="alert">{{ logoError }}</NcNoteCard>
        </div>
        <details class="lf-finance-explanation">
          <summary>Outras informações (opcionais)</summary>
          <div class="lf-dialog-optional-fields">
            <NcTextField v-model="companyTradeName" label="Nome comercial" maxlength="180" />
            <NcTextField v-model="companyTaxId" label="Identificador fiscal local" maxlength="40" />
          </div>
          <p>Identificadores locais variam conforme o país. Valores brasileiros existentes são preservados.</p>
        </details>
        <CustomFieldsPanel :key="companyEditId || 'new'" v-model="companyCustomValues"
          entity-kind="company" />
        <NcCheckboxRadioSwitch v-if="companyEditId" v-model="companyActive" type="checkbox">
          Cadastro ativo
        </NcCheckboxRadioSwitch>
        <div class="lf-form-actions">
          <NcButton variant="tertiary" @click="closeCompany">Cancelar</NcButton>
          <NcButton type="submit" variant="primary" :disabled="busy">Salvar empresa</NcButton>
        </div>
      </form>
    </NcDialog>
  </div>
</template>

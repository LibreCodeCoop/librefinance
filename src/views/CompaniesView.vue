<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { computed, inject, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { mdiPlus, mdiPencilOutline } from '@mdi/js'
import LfPageHeader from '../components/LfPageHeader.vue'
import LfSortableHeader from '../components/LfSortableHeader.vue'
import { useLoadedSort } from '../composables/useLoadedSort.js'
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
const search = ref(new URLSearchParams(window.location.search).get('search') || '')
const searchTerm = ref(search.value.trim())
const searchResults = ref([])
const searchNext = ref(null)
const searchLoading = ref(false)
const searchProblem = ref('')
let searchSequence = 0
let searchTimer = null
const localSort = useLoadedSort('')
const listedCompanies = computed(() => localSort.order(
  searchTerm.value ? searchResults.value : companies.value))
async function loadSearch(append = false) {
  const token = ++searchSequence
  const term = searchTerm.value.trim().slice(0,120)
  if (!term || !w.activeId.value) {
    searchResults.value = []
    searchNext.value = null
    searchProblem.value = ''
    return
  }
  searchLoading.value = true
  searchProblem.value = ''
  try {
    const next = append ? searchNext.value : null
    const data = await financeApi('librefinance', Number(w.activeId.value),
      '/companies?search=' + encodeURIComponent(term)
        + (next ? '&afterId=' + Number(next) : ''))
    if (token !== searchSequence) return
    searchResults.value = append ? [...searchResults.value, ...data.companies] : data.companies
    searchNext.value = data.nextAfterId
  } catch (error) {
    if (token === searchSequence) searchProblem.value = financeError(error)
  } finally {
    if (token === searchSequence) searchLoading.value = false
  }
}
function scheduleSearch() {
  if (searchTimer) clearTimeout(searchTimer)
  searchTimer = setTimeout(() => {
    searchTerm.value = search.value.trim().slice(0,120)
    const url = new URL(window.location.href)
    if (searchTerm.value) url.searchParams.set('search',searchTerm.value)
    else url.searchParams.delete('search')
    window.history.replaceState(null,'',url.pathname+url.search)
  },280)
}
watch([searchTerm, () => w.activeId.value], () => loadSearch())
onMounted(() => { if (searchTerm.value) loadSearch() })
onBeforeUnmount(() => { ++searchSequence; if (searchTimer) clearTimeout(searchTimer) })
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
  else {
    if (searchTerm.value) await loadSearch()
    closeCompany()
  }
}
</script>
<template>
  <div class="lf-view">
    <LfPageHeader title="Dados fiscais da empresa" eyebrow="DENTRO DA ÁREA FINANCEIRA"
      :description="personal
        ? 'Opcional: seu dinheiro e suas contas da casa funcionam sem empresa ou CNPJ.'
        : 'Cadastre as empresas ou emissores fiscais vinculados à área financeira atual. Um cadastro fiscal não cria outra área nem mistura os saldos.'">
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
        <div><h2>Empresas nesta área</h2>
          <p>Estes dados identificam empresas em documentos; as contas, pessoas e lançamentos continuam associados à área financeira selecionada.</p></div>
        <NcTextField v-model="search" label="Pesquisar empresas" type="search"
          placeholder="Buscar em todas as empresas" maxlength="120"
          class="lf-search-field" @update:model-value="scheduleSearch" />
      </div>
      <NcNoteCard v-if="searchProblem" type="error">{{searchProblem}}</NcNoteCard>
      <p v-if="searchLoading" role="status" class="lf-muted">Buscando empresas…</p>
      <div v-if="listedCompanies.length" class="lf-table-scroll">
        <table class="lf-data-table">
          <thead><tr>
            <th v-for="col in [
              {key:'legal_name',label:'Nome legal'}, {key:'country',label:'País'},
              {key:'tax_id',label:'Identificador fiscal'}, {key:'active',label:'Situação'}]"
              :key="col.key" :aria-sort="localSort.ariaSort(col.key)">
              <LfSortableHeader :label="col.label" :column="col.key"
                :active-column="localSort.column.value" :direction="localSort.direction.value"
                scope="loaded" @sort="localSort.sort" />
            </th>
            <th v-if="manager">Ações</th></tr></thead>
          <tbody><tr v-for="company in listedCompanies" :key="company.id">
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
      </div>
      <NcEmptyContent v-else-if="!searchLoading"
        :name="searchTerm ? 'Nenhuma empresa encontrada' : 'Nenhuma empresa cadastrada'"
        :description="searchTerm ? 'Altere a pesquisa para localizar outras empresas.'
          : 'Comece com nome legal e país; os demais dados são opcionais ou configuráveis.'" />
      <p v-if="localSort.column.value && (companyNextAfterId || searchNext)"
        class="lf-muted">A ordenação considera somente os registros já carregados.</p>
      <div v-if="companyNextAfterId || searchNext" class="lf-table-footer">
        <NcButton v-if="!searchTerm" variant="secondary" @click="loadMoreCompanies">Carregar mais empresas</NcButton>
        <NcButton v-else-if="searchNext" variant="secondary"
          :disabled="searchLoading" @click="loadSearch(true)">Mais resultados</NcButton>
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

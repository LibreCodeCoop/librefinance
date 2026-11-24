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
import NcColorPicker from '@nextcloud/vue/components/NcColorPicker'
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
import { ledgerLocale } from '../money.js'
const w = inject('lfWorkspace')
const { companies, companyNextAfterId, manager, busy, companyEditId,
  companyName, companyTradeName, companyCountry, companyTaxId,
  companyCustomValues, companyMunicipal, companyState, companyProfile,
  companyActive, editCompany, saveCompany, resetCompanyForm, loadMoreCompanies } = w
const dialog = ref(false)
const onboarding=inject('lfCompanyOnboarding',ref(false))
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
const invalidImageMessage = () => t('librefinance', 'Invalid image.')
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
    logoError.value = t('librefinance', 'Choose a PNG, JPG or WebP image up to 512 KB.')
    return
  }
  logoBusy.value = true
  try {
    const contents = await new Promise((resolve, reject) => {
      const reader = new FileReader()
      reader.onerror = () => reject(new Error(t('librefinance', 'Failed to read image.')))
      reader.onload = () => resolve(reader.result)
      reader.readAsDataURL(file)
    })
    const match = /^data:image\/(?:png|jpeg|webp);base64,([a-zA-Z0-9+/=]+)$/.exec(String(contents))
    if (!match) throw new Error(invalidImageMessage())
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
const countryChoices = countryOptions(ledgerLocale())
const countryValid = computed(() => /^[A-Za-z]{2}$/.test(companyCountry.value.trim()))
function beginCreate() { resetCompanyForm(); logoVersion.value = null; logoError.value = ''; saveError.value = ''; dialog.value = true }
onMounted(() => {
  if (onboarding.value && manager.value) {
    onboarding.value=false
    beginCreate()
  }
})
function beginEdit(company) { editCompany(company); logoVersion.value = company.logo_version; logoError.value = ''; saveError.value = ''; dialog.value = true }
function closeCompany() { saveError.value = ''; dialog.value = false }
async function save() {
  saveError.value = ''
  if (!companyName.value.trim() || !countryValid.value) {
    saveError.value = t('librefinance', 'Enter the legal name and two-letter country code (ISO 3166-1).')
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
    <LfPageHeader :title="t('librefinance', 'Organization details')" :eyebrow="t('librefinance', 'IDENTITY AND REGISTRATION')"
      :description="personal
        ? t('librefinance', 'Optional: your money and household accounts work without a company or tax ID.')
        : t('librefinance', 'Register companies or fiscal issuers linked to the current financial area. A fiscal registration does not create another area or mix balances.')">
      <template #actions>
        <FieldStudioShortcut entity-kind="company" />
        <NcButton v-if="manager" variant="primary" @click="beginCreate">
          <template #icon><NcIconSvgWrapper :path="mdiPlus" :size="20" /></template>
          {{ t('librefinance', 'Register company') }}
        </NcButton>
      </template>
    </LfPageHeader>
    <NcNoteCard v-if="!personal && companies.length===0" type="warning">
      {{ t('librefinance', 'This workspace has no registered company yet. Internal entries can be organized, but legal details and fiscal issuance depend on identifying an organization.') }}
    </NcNoteCard>
    <section class="lf-surface">
      <div class="lf-section-heading">
        <div><h2>{{ t('librefinance', 'Companies in this workspace') }}</h2>
          <p>{{ t('librefinance', 'Legal registration and visual identity (including color and logo). Companies in this workspace share the ledger, cash and access rights; there is no accounting segregation by tax ID. For independent balances, create another financial workspace.') }}</p></div>
        <NcTextField v-if="companies.length || searchTerm" v-model="search" :label="t('librefinance', 'Search companies')" type="search"
          :placeholder="t('librefinance', 'Search all companies')" maxlength="120"
          class="lf-search-field" @update:model-value="scheduleSearch" />
      </div>
      <NcNoteCard v-if="searchProblem" type="error">{{searchProblem}}</NcNoteCard>
      <p v-if="searchLoading" role="status" class="lf-muted">{{ t('librefinance', 'Searching companies…') }}</p>
      <div v-if="listedCompanies.length" class="lf-table-scroll">
        <table class="lf-data-table">
          <thead><tr>
            <th v-for="col in [
              {key:'legal_name',label:t('librefinance', 'Legal entity name')}, {key:'country',label:t('librefinance', 'Country')},
              {key:'tax_id',label:t('librefinance', 'Tax identifier')}, {key:'active',label:t('librefinance', 'Status')}]"
              :key="col.key" :aria-sort="localSort.ariaSort(col.key)">
              <LfSortableHeader :label="col.label" :column="col.key"
                :active-column="localSort.column.value" :direction="localSort.direction.value"
                scope="loaded" @sort="localSort.sort" />
            </th>
            <th v-if="manager">{{ t('librefinance', 'Actions') }}</th></tr></thead>
          <tbody><tr v-for="company in listedCompanies" :key="company.id">
            <td><span class="lf-company-list-identity">
              <span class="lf-category-dot" :style="{backgroundColor: company.brand_color || '#64748B'}"
                aria-hidden="true" />
              <img v-if="company.logo_version" class="lf-company-mini-logo"
                :src="generateUrl('/apps/librefinance/api/spaces/' + Number(w.activeId.value)
                  + '/companies/' + company.id + '/logo?v=' + company.logo_version)"
                :alt="t('librefinance', 'Logo of {name}', { name: company.legal_name }, { escape: false, sanitize: false })" />
              <strong>{{ company.legal_name }}</strong>
              </span>
              <small v-if="company.trade_name" class="lf-muted">{{ company.trade_name }}</small></td>
            <td>{{ company.country }}</td>
            <td>{{ company.tax_id || t('librefinance', 'Not provided') }}</td>
            <td><span :class="company.active?'lf-state lf-state-good':'lf-state'">
              {{ company.active?t('librefinance', 'Active company'):t('librefinance', 'Inactive company') }}</span></td>
            <td v-if="manager"><NcButton variant="tertiary"
              :aria-label="t('librefinance', 'Edit {name}', { name: company.legal_name }, { escape: false, sanitize: false })" @click="beginEdit(company)">
              <template #icon><NcIconSvgWrapper :path="mdiPencilOutline" :size="20" /></template>
            </NcButton></td>
          </tr></tbody>
        </table>
      </div>
      <NcEmptyContent v-else-if="!searchLoading"
        :name="searchTerm ? t('librefinance', 'No company found') : t('librefinance', 'No companies registered')"
        :description="searchTerm ? t('librefinance', 'Change the search to find other companies.')
          : t('librefinance', 'Use Register company to record the legal identity, address, contacts and fiscal details without creating another cash account.')" />
      <p v-if="localSort.column.value && (companyNextAfterId || searchNext)"
        class="lf-muted">{{ t('librefinance', 'Sorting applies only to the records already loaded.') }}</p>
      <div v-if="companyNextAfterId || searchNext" class="lf-table-footer">
        <NcButton v-if="!searchTerm" variant="secondary" @click="loadMoreCompanies">{{ t('librefinance', 'Load more companies') }}</NcButton>
        <NcButton v-else-if="searchNext" variant="secondary"
          :disabled="searchLoading" @click="loadSearch(true)">{{ t('librefinance', 'More results') }}</NcButton>
      </div>
    </section>
    <NcDialog size="normal" content-classes="lf-finance-dialog-content" :open="dialog"
      :name="companyEditId ? t('librefinance', 'Edit company') : t('librefinance', 'Register company')"
      @update:open="!$event && closeCompany()">
      <form class="lf-dialog-form" @submit.prevent="save">
        <p class="lf-company-ledger-scope">{{ t('librefinance', 'This registration identifies the company in documents. Accounts, balances, history and access rights remain in the current financial workspace. To separate cash and accounting between companies, create another financial workspace.') }}</p>
        <NcNoteCard v-if="saveError" type="error" role="alert">{{ saveError }}</NcNoteCard>

        <fieldset class="lf-company-form-section">
          <legend>{{ t('librefinance', 'Identification') }}</legend>
          <div class="lf-form-grid">
            <NcTextField v-model="companyName" :label="t('librefinance', 'Legal entity name')" maxlength="180" required />
            <NcTextField v-model="companyTradeName" :label="t('librefinance', 'Trade name')" maxlength="180" />
          </div>
          <div class="lf-form-grid">
            <LfSelect id="lf-company-country" v-model="companyCountry" :label="t('librefinance', 'Country of registration')"
              :options="countryChoices" :placeholder="t('librefinance', 'Select the country')" />
            <NcTextField v-model="companyTaxId" :label="t('librefinance', 'Local tax identifier')"
              maxlength="40" />
          </div>
        </fieldset>

        <fieldset class="lf-company-form-section">
          <legend>{{ t('librefinance', 'Other information (optional)') }}</legend>
          <p class="lf-muted">{{ t('librefinance', 'Additional fiscal registrations and records. Country-specific tax validation belongs to the corresponding fiscal feature.') }}</p>
          <div class="lf-form-grid">
            <NcTextField v-model="companyState"
              :label="t('librefinance', 'State or regional registration')" maxlength="40" />
            <NcTextField v-model="companyMunicipal"
              :label="t('librefinance', 'Municipal or local registration')" maxlength="40" />
          </div>
        </fieldset>

        <fieldset class="lf-company-form-section">
          <legend>{{ t('librefinance', 'Contact and online presence') }}</legend>
          <div class="lf-form-grid">
            <NcTextField v-model="companyProfile.contactEmail" :label="t('librefinance', 'Company email')"
              type="email" maxlength="190" />
            <NcTextField v-model="companyProfile.contactPhone" :label="t('librefinance', 'Company telephone')"
              type="tel" maxlength="60" />
          </div>
          <NcTextField v-model="companyProfile.website" :label="t('librefinance', 'Company website (URL)')"
            type="url" :placeholder="t('librefinance', 'https://example.org')" maxlength="255" />
        </fieldset>

        <fieldset class="lf-company-form-section">
          <legend>{{ t('librefinance', 'Company address') }}</legend>
          <NcTextField v-model="companyProfile.addressLine1"
            :label="t('librefinance', 'Address (street and number)')" maxlength="255" />
          <NcTextField v-model="companyProfile.addressLine2"
            :label="t('librefinance', 'Address complement')" maxlength="255" />
          <div class="lf-form-grid">
            <NcTextField v-model="companyProfile.city" :label="t('librefinance', 'City')" maxlength="120" />
            <NcTextField v-model="companyProfile.region" :label="t('librefinance', 'State, province or region')"
              maxlength="120" />
          </div>
          <NcTextField v-model="companyProfile.postalCode"
            :label="t('librefinance', 'Postal code')" maxlength="32" />
        </fieldset>

        <fieldset class="lf-company-form-section">
          <legend>{{ t('librefinance', 'Visual identity') }}</legend>
          <div class="lf-field">
            <span class="lf-field-label">{{ t('librefinance', 'Identification color') }}</span>
            <NcColorPicker v-model="companyProfile.brandColor">
              <NcButton variant="secondary" :aria-label="t('librefinance', 'Choose organization color')">
                <span class="lf-category-color-swatch" :style="{backgroundColor: companyProfile.brandColor}" aria-hidden="true" />
                {{ t('librefinance', 'Choose color') }}
              </NcButton>
            </NcColorPicker>
          </div>
          <div class="lf-company-logo-settings">
            <label class="lf-field-label" for="lf-company-logo-file">{{ t('librefinance', 'Company logo') }}</label>
            <template v-if="companyEditId">
              <img v-if="logoVersion" class="lf-company-preview-logo"
                :src="logoUrl" :alt="t('librefinance', 'Current company logo')" />
              <input id="lf-company-logo-file" type="file" accept="image/png,image/jpeg,image/webp"
                :disabled="logoBusy" :aria-label="t('librefinance', 'Select company logo')"
                @change="updateLogo" />
              <small class="lf-muted">{{ t('librefinance', 'PNG, JPG or WebP, up to 512 KB. Stored privately, without automatic publication.') }}</small>
              <NcButton v-if="logoVersion" variant="tertiary" :disabled="logoBusy"
                @click.prevent="removeLogo">{{ t('librefinance', 'Remove logo') }}</NcButton>
            </template>
            <p v-else class="lf-muted">{{ t('librefinance', 'Save the company to add its logo.') }}</p>
            <NcNoteCard v-if="logoError" type="error" role="alert">{{ logoError }}</NcNoteCard>
          </div>
        </fieldset>

        <fieldset class="lf-company-form-section">
          <legend>{{ t('librefinance', 'Specific additional data') }}</legend>
          <p class="lf-muted">{{ t('librefinance', 'Custom fields defined in Manage → Custom fields for the Company entity appear below. Each company has its own values.') }}</p>
          <CustomFieldsPanel :key="companyEditId || 'new'" v-model="companyCustomValues"
            entity-kind="company" />
        </fieldset>
        <NcCheckboxRadioSwitch v-if="companyEditId" v-model="companyActive" type="checkbox">
          {{ t('librefinance', 'Active registration') }}
        </NcCheckboxRadioSwitch>
        <div class="lf-form-actions">
          <NcButton variant="tertiary" @click="closeCompany">{{ t('librefinance', 'Cancel') }}</NcButton>
          <NcButton type="submit" variant="primary" :disabled="busy">{{ t('librefinance', 'Save company') }}</NcButton>
        </div>
      </form>
    </NcDialog>
  </div>
</template>

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
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { mdiPlus, mdiPencilOutline } from '@mdi/js'
import LfSelect from '../components/LfSelect.vue'
import LfPageHeader from '../components/LfPageHeader.vue'
import LfSortableHeader from '../components/LfSortableHeader.vue'
import { useLoadedSort } from '../composables/useLoadedSort.js'
import CustomFieldsPanel from '../components/CustomFieldsPanel.vue'
import ContactProfileFields from '../components/ContactProfileFields.vue'
import FieldStudioShortcut from '../components/FieldStudioShortcut.vue'
import { financeApi, financeError } from '../services/api.js'

const props = defineProps({ section: { type: String, default: 'contacts' } })
const w = inject('lfWorkspace')
const { activeId, manager, busy, contacts, items, contactNextAfterId,
  itemNextAfterId, money, refreshCurrent, loadMoreCatalog } = w
const active = computed(() => props.section === 'items' ? 'items' : 'contacts')
const personal = computed(() => w.space.value?.kind === 'personal')
const pageTitle = computed(() => active.value === 'items' ? t('librefinance', 'Products and services')
  : personal.value ? t('librefinance', 'People and businesses') : t('librefinance', 'Customers and suppliers'))
const editId = ref(null)
const editingItem = ref(null)
const dialog = ref('')
const viewing = ref(false)
let dialogGeneration = 0
const emptyAddress = () => ({ line1:'', line2:'', city:'', region:'', postalCode:'', country:'' })
const profileBaseline = ref(null)
const editProfile = (profile = {}) => ({
  contactPerson:profile.contactPerson ?? '', phone:profile.phone ?? '', website:profile.website ?? '',
  billingAddress:{...emptyAddress(), ...profile.billingAddress},
  shippingAddress:{...emptyAddress(), ...profile.shippingAddress},
})
// For an existing contact only submit changed profile fields; the API
// preserves omitted fields even when another caller updates a different one.
function changedProfile() {
  const current = form.value.profile
  if (!profileBaseline.value) return current
  const patch = {}
  for (const key of ['contactPerson', 'phone', 'website']) {
    if ((current[key] ?? '') !== (profileBaseline.value[key] ?? '')) patch[key] = current[key] || null
  }
  for (const key of ['billingAddress', 'shippingAddress']) {
    const changes = {}
    for (const field of Object.keys(emptyAddress())) {
      if ((current[key]?.[field] ?? '') !== (profileBaseline.value[key]?.[field] ?? '')) {
        changes[field] = current[key]?.[field] || null
      }
    }
    if (Object.keys(changes).length) patch[key] = changes
  }
  return patch
}
const working = ref(false)
const problem = ref('')
const form = ref({
  name:'', kind:'customer', email:'', taxId:'', active:true,
  sku:'', description:'', price:'', customFields:{}, profile:editProfile(),
})
// Existing customer/vendor/both enum is unchanged; only the labels change.
const contactKinds = computed(() => personal.value ? [
  {value:'customer',label:t('librefinance', 'People I receive money from')},
  {value:'vendor',label:t('librefinance', 'People I pay')},
  {value:'both',label:t('librefinance', 'Pay and receive')},
] : [
  {value:'customer',label:t('librefinance', 'Customer')},
  {value:'vendor',label:t('librefinance', 'Supplier')},
  {value:'both',label:t('librefinance', 'Customer and supplier')},
])
const itemKinds = [{value:'service',label:t('librefinance', 'Service')},{value:'product',label:t('librefinance', 'Product')}]
const search = ref(new URLSearchParams(window.location.search).get('search') || '')
const searchTerm = ref(search.value.trim())
const searchResults = ref([])
const searchNext = ref(null)
const searchLoading = ref(false)
const searchProblem = ref('')
let searchSequence = 0
let searchTimer = null
const localSort = useLoadedSort('')
const shownContacts = computed(() => localSort.order(searchTerm.value ? (active.value === 'contacts' ? searchResults.value : []) : contacts.value))
const shownItems = computed(() => localSort.order(searchTerm.value ? (active.value === 'items' ? searchResults.value : []) : items.value))
async function loadSearch(append = false) {
  const term = searchTerm.value.trim().slice(0, 120)
  const token = ++searchSequence
  if (!term || !activeId.value) {
    searchResults.value = []
    searchNext.value = null
    searchProblem.value = ''
    return
  }
  searchLoading.value = true
  searchProblem.value = ''
  try {
    const entity = active.value === 'contacts' ? 'contacts' : 'items'
    const afterId = append ? searchNext.value : null
    const url = '/' + entity + '?search=' + encodeURIComponent(term)
      + (afterId ? '&afterId=' + Number(afterId) : '')
    const result = await financeApi('librefinance', Number(activeId.value), url)
    if (token !== searchSequence) return
    searchResults.value = append ? [...searchResults.value, ...result[entity]] : result[entity]
    searchNext.value = result.nextAfterId
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
    if (searchTerm.value) url.searchParams.set('search', searchTerm.value)
    else url.searchParams.delete('search')
    window.history.replaceState(null,'',url.pathname+url.search)
  }, 280)
}
watch([searchTerm, activeId, active], () => loadSearch())
onMounted(() => { if (searchTerm.value) loadSearch() })
onBeforeUnmount(() => { ++searchSequence; if (searchTimer) clearTimeout(searchTimer) })
function newRecord() {
  dialogGeneration++
  viewing.value = false
  profileBaseline.value = null
  editId.value = null
  editingItem.value = null
  form.value = { name:'',kind:active.value==='contacts'?'customer':'service',
    email:'',taxId:'',active:true,sku:'',description:'',price:'',customFields:{},profile:editProfile() }
  problem.value = ''
  dialog.value = active.value
}
function editContact(row) {
  dialogGeneration++
  viewing.value = !manager.value
  profileBaseline.value = editProfile(row.profile ?? {})
  editId.value = row.id
  editingItem.value = null
  form.value = { ...form.value,name:row.name,kind:row.kind,
    email:row.email||'',taxId:row.tax_id||'',active:row.active,
    profile:editProfile(row.profile ?? {}),customFields:{...row.custom_fields} }
  problem.value = ''
  dialog.value = 'contacts'
}
function editItem(row) {
  dialogGeneration++
  viewing.value = false
  editId.value = row.id
  editingItem.value = row
  form.value = { ...form.value,name:row.name,kind:row.kind,sku:row.sku,
    description:row.description || '',price:w.amountInput(row.unit_price_minor, row),active:row.active,
    customFields:{...row.custom_fields} }
  problem.value = ''
  dialog.value = 'items'
}
function closeDialog() {
  dialogGeneration++
  dialog.value = ''
  working.value = false
  problem.value = ''
}
watch([activeId, active], closeDialog, { flush: 'sync' })
watch(manager, allowed => { if (!allowed && dialog.value && !viewing.value) closeDialog() }, { flush: 'sync' })
onBeforeUnmount(() => { ++dialogGeneration })
async function save() {
  if (viewing.value || !manager.value || working.value || !form.value.name.trim()) return
  const token = ++dialogGeneration
  const spaceId = Number(activeId.value)
  working.value = true
  problem.value = ''
  try {
    if (dialog.value === 'contacts') {
      const data = {name:form.value.name.trim(),kind:form.value.kind,
        email:form.value.email.trim()||null,taxId:form.value.taxId.trim()||null,
        active:form.value.active,customFields:form.value.customFields,profile:changedProfile()}
      await financeApi('librefinance',spaceId,
        '/contacts' + (editId.value ? '/' + editId.value : ''),
        editId.value ? 'PUT' : 'POST',data)
    } else {
      const data = {sku:form.value.sku.trim(),name:form.value.name.trim(),
        kind:form.value.kind,description:form.value.description.trim()||null,
        unitPriceMinor:w.parseMoney(form.value.price, editingItem.value),active:form.value.active,customFields:form.value.customFields}
      await financeApi('librefinance',spaceId,
        '/items' + (editId.value ? '/' + editId.value : ''),
        editId.value ? 'PUT' : 'POST',data)
    }
    if (token !== dialogGeneration || spaceId !== Number(activeId.value)) return
    await refreshCurrent()
    if (token !== dialogGeneration || spaceId !== Number(activeId.value)) return
    if (searchTerm.value) await loadSearch()
    if (token === dialogGeneration) closeDialog()
  } catch (e) { if (token === dialogGeneration) problem.value = financeError(e) }
  finally { if (token === dialogGeneration) working.value = false }
}
</script>
<template>
  <div class="lf-view">
    <LfPageHeader :title="pageTitle"
      :eyebrow="t('librefinance', 'RECORDS')"
      :description="active === 'contacts'
        ? (personal
          ? t('librefinance', 'Record the people and businesses you receive money from or pay in your personal finances.')
          : t('librefinance', 'Organize your customers, suppliers and their contact details.'))
        : (personal
          ? t('librefinance', 'Optional items for detailing household purchases and services.')
          : t('librefinance', 'Manage the products and services used in sales and purchases.'))">
      <template #actions>
        <FieldStudioShortcut :entity-kind="active==='contacts'?'contact':'item'" />
        <NcButton v-if="manager" variant="primary" @click="newRecord">
          <template #icon><NcIconSvgWrapper :path="mdiPlus" :size="20" /></template>
          {{ active === 'contacts' ? t('librefinance', 'New contact') : t('librefinance', 'New product or service') }}
        </NcButton>
      </template>
    </LfPageHeader>
    <section class="lf-surface">
      <div class="lf-section-heading">
        <h2>{{ active === 'contacts'
          ? (personal ? t('librefinance', 'Personal contacts') : t('librefinance', 'People and organizations'))
          : t('librefinance', 'Item catalog') }}</h2>
        <NcTextField v-model="search"
          :label="active==='contacts'?t('librefinance', 'Search contacts'):t('librefinance', 'Search products and services')"
          type="search" :placeholder="t('librefinance', 'Search all records')" class="lf-search-field"
          maxlength="120" @update:model-value="scheduleSearch" />
      </div>
      <NcNoteCard v-if="searchProblem" type="error">{{searchProblem}}</NcNoteCard>
      <p v-if="searchLoading" class="lf-muted" role="status">{{ t('librefinance', 'Searching all records…') }}</p>
      <div v-if="active==='contacts' ? shownContacts.length : shownItems.length" class="lf-table-scroll">
        <table class="lf-data-table">
          <thead v-if="active==='contacts'"><tr>
            <th v-for="col in [
              {key:'name',label:t('librefinance', 'Contact')}, {key:'kind',label:t('librefinance', 'Type')},
              {key:'email',label:t('librefinance', 'Email')}, {key:'tax_id',label:t('librefinance', 'Identification')},
              {key:'active',label:t('librefinance', 'Status')}]" :key="col.key"
              :aria-sort="localSort.ariaSort(col.key)">
              <LfSortableHeader :label="col.label" :column="col.key"
                :active-column="localSort.column.value" :direction="localSort.direction.value"
                scope="loaded" @sort="localSort.sort" />
            </th><th>{{ t('librefinance', 'Actions') }}</th>
          </tr></thead>
          <thead v-else><tr>
            <th v-for="col in [
              {key:'sku',label:t('librefinance', 'Code')}, {key:'name',label:t('librefinance', 'Name')},
              {key:'kind',label:t('librefinance', 'Type')}, {key:'unit_price_minor',label:t('librefinance', 'Price')},
              {key:'active',label:t('librefinance', 'Status')}]" :key="col.key"
              :class="{'lf-numeric':col.key==='unit_price_minor'}"
              :aria-sort="localSort.ariaSort(col.key)">
              <LfSortableHeader :label="col.label" :column="col.key"
                :active-column="localSort.column.value" :direction="localSort.direction.value"
                scope="loaded" @sort="localSort.sort" />
            </th><th v-if="manager">{{ t('librefinance', 'Actions') }}</th>
          </tr></thead>
          <tbody v-if="active==='contacts'"><tr v-for="c in shownContacts" :key="c.id">
            <td><strong>{{ c.name }}</strong></td>
            <td>{{ contactKinds.find(k=>k.value===c.kind)?.label || c.kind }}</td>
            <td>{{ c.email||'—' }}</td><td>{{ c.tax_id||'—' }}</td>
            <td>{{ c.active ? t('librefinance', 'Active') : t('librefinance', 'Inactive') }}</td>
            <td><NcButton variant="tertiary"
              :aria-label="manager ? t('librefinance', 'Edit {name}', { name: c.name }, { escape: false, sanitize: false }) : t('librefinance', 'View {name}', { name: c.name }, { escape: false, sanitize: false })"
              @click="editContact(c)">
              <template v-if="manager" #icon><NcIconSvgWrapper :path="mdiPencilOutline" :size="20" /></template>
              <template v-if="!manager">{{ t('librefinance', 'Details') }}</template>
            </NcButton></td>
          </tr></tbody>
          <tbody v-else><tr v-for="i in shownItems" :key="i.id">
            <td class="lf-code">{{ i.sku }}</td><td><strong>{{ i.name }}</strong></td>
            <td>{{ i.kind==='service'?t('librefinance', 'Service'):t('librefinance', 'Product') }}</td>
            <td class="lf-numeric">{{ money(i.unit_price_minor, i) }}</td>
            <td>{{ i.active?t('librefinance', 'Active'):t('librefinance', 'Inactive') }}</td>
            <td v-if="manager"><NcButton variant="tertiary" :aria-label="t('librefinance', 'Edit {name}', { name: i.name }, { escape: false, sanitize: false })"
              @click="editItem(i)">
              <template #icon><NcIconSvgWrapper :path="mdiPencilOutline" :size="20" /></template>
            </NcButton></td>
          </tr></tbody>
        </table>
      </div>
      <NcEmptyContent v-else-if="!searchLoading"
        :name="active==='contacts' ? t('librefinance', 'No contact found') : t('librefinance', 'No product or service found')"
        :description="searchTerm ? t('librefinance', 'Change your search to see other results.') : t('librefinance', 'Records will appear here after they are created.')" />
      <div class="lf-table-footer">
        <NcButton v-if="searchTerm && searchNext" variant="secondary"
          :disabled="searchLoading" @click="loadSearch(true)">{{ t('librefinance', 'More results') }}</NcButton>
        <NcButton v-else-if="!searchTerm && active==='contacts'&&contactNextAfterId"
          variant="secondary" @click="loadMoreCatalog('contacts')">{{ t('librefinance', 'More contacts') }}</NcButton>
        <NcButton v-else-if="!searchTerm && active==='items'&&itemNextAfterId"
          variant="secondary" @click="loadMoreCatalog('items')">{{ t('librefinance', 'More products and services') }}</NcButton>
      </div>
    </section>

    <NcDialog size="normal" content-classes="lf-finance-dialog-content" :open="Boolean(dialog)"
      :name="viewing ? t('librefinance', 'Contact details') : (dialog === 'items' ? (editId ? t('librefinance', 'Edit product or service') : t('librefinance', 'New product or service')) : (editId ? t('librefinance', 'Edit contact') : t('librefinance', 'New contact')))"
      @update:open="!$event && closeDialog()">
      <form class="lf-dialog-form" @submit.prevent="save">
        <NcNoteCard v-if="problem" type="error" role="alert">{{ problem }}</NcNoteCard>
        <NcTextField v-model="form.name" :label="t('librefinance', 'Name')" required maxlength="160" :disabled="viewing" />
        <LfSelect v-if="dialog==='contacts'" id="lf-contact-kind" v-model="form.kind"
          :options="contactKinds" :label="t('librefinance', 'Contact type')" :disabled="viewing" />
        <template v-if="dialog==='contacts'">
          <NcTextField v-model="form.email" :label="t('librefinance', 'Email (optional)')" type="email" maxlength="190" :disabled="viewing" />
          <NcTextField v-model="form.taxId" :label="t('librefinance', 'CPF/CNPJ or identification (optional)')" maxlength="40" :disabled="viewing" />
          <ContactProfileFields :key="activeId + ':' + editId" v-model="form.profile" :read-only="viewing" />
        </template>
        <template v-else>
          <NcTextField v-model="form.sku" :label="t('librefinance', 'Code/SKU')" maxlength="48" required />
          <LfSelect id="lf-item-kind" v-model="form.kind" :options="itemKinds" :label="t('librefinance', 'Type')" />
          <NcTextField v-model="form.price" :label="t('librefinance', 'Unit price')" :placeholder="w.amountInput(0, editingItem)"
            inputmode="decimal" required />
          <NcTextArea v-model="form.description" :label="t('librefinance', 'Additional description')" />
        </template>
        <CustomFieldsPanel v-if="!viewing" :key="activeId + ':' + dialog" v-model="form.customFields"
          :entity-kind="dialog === 'contacts' ? 'contact' : 'item'" />
        <NcCheckboxRadioSwitch v-if="editId" v-model="form.active" type="checkbox" :disabled="viewing">
          {{ t('librefinance', 'Active record') }}
        </NcCheckboxRadioSwitch>
        <div class="lf-form-actions">
          <NcButton variant="tertiary" @click="closeDialog">{{ viewing ? t('librefinance', 'Close') : t('librefinance', 'Cancel') }}</NcButton>
          <NcButton v-if="!viewing" variant="primary" type="submit" :disabled="working">
            {{ t('librefinance', 'Save record') }}
          </NcButton>
        </div>
      </form>
    </NcDialog>
  </div>
</template>

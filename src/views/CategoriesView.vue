<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { computed, inject, ref } from 'vue'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcPopover from '@nextcloud/vue/components/NcPopover'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { mdiPlus, mdiPencilOutline, mdiFilterVariant } from '@mdi/js'
import LfSelect from '../components/LfSelect.vue'
import LfPageHeader from '../components/LfPageHeader.vue'
import LfSortableHeader from '../components/LfSortableHeader.vue'
import { useLoadedSort } from '../composables/useLoadedSort.js'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcColorPicker from '@nextcloud/vue/components/NcColorPicker'
import { financeApi, financeError } from '../services/api.js'
const w = inject('lfWorkspace')
const { manager, busy, categoryRecords, categoryNextAfterId, accounts,
  categoryCode, categoryName, categoryColor, categoryKind, categoryParentId, categoryAccountId,
  createCategory, loadMoreCategories, activeId, refreshCurrent } = w
const dialog = ref(false)
const editDialog = ref(false)
const editWorking = ref(false)
const editProblem = ref('')
const editForm = ref({ id: 0, name: '', color: '#64748B', active: true })
const filter = ref('all')
const localSort = useLoadedSort('code')
const categoryFilters = [
  {value:'all',label:t('librefinance', 'All categories')},
  {value:'income',label:t('librefinance', 'Income')},
  {value:'expense',label:t('librefinance', 'Expenses')},
]
const categories = computed(() => localSort.order(
  filter.value === 'all' ? categoryRecords.value
    : categoryRecords.value.filter(c => c.kind === filter.value),
  {parent: row => categoryRecords.value.find(c => c.id===row.parent_id)?.name || '',
    account: row => accounts.value.find(a => a.id===row.effective_account_id)?.name || ''}))
const parents = computed(() => [{value:0,label:t('librefinance', 'No parent category')},
  ...categoryRecords.value.filter(c => c.kind===categoryKind.value && c.active)
    .map(c=>({value:c.id,label:c.code+' · '+c.name}))])
const accountsForKind = computed(() => [{value:0,label:t('librefinance', 'Inherit from parent category')},
  ...accounts.value.filter(a=>a.active &&
    a.class===(categoryKind.value==='income'?'revenue':'expense'))
    .map(a=>({value:a.id,label:a.code+' · '+a.name}))])
function edit(category) {
  editProblem.value = ''
  editForm.value = { id: category.id, name: category.name,
    color: category.color || '#64748B', active: category.active }
  editDialog.value = true
}
async function saveEdit() {
  if (editWorking.value) return
  editWorking.value = true
  editProblem.value = ''
  try {
    await financeApi('librefinance', Number(activeId.value), '/categories/' + editForm.value.id,
      'PUT', { name: editForm.value.name, color: editForm.value.color,
        active: editForm.value.active })
    await refreshCurrent()
    editDialog.value = false
  } catch (e) { editProblem.value = financeError(e) }
  finally { editWorking.value = false }
}
async function save() {
  if (!categoryCode.value.trim() || !categoryName.value.trim()) return
  await createCategory()
  if (!w.error.value) dialog.value=false
}
</script>
<template>
  <div class="lf-view">
    <LfPageHeader :title="t('librefinance', 'Categories')" :eyebrow="t('librefinance', 'ACCOUNTING')"
      :description="t('librefinance', 'Hierarchical classifications of income and expenses.')">
      <template #actions>
        <NcButton v-if="manager" variant="primary" @click="dialog=true">
          <template #icon><NcIconSvgWrapper :path="mdiPlus" :size="20" /></template>
          {{ t('librefinance', 'New category') }}
        </NcButton>
      </template>
    </LfPageHeader>
    <section class="lf-surface">
      <div class="lf-list-filter-toolbar">
        <NcPopover popup-role="dialog">
          <template #trigger>
            <NcButton id="lf-categories-filter" variant="tertiary" :pressed="filter!=='all'">
              <template #icon><NcIconSvgWrapper :path="mdiFilterVariant" :size="20" /></template>
              {{ t('librefinance', 'Type: {type}', { type: categoryFilters.find(item => item.value === filter)?.label }, { escape: false, sanitize: false }) }}
            </NcButton>
          </template>
          <div class="lf-agenda-filter-content" role="group" :aria-label="t('librefinance', 'Filter categories by type')">
            <NcButton v-for="choice in categoryFilters" :key="choice.value" variant="tertiary"
              :aria-pressed="filter===choice.value" @click="filter=choice.value">{{choice.label}}</NcButton>
          </div>
        </NcPopover>
      </div>
      <div v-if="categories.length" class="lf-table-scroll">
        <table class="lf-data-table">
          <thead><tr>
            <th v-for="col in [
              {key:'code',label:t('librefinance', 'Code')}, {key:'name',label:t('librefinance', 'Category')},
              {key:'kind',label:t('librefinance', 'Type')}, {key:'parent',label:t('librefinance', 'Parent category')},
              {key:'account',label:t('librefinance', 'Linked account')}]" :key="col.key"
              :aria-sort="localSort.ariaSort(col.key)">
              <LfSortableHeader :label="col.label" :column="col.key"
                :active-column="localSort.column.value" :direction="localSort.direction.value"
                scope="loaded" @sort="localSort.sort" />
            </th>
            <th v-if="manager">{{ t('librefinance', 'Actions') }}</th></tr></thead>
          <tbody><tr v-for="cat in categories" :key="cat.id">
            <td class="lf-code">{{cat.code}}</td><td>
              <span class="lf-category-name"><span class="lf-category-dot"
                :style="{ backgroundColor: cat.color || '#64748B' }" aria-hidden="true" />
                <strong>{{cat.name}}</strong></span></td>
            <td>{{cat.kind==='income'?t('librefinance', 'Revenue'):t('librefinance', 'Expense')}}</td>
            <td>{{cat.parent_id ? (categoryRecords.find(c=>c.id===cat.parent_id)?.name||'#'+cat.parent_id) : t('librefinance', 'Root')}}</td>
            <td>{{cat.effective_account_id ?
              (accounts.find(a=>a.id===cat.effective_account_id)?.name||'#'+cat.effective_account_id)
              :t('librefinance', 'Not defined')}}</td>
            <td v-if="manager"><NcButton variant="tertiary" :aria-label="t('librefinance', 'Edit {name}', { name: cat.name }, { escape: false, sanitize: false })"
              @click="edit(cat)">
              <template #icon><NcIconSvgWrapper :path="mdiPencilOutline" :size="20" /></template>
            </NcButton></td>
          </tr></tbody>
        </table>
      </div>
      <NcEmptyContent v-else :name="t('librefinance', 'No categories registered')" />
      <div v-if="categoryNextAfterId" class="lf-table-footer">
        <NcButton @click="loadMoreCategories">{{ t('librefinance', 'More categories') }}</NcButton>
      </div>
    </section>
    <NcDialog size="normal" content-classes="lf-finance-dialog-content" :open="dialog" :name="t('librefinance', 'Register category')" @update:open="dialog=$event">
      <form class="lf-dialog-form" @submit.prevent="save">
        <NcTextField v-model="categoryCode" :label="t('librefinance', 'Code')" maxlength="40"
          :placeholder="t('librefinance', 'E.G. CONSULTING')" required />
        <NcTextField v-model="categoryName" :label="t('librefinance', 'Category name')" maxlength="150" required />
        <LfSelect id="lf-category-kind" v-model="categoryKind" :label="t('librefinance', 'Account nature')"
          :options="[{value:'income',label:t('librefinance', 'Revenue')},{value:'expense',label:t('librefinance', 'Expense')}]" />
        <LfSelect id="lf-category-parent" v-model="categoryParentId" :label="t('librefinance', 'Parent category')"
          :options="parents" />
        <LfSelect id="lf-category-account" v-model="categoryAccountId" :label="t('librefinance', 'Ledger account')"
          :options="accountsForKind" />
        <div class="lf-field"><span class="lf-field-label">{{ t('librefinance', 'Category color') }}</span>
          <NcColorPicker v-model="categoryColor">
            <NcButton variant="secondary" :aria-label="t('librefinance', 'Choose category color')">
              <span class="lf-category-color-swatch" :style="{ backgroundColor: categoryColor }" aria-hidden="true" />
              {{ t('librefinance', 'Select color') }}
            </NcButton>
          </NcColorPicker>
        </div>
        <div class="lf-form-actions">
          <NcButton variant="tertiary" @click="dialog=false">{{ t('librefinance', 'Cancel') }}</NcButton>
          <NcButton variant="primary" type="submit" :disabled="busy">{{ t('librefinance', 'Save category') }}</NcButton>
        </div>
      </form>
    </NcDialog>
    <NcDialog size="normal" content-classes="lf-finance-dialog-content" :open="editDialog"
      :name="t('librefinance', 'Edit category')" @update:open="editDialog=$event">
      <form class="lf-dialog-form" @submit.prevent="saveEdit">
        <NcNoteCard v-if="editProblem" type="error" role="alert">{{ editProblem }}</NcNoteCard>
        <NcTextField v-model="editForm.name" :label="t('librefinance', 'Category name')" required maxlength="150" />
        <div class="lf-field"><span class="lf-field-label">{{ t('librefinance', 'Category color') }}</span>
          <NcColorPicker v-model="editForm.color">
            <NcButton variant="secondary" :aria-label="t('librefinance', 'Choose new category color')">
              <span class="lf-category-color-swatch" :style="{ backgroundColor: editForm.color }" aria-hidden="true" />
              {{ t('librefinance', 'Select color') }}
            </NcButton>
          </NcColorPicker>
        </div>
        <NcCheckboxRadioSwitch v-model="editForm.active" type="checkbox">{{ t('librefinance', 'Active category') }}</NcCheckboxRadioSwitch>
        <NcNoteCard type="info">{{ t('librefinance', 'The code, accounting nature and hierarchy remain unchanged to protect previous entries.') }}</NcNoteCard>
        <div class="lf-form-actions"><NcButton variant="tertiary" @click="editDialog=false">{{ t('librefinance', 'Cancel') }}</NcButton>
          <NcButton variant="primary" type="submit" :disabled="editWorking">{{ t('librefinance', 'Save changes') }}</NcButton></div>
      </form>
    </NcDialog>
  </div>
</template>

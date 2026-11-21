<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { computed, inject, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { mdiPlus, mdiPencilOutline } from '@mdi/js'
import LfSelect from '../components/LfSelect.vue'
import LfPageHeader from '../components/LfPageHeader.vue'
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
const categories = computed(() => filter.value === 'all' ? categoryRecords.value
  : categoryRecords.value.filter(c => c.kind === filter.value))
const parents = computed(() => [{value:0,label:'Sem categoria superior'},
  ...categoryRecords.value.filter(c => c.kind===categoryKind.value && c.active)
    .map(c=>({value:c.id,label:c.code+' · '+c.name}))])
const accountsForKind = computed(() => [{value:0,label:'Herdar da categoria superior'},
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
    <LfPageHeader title="Categorias" eyebrow="CONTABILIDADE"
      description="Classificações de receitas e despesas com estrutura hierárquica.">
      <template #actions>
        <NcButton v-if="manager" variant="primary" @click="dialog=true">
          <template #icon><NcIconSvgWrapper :path="mdiPlus" :size="20" /></template>
          Nova categoria
        </NcButton>
      </template>
    </LfPageHeader>
    <section class="lf-surface">
      <div class="lf-filter-row" aria-label="Filtrar categorias">
        <NcButton v-for="tab in [{id:'all',name:'Todas'},{id:'income',name:'Receitas'},{id:'expense',name:'Despesas'}]"
          :key="tab.id" :variant="filter===tab.id?'primary':'tertiary'"
          :pressed="filter===tab.id" @click="filter=tab.id">{{tab.name}}</NcButton>
      </div>
      <div class="lf-table-scroll">
        <table class="lf-data-table">
          <thead><tr><th>Código</th><th>Categoria</th><th>Tipo</th>
            <th>Categoria superior</th><th>Conta vinculada</th><th v-if="manager">Ações</th></tr></thead>
          <tbody><tr v-for="cat in categories" :key="cat.id">
            <td class="lf-code">{{cat.code}}</td><td>
              <span class="lf-category-name"><span class="lf-category-dot"
                :style="{ backgroundColor: cat.color || '#64748B' }" aria-hidden="true" />
                <strong>{{cat.name}}</strong></span></td>
            <td>{{cat.kind==='income'?'Receita':'Despesa'}}</td>
            <td>{{cat.parent_id ? (categoryRecords.find(c=>c.id===cat.parent_id)?.name||'#'+cat.parent_id) : 'Raiz'}}</td>
            <td>{{cat.effective_account_id ?
              (accounts.find(a=>a.id===cat.effective_account_id)?.name||'#'+cat.effective_account_id)
              :'Não definida'}}</td>
            <td v-if="manager"><NcButton variant="tertiary" :aria-label="'Editar ' + cat.name"
              @click="edit(cat)">
              <template #icon><NcIconSvgWrapper :path="mdiPencilOutline" :size="20" /></template>
            </NcButton></td>
          </tr></tbody>
        </table>
        <NcEmptyContent v-if="!categories.length" name="Nenhuma categoria cadastrada" />
      </div>
      <div v-if="categoryNextAfterId" class="lf-table-footer">
        <NcButton @click="loadMoreCategories">Mais categorias</NcButton>
      </div>
    </section>
    <NcDialog size="normal" content-classes="lf-finance-dialog-content" :open="dialog" name="Cadastrar categoria" @update:open="dialog=$event">
      <form class="lf-dialog-form" @submit.prevent="save">
        <NcTextField v-model="categoryCode" label="Código" maxlength="40"
          placeholder="EX: CONSULTORIA" required />
        <NcTextField v-model="categoryName" label="Nome da categoria" maxlength="150" required />
        <LfSelect id="lf-category-kind" v-model="categoryKind" label="Natureza"
          :options="[{value:'income',label:'Receita'},{value:'expense',label:'Despesa'}]" />
        <LfSelect id="lf-category-parent" v-model="categoryParentId" label="Categoria superior"
          :options="parents" />
        <LfSelect id="lf-category-account" v-model="categoryAccountId" label="Conta contábil"
          :options="accountsForKind" />
        <div class="lf-field"><span class="lf-field-label">Cor da categoria</span>
          <NcColorPicker v-model="categoryColor">
            <NcButton variant="secondary" aria-label="Escolher cor da categoria">
              <span class="lf-category-color-swatch" :style="{ backgroundColor: categoryColor }" aria-hidden="true" />
              Selecionar cor
            </NcButton>
          </NcColorPicker>
        </div>
        <div class="lf-form-actions">
          <NcButton variant="tertiary" @click="dialog=false">Cancelar</NcButton>
          <NcButton variant="primary" type="submit" :disabled="busy">Salvar categoria</NcButton>
        </div>
      </form>
    </NcDialog>
    <NcDialog size="normal" content-classes="lf-finance-dialog-content" :open="editDialog"
      name="Editar categoria" @update:open="editDialog=$event">
      <form class="lf-dialog-form" @submit.prevent="saveEdit">
        <NcNoteCard v-if="editProblem" type="error" role="alert">{{ editProblem }}</NcNoteCard>
        <NcTextField v-model="editForm.name" label="Nome da categoria" required maxlength="150" />
        <div class="lf-field"><span class="lf-field-label">Cor da categoria</span>
          <NcColorPicker v-model="editForm.color">
            <NcButton variant="secondary" aria-label="Escolher nova cor da categoria">
              <span class="lf-category-color-swatch" :style="{ backgroundColor: editForm.color }" aria-hidden="true" />
              Selecionar cor
            </NcButton>
          </NcColorPicker>
        </div>
        <NcCheckboxRadioSwitch v-model="editForm.active" type="checkbox">Categoria ativa</NcCheckboxRadioSwitch>
        <NcNoteCard type="info">O código, a natureza contábil e a hierarquia permanecem
          inalterados para proteger lançamentos anteriores.</NcNoteCard>
        <div class="lf-form-actions"><NcButton variant="tertiary" @click="editDialog=false">Cancelar</NcButton>
          <NcButton variant="primary" type="submit" :disabled="editWorking">Salvar alterações</NcButton></div>
      </form>
    </NcDialog>
  </div>
</template>

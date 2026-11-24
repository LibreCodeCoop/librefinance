<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { computed, inject, onBeforeUnmount, ref, watch } from 'vue'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import LfSelect from './LfSelect.vue'
import { financeApi, financeError } from '../services/api.js'

const w = inject('lfWorkspace')
const types = [
  {value:'text',label:t('librefinance', 'Short text')}, {value:'textarea',label:t('librefinance', 'Long text')},
  {value:'email',label:t('librefinance', 'Email')}, {value:'url',label:t('librefinance', 'Web address')},
  {value:'integer',label:t('librefinance', 'Integer')}, {value:'number',label:t('librefinance', 'Exact decimal')},
  {value:'date',label:t('librefinance', 'Date')}, {value:'boolean',label:t('librefinance', 'Yes / No')},
  {value:'select',label:t('librefinance', 'List of options')},
]
const entities = computed(() => [
  {value:'company',label:w.space.value?.kind==='personal'?t('librefinance', 'Organization (optional)'):t('librefinance', 'Business / organization'),
    description:t('librefinance', 'Organization details, address and administrative information')},
  {value:'contact',label:w.space.value?.kind==='personal'?t('librefinance', 'People and establishments'):t('librefinance', 'Customers and suppliers'),
    description:t('librefinance', 'Additional information about registered contacts')},
  {value:'item',label:t('librefinance', 'Products and services'),description:t('librefinance', 'Item catalog information')},
  {value:'invoice',label:t('librefinance', 'Sales invoices'),description:t('librefinance', 'Information associated with each detailed sale')},
  {value:'bill',label:t('librefinance', 'Purchase invoices'),description:t('librefinance', 'Information associated with each detailed purchase')},
  {value:'journal',label:t('librefinance', 'Recorded movements'),
    description:t('librefinance', 'Inflows and outflows already recorded in the financial ledger')},
])
const fields = ref([])
const loading = ref(false)
const saving = ref(false)
const problem = ref('')
const dialog = ref(false)
const step = ref('choose')
const draft = ref({})
const totals = computed(() => entities.value.map(entry => ({
  ...entry,
  fields:fields.value.filter(field => field.entityKind === entry.value),
})))
let generation = 0
async function load() {
  const token = ++generation
  if (!w.activeId.value) return
  const spaceId=Number(w.activeId.value)
  loading.value = true
  problem.value = ''
  try {
    const results = await Promise.all(entities.value.map(entry =>
      financeApi('librefinance', spaceId, '/custom-fields/' + entry.value)))
    if (token !== generation || spaceId !== Number(w.activeId.value)) return
    fields.value = results.flatMap(response => response.fields)
  } catch (e) {
    if (token === generation) {
      problem.value = financeError(e)
      fields.value = []
    }
  } finally {
    if (token === generation) loading.value = false
  }
}
watch(() => w.activeId.value, () => {
  dialog.value=false
  load()
}, {immediate:true})
onBeforeUnmount(() => { generation++ })
function newDraft() {
  return {id:0,entityKind:'',key:'',label:'',type:'text',
    required:false,visible:true,apiVisible:true,active:true,
    help:'',sortOrder:0,columnSpan:12,options:''}
}
function begin(field = null) {
  problem.value = ''
  draft.value = field ? {
    id:field.id,entityKind:field.entityKind,key:field.key,label:field.label,type:field.type,
    required:field.required,visible:field.visible,apiVisible:field.apiVisible,
    active:field.active,help:field.help||'',sortOrder:field.sortOrder,
    columnSpan:field.columnSpan,options:(field.options||[]).join('\n'),
  } : newDraft()
  if (!draft.value.visible || !draft.value.apiVisible) draft.value.required=false
  step.value=field?'details':'choose'
  dialog.value=true
}
function chooseEntity(entityKind) {
  const group=totals.value.find(entry => entry.value===entityKind)
  if (!group || group.fields.length>=30) return
  draft.value.entityKind=entityKind
  draft.value.sortOrder=group.fields.length*10
  step.value='details'
}
const currentEntity = computed(() =>
  entities.value.find(entry => entry.value===draft.value.entityKind))
function formVisibilityChanged(value) {
  if (!value) {
    // Conservative privacy default: hiding a field should not accidentally
    // make the historical private data available to API consumers.
    // Re-enabling API access remains an explicit independent choice.
    draft.value.required=false
    draft.value.apiVisible=false
  }
}
function autoKey(label) {
  return String(label).normalize('NFD').replace(/[\u0300-\u036f]/g,'')
    .toLowerCase().replace(/[^a-z0-9]+/g,'_').replace(/^_+|_+$/g,'')
    .replace(/^[^a-z]+/,'').slice(0,40)
}
async function save() {
  if (saving.value || !draft.value.entityKind) return
  problem.value=''
  saving.value=true
  const spaceId=Number(w.activeId.value)
  try {
    const f=draft.value
    const payload={
      label:f.label.trim(),required:Boolean(f.visible && f.apiVisible && f.required),
      visible:Boolean(f.visible),apiVisible:Boolean(f.apiVisible),
      active:Boolean(f.active),help:f.help,sortOrder:Number(f.sortOrder)||0,
      columnSpan:Number(f.columnSpan),
    }
    if(!f.id)Object.assign(payload,{
      key:f.key.trim() || autoKey(f.label),type:f.type,
      options:f.type==='select'
        ? f.options.split('\n').map(value=>value.trim()).filter(Boolean) : [],
    })
    await financeApi('librefinance',spaceId,
      '/custom-fields/'+f.entityKind+(f.id?'/'+f.id:''),
      f.id?'PUT':'POST',payload)
    if (spaceId !== Number(w.activeId.value)) return
    dialog.value=false
    await load()
  } catch (e) {
    if(spaceId===Number(w.activeId.value))problem.value=financeError(e)
  } finally {
    saving.value=false
  }
}
</script>

<template>
  <div class="lf-field-studio">
    <div class="lf-section-heading">
      <div>
        <p>{{ t('librefinance', 'Additional information for the current workspace, without changing amounts, balances or calculations.') }}</p>
      </div>
      <NcButton variant="primary" :disabled="loading||saving||totals.every(group=>group.fields.length>=30)"
        @click="begin()">{{ t('librefinance', 'New field') }}</NcButton>
    </div>
    <NcNoteCard v-if="problem&&!dialog" type="error" role="alert">{{problem}}</NcNoteCard>
    <p v-if="loading" role="status" class="lf-muted">{{ t('librefinance', 'Loading fields…') }}</p>
    <template v-else-if="fields.length">
      <section v-for="group in totals.filter(group=>group.fields.length)" :key="group.value"
        class="lf-custom-field-group">
        <h3>{{group.label}} <small class="lf-muted">({{group.fields.length}}/30)</small></h3>
        <div v-for="field in group.fields" :key="field.id" class="lf-custom-field-row">
          <div>
            <strong>{{field.label}}</strong>
            <small>{{types.find(type=>type.value===field.type)?.label}}
              · {{field.visible?t('librefinance', 'On the form'):t('librefinance', 'Internal')}}
              · {{field.apiVisible?t('librefinance', 'Common API'):t('librefinance', 'API restricted to managers')}}
              · {{field.active?t('librefinance', 'Active'):t('librefinance', 'Inactive')}}</small>
            <small v-if="field.help">{{field.help}}</small>
          </div>
          <NcButton variant="tertiary" :aria-label="t('librefinance', 'Edit {name}', { name: field.label }, { escape: false, sanitize: false })"
            @click="begin(field)">{{ t('librefinance', 'Edit') }}</NcButton>
        </div>
      </section>
    </template>
    <NcEmptyContent v-else-if="!problem" :name="t('librefinance', 'No custom fields')"
      :description="t('librefinance', 'When creating a field, first choose where it will be used.')" />
    <NcDialog :open="dialog" size="normal" content-classes="lf-finance-dialog-content"
      :name="draft.id?t('librefinance', 'Edit custom field'):t('librefinance', 'New custom field')"
      @update:open="!$event&&!saving&&(dialog=false)">
      <div v-if="step==='choose'" class="lf-dialog-form">
        <h3>{{ t('librefinance', 'Where will this field be used?') }}</h3>
        <div class="lf-field-targets" role="group" :aria-label="t('librefinance', 'Choose field target')">
          <NcButton v-for="group in totals" :key="group.value" variant="secondary"
            :disabled="group.fields.length>=30" @click="chooseEntity(group.value)">
            {{group.label}}
          </NcButton>
        </div>
        <p class="lf-muted">{{ t('librefinance', 'Each field belongs to the selected area within this financial workspace. Register its values when creating or editing records in that area.') }}</p>
        <div class="lf-form-actions">
          <NcButton variant="tertiary" @click="dialog=false">{{ t('librefinance', 'Cancel') }}</NcButton>
        </div>
      </div>
      <form v-else class="lf-dialog-form" @submit.prevent="save">
        <NcButton v-if="!draft.id" variant="tertiary" @click="step='choose'">
          {{ t('librefinance', '{name} · Change target', { name: currentEntity?.label }, { escape: false, sanitize: false }) }}
        </NcButton>
        <p v-else class="lf-muted">{{ t('librefinance', 'Used in: {name}', { name: currentEntity?.label }, { escape: false, sanitize: false }) }}</p>
        <NcNoteCard v-if="problem" type="error" role="alert">{{problem}}</NcNoteCard>
        <NcTextField v-model="draft.label" :label="t('librefinance', 'Field name')" maxlength="100" required />
        <details class="lf-finance-explanation">
          <summary>{{ t('librefinance', 'Technical identifier') }}</summary>
          <NcTextField v-model="draft.key" :label="t('librefinance', 'Integration key')"
            :disabled="Boolean(draft.id)" :placeholder="t('librefinance', 'Automatically generated from the name')"
            maxlength="40" />
        </details>
        <LfSelect id="lf-field-studio-type" v-model="draft.type" :label="t('librefinance', 'Information type')"
          :disabled="Boolean(draft.id)" :options="types" />
        <NcTextArea v-if="draft.type==='select'" v-model="draft.options"
          :label="t('librefinance', 'Options (one per line)')" :disabled="Boolean(draft.id)" />
        <NcTextField v-model="draft.help" :label="t('librefinance', 'Help for the person filling in the field (optional)')"
          maxlength="255" />
        <div class="lf-form-grid">
          <LfSelect id="lf-field-studio-width" v-model="draft.columnSpan"
            :label="t('librefinance', 'Width on the form')"
            :options="[{value:12,label:t('librefinance', 'Full row')},{value:6,label:t('librefinance', 'Half row')}]" />
          <NcTextField v-model="draft.sortOrder" :label="t('librefinance', 'Position on the screen')" inputmode="numeric" />
        </div>
        <NcCheckboxRadioSwitch v-model="draft.visible" type="checkbox"
          @update:model-value="formVisibilityChanged">
          {{ t('librefinance', 'Show on the form') }}
        </NcCheckboxRadioSwitch>
        <NcCheckboxRadioSwitch v-model="draft.apiVisible" type="checkbox"
          @update:model-value="!$event&&(draft.required=false)">
          {{ t('librefinance', 'Available in the common API') }}
        </NcCheckboxRadioSwitch>
        <NcNoteCard v-if="!draft.visible && !draft.apiVisible" type="info">
          {{ t('librefinance', 'This field will be internal data. Only managers can view and fill it in the {name} forms. No spreadsheet import is needed to register its value.', { name: currentEntity?.label }, { escape: false, sanitize: false }) }}
        </NcNoteCard>
        <NcNoteCard v-else-if="!draft.visible && draft.apiVisible" type="warning">
          {{ t('librefinance', 'Hidden on the form, but available through the API to authorized users of this workspace. Disable API availability if the data should be internal.') }}
        </NcNoteCard>
        <NcNoteCard v-if="!draft.apiVisible" type="info">
          {{ t('librefinance', 'The value will not be returned to viewers or operators through the common API. Managers retain administrative access to view and edit the data.') }}
        </NcNoteCard>
        <NcCheckboxRadioSwitch v-model="draft.required" type="checkbox"
          :disabled="!draft.visible||!draft.apiVisible">
          {{ t('librefinance', 'Required when saving') }}
        </NcCheckboxRadioSwitch>
        <NcCheckboxRadioSwitch v-if="draft.id" v-model="draft.active" type="checkbox">
          {{ t('librefinance', 'Active field (deactivation preserves existing values)') }}
        </NcCheckboxRadioSwitch>
        <p v-if="draft.id" class="lf-muted">{{ t('librefinance', 'Type and options are preserved so existing values retain their meaning.') }}</p>
        <div class="lf-form-actions">
          <NcButton variant="tertiary" :disabled="saving" @click="dialog=false">
            {{ t('librefinance', 'Cancel') }}
          </NcButton>
          <NcButton variant="primary" type="submit" :disabled="saving">
            {{saving?t('librefinance', 'Saving…'):t('librefinance', 'Save field')}}
          </NcButton>
        </div>
      </form>
    </NcDialog>
  </div>
</template>

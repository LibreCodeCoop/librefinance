<!-- SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later -->
<script setup>
import { inject, onMounted, ref, watch } from 'vue'
import { t } from '@nextcloud/l10n'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import LfDateField from './LfDateField.vue'
import LfSelect from './LfSelect.vue'
import { financeApi, financeError } from '../services/api.js'
const props = defineProps({
  entityKind: { type: String, required: true },
  modelValue: { type: Object, default: () => ({}) },
})
const emit = defineEmits(['update:modelValue'])
const w = inject('lfWorkspace')
const fields = ref([])
const error = ref('')
let generation = 0
async function load() {
  const token = ++generation
  fields.value = []
  error.value = ''
  try {
    const response = await financeApi('librefinance', Number(w.activeId.value),
      '/custom-fields/' + encodeURIComponent(props.entityKind))
    if (token !== generation) return
    // Field values are loaded through the role-scoped entity API. Managers
    // also edit internal metadata; members never receive fields not allowed
    // to them by the service.
    fields.value = response.fields.filter(field => field.active
      && (w.manager.value || (field.visible && field.apiVisible)))
  } catch (e) { if (token === generation) error.value = financeError(e) }
}
function setValue(id, value) {
  emit('update:modelValue', { ...props.modelValue, [String(id)]: value })
}
watch([() => w.activeId.value, () => props.entityKind], load)
onMounted(load)
</script>
<template>
  <div v-if="fields.length || error" class="lf-custom-fields-panel">
    <h3>{{ t('librefinance', 'Additional information') }}</h3>
    <p class="lf-muted">{{ t('librefinance', 'Fields defined for this financial workspace. They do not change accounting calculations.') }}</p>
    <NcNoteCard v-if="error" type="error" role="alert">{{ error }}</NcNoteCard>
    <div class="lf-custom-fields-grid">
      <div v-for="field in fields" :key="field.id" class="lf-field"
        :class="{ 'lf-custom-wide': field.columnSpan === 12 }">
        <NcCheckboxRadioSwitch v-if="field.type === 'boolean'"
          :model-value="modelValue[String(field.id)] ?? false" type="checkbox"
          @update:model-value="setValue(field.id, $event)">
          {{ field.label }}
        </NcCheckboxRadioSwitch>
        <LfSelect v-else-if="field.type === 'select'" :id="'lf-extra-' + field.id"
          :label="field.label" :model-value="modelValue[String(field.id)] ?? ''"
          :options="field.options.map(value => ({ value, label:value }))"
          @update:model-value="setValue(field.id, $event)" />
        <LfDateField v-else-if="field.type === 'date'" :id="'lf-extra-' + field.id"
          :label="field.label" :model-value="modelValue[String(field.id)] || ''"
          @update:model-value="setValue(field.id, $event)" />
        <NcTextArea v-else-if="field.type === 'textarea'"
          :model-value="modelValue[String(field.id)] ?? ''"
          :label="field.label" maxlength="2000" :required="field.required"
          @update:model-value="setValue(field.id, $event)" />
        <NcTextField v-else :model-value="modelValue[String(field.id)] ?? ''"
          :label="field.label" :required="field.required"
          :type="field.type === 'email' ? 'email' : field.type === 'url' ? 'url' : 'text'"
          :inputmode="['integer', 'number'].includes(field.type) ? 'decimal' : 'text'"
          :maxlength="field.type === 'text' ? 500 : 400"
          @update:model-value="setValue(field.id, $event)" />
        <small v-if="w.manager.value && (!field.visible || !field.apiVisible)"
          class="lf-muted">{{ t('librefinance', 'Internal data: only managers can view and fill in this field.') }}</small>
        <small v-if="field.help" class="lf-muted">{{ field.help }}</small>
        <small v-if="['integer','number'].includes(field.type)" class="lf-muted">{{ t('librefinance', 'Use a period as the decimal separator; values are not rounded.') }}</small>
      </div>
    </div>
  </div>
</template>

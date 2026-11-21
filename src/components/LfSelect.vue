<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { computed } from 'vue'
import NcSelect from '@nextcloud/vue/components/NcSelect'

const props = defineProps({
  modelValue: { type: [String, Number], default: '' },
  options: { type: Array, default: () => [] },
  label: { type: String, required: true },
  id: { type: String, required: true },
  disabled: { type: Boolean, default: false },
  placeholder: { type: String, default: 'Selecione…' },
})
const emit = defineEmits(['update:modelValue'])
const selected = computed(() => props.options.find(option =>
  String(option.value) === String(props.modelValue)) ?? null)
function choose(item) {
  emit('update:modelValue', item ? item.value : '')
}
</script>
<template>
  <div class="lf-field">
    <label class="lf-field-label" :for="id">{{ label }}</label>
    <NcSelect :input-id="id" :model-value="selected" :options="options"
      label="label" :searchable="options.length > 6"
      :clearable="false" :disabled="disabled" :placeholder="placeholder"
      @update:model-value="choose" />
  </div>
</template>

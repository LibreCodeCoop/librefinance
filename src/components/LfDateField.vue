<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { computed } from 'vue'
import NcDateTimePickerNative from '@nextcloud/vue/components/NcDateTimePickerNative'
const props = defineProps({
  modelValue: { type: String, default: '' },
  label: { type: String, required: true },
  id: { type: String, required: true },
  disabled: { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue'])
const value = computed(() => {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(props.modelValue)) return null
  const [year, month, day] = props.modelValue.split('-').map(Number)
  const parsed = new Date(year, month - 1, day, 12)
  return parsed.getFullYear() === year && parsed.getMonth() === month - 1
    && parsed.getDate() === day ? parsed : null
})
function update(date) {
  if (!(date instanceof Date) || Number.isNaN(date.getTime())) {
    emit('update:modelValue', '')
    return
  }
  emit('update:modelValue', date.getFullYear().toString().padStart(4, '0') + '-'
    + (date.getMonth() + 1).toString().padStart(2, '0') + '-'
    + date.getDate().toString().padStart(2, '0'))
}
</script>
<template>
  <NcDateTimePickerNative :id="id" :model-value="value" type="date"
    :label="label" :disabled="disabled" @update:model-value="update" />
</template>

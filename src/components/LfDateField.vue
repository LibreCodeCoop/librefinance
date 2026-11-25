<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { computed } from 'vue'
import NcDateTimePickerNative from '@nextcloud/vue/components/NcDateTimePickerNative'
import { canonicalCalendarDate, parseNativeCalendarDate } from '../date.js'

const props = defineProps({
  modelValue: { type: String, default: '' },
  label: { type: String, required: true },
  id: { type: String, required: true },
  disabled: { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue'])
const value = computed(() => parseNativeCalendarDate(props.modelValue))

// The picker forwards input listeners to the native control. Capture its ISO
// value before the picker turns it into a Date using the local offset.
function update(event) {
  const input = event.currentTarget
  if (!input.value || input.validity.badInput) return

  const date = canonicalCalendarDate(input.value)
  if (!date) {
    input.value = canonicalCalendarDate(props.modelValue)
    return
  }
  emit('update:modelValue', date)
}

// Let the picker distinguish an intentional clear from incomplete keyboard input.
function clear(value) {
  if (value === null) emit('update:modelValue', '')
}
</script>
<template>
  <NcDateTimePickerNative :id="id" :model-value="value" type="date"
    :label="label" :disabled="disabled" @input.capture="update" @update:model-value="clear" />
</template>

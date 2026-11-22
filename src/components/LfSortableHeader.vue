<!-- SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later -->
<script setup>
import { computed } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { mdiArrowDown, mdiArrowUp, mdiUnfoldMoreHorizontal } from '@mdi/js'
const props = defineProps({
  label: { type: String, required: true },
  column: { type: String, required: true },
  activeColumn: { type: String, default: '' },
  direction: { type: String, default: 'asc' },
})
defineEmits(['sort'])
const current = computed(() => props.activeColumn === props.column)
const icon = computed(() => !current.value ? mdiUnfoldMoreHorizontal
  : props.direction === 'asc' ? mdiArrowUp : mdiArrowDown)
</script>
<template>
  <NcButton variant="tertiary" size="small" class="lf-sort-header"
    :aria-label="'Ordenar por ' + label"
    :title="'Ordenar por ' + label"
    @click="$emit('sort', column)">
    <template #icon>
      <NcIconSvgWrapper :path="icon" :size="18" aria-hidden="true" />
    </template>
    {{ label }}
  </NcButton>
</template>

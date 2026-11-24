<!-- SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later -->
<script setup>
import { computed } from 'vue'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { mdiArrowDown, mdiArrowUp, mdiUnfoldMoreHorizontal } from '@mdi/js'
const props = defineProps({
  label: { type: String, required: true },
  column: { type: String, required: true },
  activeColumn: { type: String, default: '' },
  direction: { type: String, default: 'asc' },
  scope: { type: String, default: 'all' },
})
defineEmits(['sort'])
const current = computed(() => props.activeColumn === props.column)
const icon = computed(() => !current.value ? mdiUnfoldMoreHorizontal
  : props.direction === 'asc' ? mdiArrowUp : mdiArrowDown)
const accessibleLabel = computed(() => props.scope === 'loaded'
  ? t('librefinance', 'Sort loaded records by {label}', { label: props.label }, { escape: false, sanitize: false })
  : t('librefinance', 'Sort by {label}', { label: props.label }, { escape: false, sanitize: false }))
</script>
<template>
  <NcButton variant="tertiary" size="small" class="lf-sort-header"
    :aria-label="accessibleLabel"
    :title="accessibleLabel"
    @click="$emit('sort', column)">
    <template #icon>
      <NcIconSvgWrapper :path="icon" :size="18" aria-hidden="true" />
    </template>
    {{ label }}
  </NcButton>
</template>

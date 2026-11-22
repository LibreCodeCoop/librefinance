<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { computed, inject } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { mdiTuneVariant } from '@mdi/js'

const props = defineProps({
  entityKind: {
    type: String,
    required: true,
    validator: value => ['company', 'contact', 'item', 'journal'].includes(value),
  },
})
const workspace = inject('lfWorkspace')
const navigate = inject('lfNavigate', () => {})
const entityNames = {
  company: 'empresas', contact: 'contatos',
  item: 'produtos e serviços', journal: 'lançamentos',
}
const accessibleLabel = computed(() =>
  'Personalizar campos de ' + (entityNames[props.entityKind] || 'cadastros'))
function open() {
  if (!workspace.manager.value || !workspace.activeId.value) return
  navigate('fields', { entityKind: props.entityKind })
}
</script>

<template>
  <NcButton v-if="workspace.manager.value" size="small" variant="tertiary"
    class="lf-field-studio-shortcut"
    :aria-label="accessibleLabel" :title="accessibleLabel" @click="open">
    <template #icon>
      <NcIconSvgWrapper :path="mdiTuneVariant" :size="18" aria-hidden="true" />
    </template>
    Personalizar campos
  </NcButton>
</template>

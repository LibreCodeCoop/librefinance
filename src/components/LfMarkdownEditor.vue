<!-- SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later -->
<script setup>
import { onMounted, onBeforeUnmount, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
const props = defineProps({ modelValue: { type: String, default: '' }, label: { type: String, default: 'Notas detalhadas' } })
const emit = defineEmits(['update:modelValue'])
const host = ref(null)
const source = ref(null)
const rich = ref(false)
let editor = null
let destroyed = false
function setValue(value) { emit('update:modelValue', String(value || '').slice(0, 5000)) }
function addMarkdown(prefix, suffix = prefix) {
  const textarea = source.value
  if (!textarea) return
  const left = textarea.selectionStart ?? props.modelValue.length
  const right = textarea.selectionEnd ?? left
  const text = props.modelValue
  const next = text.slice(0, left) + prefix + text.slice(left, right) + suffix + text.slice(right)
  setValue(next)
}
onMounted(async () => {
  const create = window.OCA?.Text?.createEditor
  if (typeof create !== 'function' || !host.value) return
  try {
    editor = await create({ el: host.value, content: props.modelValue || '',
      onUpdate: ({ markdown }) => { if (!destroyed) setValue(markdown) },
    })
    if (destroyed) { editor?.destroy?.(); editor = null; return }
    rich.value = true
  } catch { rich.value = false }
})
onBeforeUnmount(() => { destroyed = true; editor?.destroy?.(); editor = null })
</script>
<template>
  <div class="lf-markdown-editor lf-form-full">
    <label class="lf-field-label">{{ label }} (Markdown opcional)</label>
    <div ref="host" class="lf-markdown-native" :class="{ 'lf-markdown-native-ready': rich }" />
    <template v-if="!rich">
      <div class="lf-markdown-actions" role="toolbar" aria-label="Formatação Markdown">
        <NcButton size="small" variant="tertiary" @click="addMarkdown('**')">**Negrito**</NcButton>
        <NcButton size="small" variant="tertiary" @click="addMarkdown('_')">*Itálico*</NcButton>
        <NcButton size="small" variant="tertiary" @click="addMarkdown('\n- ', '')">Lista</NcButton>
      </div>
      <textarea ref="source" :value="modelValue" rows="4" maxlength="5000"
        aria-label="Notas em Markdown" placeholder="Ex.: Contrato **123**\n- Referência do pedido"
        @input="setValue($event.target.value)" />
      <small class="lf-muted">Se o aplicativo Nextcloud Text estiver ativo, será usado seu editor rico.
        Caso contrário, as notas são gravadas como Markdown puro, sem executar HTML.</small>
    </template>
    <NcNoteCard type="info">Notas adicionais não alteram o valor, a data nem a classificação do lançamento.</NcNoteCard>
  </div>
</template>

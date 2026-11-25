<!-- SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later -->
<script setup>
import { nextTick, onMounted, onBeforeUnmount, ref } from 'vue'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
const props = defineProps({ modelValue: { type: String, default: '' }, label: { type: String, default: t('librefinance', 'Detailed notes') } })
const emit = defineEmits(['update:modelValue'])
const host = ref(null)
const source = ref(null)
const rich = ref(false)
let editor = null
let destroyed = false
function setValue(value) { emit('update:modelValue', String(value || '').slice(0, 5000)) }
async function addMarkdown(prefix, suffix = prefix) {
  const textarea = source.value
  if (!textarea) return
  const left = textarea.selectionStart ?? props.modelValue.length
  const right = textarea.selectionEnd ?? left
  const text = props.modelValue
  const next = text.slice(0, left) + prefix + text.slice(left, right) + suffix + text.slice(right)
  // A toolbar action must never truncate existing notes to make room for syntax.
  if (next.length > 5000) {
    textarea.focus()
    textarea.setSelectionRange(left, right)
    return
  }
  setValue(next)
  await nextTick()
  if (source.value !== textarea) return
  textarea.focus()
  textarea.setSelectionRange(left + prefix.length, right + prefix.length)
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
    <label class="lf-field-label">{{ t('librefinance', '{label} (optional Markdown)', { label }, { escape: false, sanitize: false }) }}</label>
    <div ref="host" class="lf-markdown-native" :class="{ 'lf-markdown-native-ready': rich }" />
    <template v-if="!rich">
      <div class="lf-markdown-actions" role="toolbar" :aria-label="t('librefinance', 'Markdown formatting')">
        <NcButton size="small" variant="tertiary" @click="addMarkdown('**')">{{ t('librefinance', '**Bold**') }}</NcButton>
        <NcButton size="small" variant="tertiary" @click="addMarkdown('_')">{{ t('librefinance', '*Italic*') }}</NcButton>
        <NcButton size="small" variant="tertiary" @click="addMarkdown('\n- ', '')">{{ t('librefinance', 'List') }}</NcButton>
      </div>
      <textarea ref="source" :value="modelValue" rows="4" maxlength="5000"
        :aria-label="t('librefinance', 'Markdown notes')" :placeholder="t('librefinance', 'E.g. Contract **123** - Order reference')"
        @input="setValue($event.target.value)" />
      <small class="lf-muted">{{ t('librefinance', 'If Nextcloud Text is enabled, its rich editor is used. Otherwise, notes are stored as plain Markdown without executing HTML.') }}</small>
    </template>
    <NcNoteCard type="info">{{ t('librefinance', 'Additional notes do not change the amount, date or classification of the entry.') }}</NcNoteCard>
  </div>
</template>

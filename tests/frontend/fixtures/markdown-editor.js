// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { createApp, h, ref } from 'vue'
import LfMarkdownEditor from '../../../src/components/LfMarkdownEditor.vue'

createApp({
  setup() {
    const model = ref('')
    return () => h('main', [
      h(LfMarkdownEditor, {
        label: 'Journal notes',
        modelValue: model.value,
        'onUpdate:modelValue': value => { model.value = value },
      }),
      h('output', { id: 'markdown-model' }, model.value),
    ])
  },
}).mount('#app')

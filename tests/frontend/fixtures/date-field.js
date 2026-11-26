// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { createApp, h, ref } from 'vue'
import LfDateField from '../../../src/components/LfDateField.vue'

createApp({
  setup() {
    const model = ref('0096-02-29')
    const updates = ref(0)
    const valid = ref(true)
    const update = (value) => {
      model.value = value
      updates.value++
    }

    return () => h('main', [
      h('button', { type: 'button' }, 'Before dates'),
      h(LfDateField, {
        id: 'entry-date', label: 'Entry date', modelValue: model.value,
        'onUpdate:modelValue': update,
        onValidityChange: value => { valid.value = value },
      }),
      h(LfDateField, {
        id: 'saved-date', label: 'Saved date', modelValue: model.value, disabled: true,
      }),
      h('output', { id: 'form-model' }, JSON.stringify([model.value, updates.value])),
      h('output', { id: 'field-validity' }, String(valid.value)),
    ])
  },
}).mount('#app')

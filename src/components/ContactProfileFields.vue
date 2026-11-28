<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { t } from '@nextcloud/l10n'
import NcTextField from '@nextcloud/vue/components/NcTextField'

const props = defineProps({
  modelValue: { type: Object, required: true },
  readOnly: { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue'])
const profileFields = [
  { key: 'contactPerson', label: t('librefinance', 'Contact person'), max: 160 },
  { key: 'phone', label: t('librefinance', 'Phone'), max: 60, type: 'tel' },
  { key: 'website', label: t('librefinance', 'Website'), max: 255, type: 'url' },
]
const addressFields = [
  { key: 'line1', label: t('librefinance', 'Address line 1'), max: 255 },
  { key: 'line2', label: t('librefinance', 'Address line 2'), max: 255 },
  { key: 'city', label: t('librefinance', 'City'), max: 120 },
  { key: 'region', label: t('librefinance', 'State or region'), max: 120 },
  { key: 'postalCode', label: t('librefinance', 'Postal code'), max: 32 },
  { key: 'country', label: t('librefinance', 'Country (ISO 2)'), max: 2 },
]
const addresses = [
  { key: 'billingAddress', label: t('librefinance', 'Billing address') },
  { key: 'shippingAddress', label: t('librefinance', 'Shipping address') },
]
function set(key, value, address = null) {
  if (props.readOnly) return
  const next = { ...props.modelValue }
  if (address) next[address] = { ...next[address], [key]: value }
  else next[key] = value
  emit('update:modelValue', next)
}
function value(key, address = null) {
  return (address ? props.modelValue[address]?.[key] : props.modelValue[key]) ?? ''
}
</script>
<template>
  <section class="lf-contact-profile" :aria-label="t('librefinance', 'Contact profile')">
    <details :open="readOnly">
      <summary>{{ t('librefinance', 'Contact profile') }}</summary>
      <p v-if="!readOnly" class="lf-muted">{{ t('librefinance', 'Optional contact details. These do not define the fiscal identity of an issued document.') }}</p>
      <dl v-if="readOnly" class="lf-profile-values">
        <template v-for="field in profileFields" :key="field.key">
          <dt>{{ field.label }}</dt><dd>{{ value(field.key) || '—' }}</dd>
        </template>
      </dl>
      <div v-else class="lf-profile-fields">
        <NcTextField v-for="field in profileFields" :key="field.key" :model-value="value(field.key)"
          :label="field.label" :maxlength="field.max" :type="field.type || 'text'"
          @update:model-value="set(field.key, $event)" />
      </div>
    </details>
    <details v-for="address in addresses" :key="address.key" :open="readOnly">
      <summary>{{ address.label }}</summary>
      <dl v-if="readOnly" class="lf-profile-values">
        <template v-for="field in addressFields" :key="field.key">
          <dt>{{ field.label }}</dt><dd>{{ value(field.key, address.key) || '—' }}</dd>
        </template>
      </dl>
      <fieldset v-else class="lf-profile-fields" :aria-label="address.label">
        <NcTextField v-for="field in addressFields" :key="field.key"
          :model-value="value(field.key, address.key)" :label="field.label" :maxlength="field.max"
          @update:model-value="set(field.key, $event, address.key)" />
      </fieldset>
    </details>
  </section>
</template>
<style scoped>
.lf-contact-profile { display: grid; gap: 8px; }
.lf-contact-profile details { border: 1px solid var(--color-border); border-radius: var(--border-radius-large); padding: 12px; }
.lf-contact-profile summary { cursor: pointer; font-weight: 600; }
.lf-profile-fields { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(200px, 100%), 1fr)); gap: 8px; margin-top: 12px; border: 0; padding: 0; min-width: 0; }
.lf-profile-values { display: grid; grid-template-columns: minmax(100px, 1fr) minmax(0, 2fr); gap: 8px; margin-top: 12px; }
.lf-profile-values dd { margin: 0; overflow-wrap: anywhere; white-space: pre-wrap; }
</style>

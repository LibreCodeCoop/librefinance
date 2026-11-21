<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
defineProps({
  title: { type: String, required: true },
  description: { type: String, default: '' },
  eyebrow: { type: String, default: '' },
})
</script>
<template>
  <header class="lf-page-header">
    <div>
      <p v-if="eyebrow" class="lf-eyebrow">{{ eyebrow }}</p>
      <h1>{{ title }}</h1>
      <p v-if="description" class="lf-page-description">{{ description }}</p>
    </div>
    <div v-if="$slots.actions" class="lf-header-actions">
      <slot name="actions" />
    </div>
  </header>
</template>

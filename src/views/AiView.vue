<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { inject, onMounted } from 'vue'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import LfDateField from '../components/LfDateField.vue'
import LfPageHeader from '../components/LfPageHeader.vue'
import { formatLedgerDate } from '../date.js'
const w=inject('lfWorkspace')
const {manager, aiCapability,aiPreview,aiTask,aiConsent,aiBusy,aiError,
  aiFrom,aiThrough,openAi,previewAi,requestAi,checkAiStatus,money}=w
const statuses = {
  scheduled: t('librefinance', 'Scheduled'),
  running: t('librefinance', 'Running'),
  successful: t('librefinance', 'Completed'),
  failed: t('librefinance', 'Failed'),
  cancelled: t('librefinance', 'Cancelled'),
  unknown: t('librefinance', 'Unknown status'),
}
onMounted(openAi)
</script>
<template>
  <div class="lf-view">
    <LfPageHeader :title="t('librefinance', 'Financial assistant')" :eyebrow="t('librefinance', 'OPTIONAL ANALYSIS')"
      :description="t('librefinance', 'Analysis of aggregate totals with explicit consent.')" />
    <NcNoteCard type="warning">
      {{ t('librefinance', 'The AI provider configured by the administrator may transmit data to external services. No amounts will be sent without your confirmation. Analysis does not issue invoices or create or change journal entries.') }}
    </NcNoteCard>
    <NcNoteCard v-if="aiError" type="error" role="alert">{{aiError}}</NcNoteCard>
    <NcNoteCard v-if="aiCapability&&!aiCapability.available" type="info">
      {{ t('librefinance', 'No text-processing provider is enabled. The local preview is available.') }}
    </NcNoteCard>
    <section v-if="manager" class="lf-surface">
      <div class="lf-section-heading"><h2>{{ t('librefinance', 'Choose the analysis period') }}</h2></div>
      <form class="lf-form-grid" @submit.prevent="previewAi">
        <LfDateField id="lf-ai-from" v-model="aiFrom" :label="t('librefinance', 'Start of period')" />
        <LfDateField id="lf-ai-through" v-model="aiThrough" :label="t('librefinance', 'End of period')" />
        <NcButton variant="primary" type="submit" :disabled="aiBusy">
          {{aiBusy?t('librefinance', 'Loading preview…'):t('librefinance', 'Preview amounts')}}
        </NcButton>
      </form>
    </section>
    <section v-if="aiPreview" class="lf-surface">
      <div class="lf-section-heading"><h2>{{ t('librefinance', 'Information that would be shared') }}</h2></div>
      <div class="lf-kpis">
        <div class="lf-kpi"><span class="lf-muted">{{ t('librefinance', 'Income') }}</span>
          <strong class="lf-kpi-amount">{{money(aiPreview.revenueMinor)}}</strong></div>
        <div class="lf-kpi"><span class="lf-muted">{{ t('librefinance', 'Expenses') }}</span>
          <strong class="lf-kpi-amount">{{money(aiPreview.expenseMinor)}}</strong></div>
        <div class="lf-kpi"><span class="lf-muted">{{ t('librefinance', 'Result') }}</span>
          <strong class="lf-kpi-amount">{{money(aiPreview.resultMinor)}}</strong></div>
      </div>
      <p class="lf-muted">{{ t('librefinance', 'Period: {start} to {end}. Identities, documents and banking data are not sent.', { start: formatLedgerDate(aiPreview.periodStart), end: formatLedgerDate(aiPreview.periodEnd) }, { escape: false, sanitize: false }) }}</p>
      <NcCheckboxRadioSwitch v-model="aiConsent" type="checkbox"
        :disabled="!aiCapability?.available||aiBusy">
        {{ t('librefinance', 'I authorize sending only these totals to the configured provider.') }}
      </NcCheckboxRadioSwitch>
      <div class="lf-form-actions">
        <NcButton variant="primary"
          :disabled="!aiConsent||!aiCapability?.available||aiBusy"
          @click="requestAi">{{ t('librefinance', 'Request analysis') }}</NcButton>
      </div>
    </section>
    <section v-if="aiTask" class="lf-surface">
      <div class="lf-section-heading"><h2>{{ t('librefinance', 'Analysis status') }}</h2>
        <NcButton :disabled="aiBusy" @click="checkAiStatus">{{ t('librefinance', 'Refresh status') }}</NcButton></div>
      <p><strong>{{ statuses[aiTask.status] || statuses.unknown }}</strong> · {{ t('librefinance', 'Identifier {id}', { id: aiTask.taskId }, { escape: false, sanitize: false }) }}</p>
      <pre v-if="aiTask.output" class="lf-ai-output">{{aiTask.output}}</pre>
    </section>
  </div>
</template>

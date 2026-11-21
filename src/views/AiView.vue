<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { inject, onMounted } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import LfDateField from '../components/LfDateField.vue'
import LfPageHeader from '../components/LfPageHeader.vue'
const w=inject('lfWorkspace')
const {manager, aiCapability,aiPreview,aiTask,aiConsent,aiBusy,aiError,
  aiFrom,aiThrough,openAi,previewAi,requestAi,checkAiStatus,money}=w
onMounted(openAi)
</script>
<template>
  <div class="lf-view">
    <LfPageHeader title="Assistente financeiro" eyebrow="ANÁLISE OPCIONAL"
      description="Análises de totais agregados com consentimento explícito." />
    <NcNoteCard type="warning">
      O provedor de IA configurado pelo administrador pode transmitir dados a serviços externos.
      Nenhum valor será enviado sem sua confirmação. A análise não emite notas nem
      cria ou altera lançamentos.
    </NcNoteCard>
    <NcNoteCard v-if="aiError" type="error" role="alert">{{aiError}}</NcNoteCard>
    <NcNoteCard v-if="aiCapability&&!aiCapability.available" type="info">
      Nenhum provedor de processamento de texto está habilitado. A prévia local pode ser consultada.
    </NcNoteCard>
    <section v-if="manager" class="lf-surface">
      <div class="lf-section-heading"><h2>Escolha o período da análise</h2></div>
      <form class="lf-form-grid" @submit.prevent="previewAi">
        <LfDateField id="lf-ai-from" v-model="aiFrom" label="Início do período" />
        <LfDateField id="lf-ai-through" v-model="aiThrough" label="Fim do período" />
        <NcButton variant="primary" type="submit" :disabled="aiBusy">
          {{aiBusy?'Consultando…':'Pré-visualizar valores'}}
        </NcButton>
      </form>
    </section>
    <section v-if="aiPreview" class="lf-surface">
      <div class="lf-section-heading"><h2>Informações que seriam compartilhadas</h2></div>
      <div class="lf-kpis">
        <div class="lf-kpi"><span class="lf-muted">Receitas</span>
          <strong class="lf-kpi-amount">{{money(aiPreview.revenueMinor)}}</strong></div>
        <div class="lf-kpi"><span class="lf-muted">Despesas</span>
          <strong class="lf-kpi-amount">{{money(aiPreview.expenseMinor)}}</strong></div>
        <div class="lf-kpi"><span class="lf-muted">Resultado</span>
          <strong class="lf-kpi-amount">{{money(aiPreview.resultMinor)}}</strong></div>
      </div>
      <p class="lf-muted">Período: {{aiPreview.periodStart}} a {{aiPreview.periodEnd}}.
        Identidades, documentos e dados bancários não são enviados.</p>
      <NcCheckboxRadioSwitch v-model="aiConsent" type="checkbox"
        :disabled="!aiCapability?.available||aiBusy">
        Autorizo o envio somente destes totais ao provedor configurado.
      </NcCheckboxRadioSwitch>
      <div class="lf-form-actions">
        <NcButton variant="primary"
          :disabled="!aiConsent||!aiCapability?.available||aiBusy"
          @click="requestAi">Solicitar análise</NcButton>
      </div>
    </section>
    <section v-if="aiTask" class="lf-surface">
      <div class="lf-section-heading"><h2>Estado da análise</h2>
        <NcButton :disabled="aiBusy" @click="checkAiStatus">Atualizar estado</NcButton></div>
      <p><strong>{{aiTask.status}}</strong> · Identificador {{aiTask.taskId}}</p>
      <pre v-if="aiTask.output" class="lf-ai-output">{{aiTask.output}}</pre>
    </section>
  </div>
</template>

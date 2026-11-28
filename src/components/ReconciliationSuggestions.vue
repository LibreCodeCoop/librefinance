<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { computed, inject, onBeforeUnmount, ref, watch } from 'vue'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import LfSelect from './LfSelect.vue'
import { financeApi, financeError } from '../services/api.js'
import { formatLedgerDate } from '../date.js'

const props = defineProps({
  transactionId: { type: Number, required: true },
  disabled: { type: Boolean, default: false },
})
const emit = defineEmits(['choose', 'invalidate'])
const w = inject('lfWorkspace')
const open = ref(false)
const windowDays = ref(3)
const busy = ref(false)
const error = ref('')
const result = ref(null)
const candidates = ref([])
let generation = 0
let alive = true
const spaceId = computed(() => Number(w.activeId.value))
const active = computed(() => spaceId.value > 0 && spaceId.value === Number(w.space.value?.id)
  && props.transactionId > 0 && w.writable.value
  && w.modulesLoadedFor.value === spaceId.value
  && w.modules.value.some(module => module.id === 'librefinance_banking' && module.enabled))
const windows = [0, 3, 7, 15, 30].map(value => ({
  value,
  label: value === 0
    ? t('librefinance', 'Booking date only')
    : t('librefinance', 'Within {days} days', { days: value }),
}))

function invalidate() {
  generation++
  result.value = null
  candidates.value = []
  busy.value = false
  error.value = ''
  emit('invalidate')
}
watch([spaceId, () => props.transactionId, active], () => {
  open.value = false
  windowDays.value = 3
  invalidate()
}, { flush: 'sync' })
watch(windowDays, invalidate, { flush: 'sync' })
onBeforeUnmount(() => { alive = false; invalidate() })

function reasonLabel(reason) {
  return ({
    same_account: t('librefinance', 'Same financial account'),
    exact_signed_amount: t('librefinance', 'Exact amount and direction'),
    same_booking_date: t('librefinance', 'Same booking date'),
    within_date_window: t('librefinance', 'Within the selected date window'),
  })[reason] ?? reason
}
const messages = {
  unique: t('librefinance', 'One candidate found. Review it before confirming.'),
  ambiguous: t('librefinance', 'Several candidates match the evidence. Review and choose explicitly.'),
  no_candidate: t('librefinance', 'No candidates found in the selected date window. Manual review remains available.'),
  incomplete_lookup: t('librefinance', 'Candidate lookup is incomplete. Load more or search again before concluding.'),
}

async function lookup(append = false) {
  if (!alive || !active.value || props.disabled || busy.value || (append && !result.value?.nextAfterId)) return
  if (!append) invalidate()
  const token = ++generation
  const space = spaceId.value
  const transaction = props.transactionId
  const afterId = append ? result.value.nextAfterId : 0
  const previousClassification = result.value?.candidateStatus
  busy.value = true
  try {
    const response = await financeApi('librefinance_banking', space,
      '/transactions/' + transaction + '/reconciliation-candidates?windowDays=' + windowDays.value + '&afterId=' + afterId)
    if (!alive || token !== generation || !active.value || space !== spaceId.value || transaction !== props.transactionId) return
    if (response.contractVersion !== 1 || response.transactionId !== transaction
      || response.evidence?.windowDays !== windowDays.value
      || !Object.hasOwn(messages, response.status) || !Array.isArray(response.candidates)) {
      throw new Error(t('librefinance', 'Unsupported reconciliation candidate version.'))
    }
    if (append && response.candidateStatus !== previousClassification) {
      invalidate()
      error.value = t('librefinance', 'Candidates changed. Search again before continuing.')
      return
    }
    result.value = response
    candidates.value = append ? [...candidates.value, ...response.candidates] : response.candidates
  } catch (cause) {
    if (alive && token === generation) {
      invalidate()
      error.value = financeError(cause)
    }
  } finally {
    if (alive && token === generation) busy.value = false
  }
}
function toggle() {
  open.value = !open.value
  if (open.value) lookup()
  else invalidate()
}
function choose(candidate) {
  if (!alive || !active.value || props.disabled || busy.value
    || !candidates.value.some(row => row.journalId === candidate.journalId)) return
  emit('choose', {
    spaceId: spaceId.value,
    transactionId: props.transactionId,
    option: {
      value: candidate.journalId,
      label: formatLedgerDate(candidate.effectiveOn) + ' · ' + candidate.description + ' (#' + candidate.journalId + ')',
    },
  })
}
</script>
<template>
  <section v-if="active" class="lf-reconciliation-suggestions" :aria-label="t('librefinance', 'Reconciliation suggestions')">
    <NcButton variant="secondary" type="button" :aria-expanded="open" :disabled="disabled" @click="toggle">
      {{ t('librefinance', 'Find reconciliation suggestions') }}
    </NcButton>
    <template v-if="open">
      <p class="lf-muted">{{ t('librefinance', 'Suggestions use the same account, exact signed amount and an explicit date window. They never confirm a match automatically.') }}</p>
      <div class="lf-suggestion-filter">
        <LfSelect id="lf-reconciliation-window" v-model="windowDays" :options="windows"
          :label="t('librefinance', 'Date window')" :disabled="disabled" />
        <NcButton type="button" :disabled="disabled || busy" @click="lookup()">{{ t('librefinance', 'Search again') }}</NcButton>
      </div>
      <NcLoadingIcon v-if="busy" :size="24" />
      <NcNoteCard v-if="error" type="error" role="alert">{{ error }}</NcNoteCard>
      <template v-if="result">
        <p class="lf-muted">
          <time :datetime="result.evidence.from">{{ formatLedgerDate(result.evidence.from) }}</time> –
          <time :datetime="result.evidence.through">{{ formatLedgerDate(result.evidence.through) }}</time>
        </p>
        <p class="lf-muted">{{ t('librefinance', 'The bank source reference is available, but journal records have no comparable bank reference. It is not used as proof of a match.') }}</p>
        <NcNoteCard :type="result.status === 'ambiguous' || result.status === 'incomplete_lookup' ? 'warning' : 'info'" role="status">
          {{ messages[result.status] }}
        </NcNoteCard>
        <ul v-if="candidates.length" class="lf-suggestion-list">
          <li v-for="candidate in candidates" :key="candidate.journalId" :data-journal-id="candidate.journalId">
            <strong>#{{ candidate.journalId }} · {{ formatLedgerDate(candidate.effectiveOn) }}</strong>
            <p>{{ candidate.description }}</p>
            <p>{{ w.money(candidate.amountMinor) }}</p>
            <p class="lf-muted">{{ candidate.reasons.map(reasonLabel).join(' · ') }}</p>
            <NcButton type="button" variant="secondary" :disabled="disabled || busy" @click="choose(candidate)">
              {{ t('librefinance', 'Select this journal') }}
            </NcButton>
          </li>
        </ul>
        <NcButton v-if="result.nextAfterId" type="button" :disabled="disabled || busy" @click="lookup(true)">
          {{ t('librefinance', 'Load more') }}
        </NcButton>
      </template>
    </template>
  </section>
</template>
<style scoped>
.lf-reconciliation-suggestions { display: grid; gap: 8px; }
.lf-suggestion-filter { display: flex; align-items: end; gap: 8px; flex-wrap: wrap; }
.lf-suggestion-filter :deep(.lf-field) { flex: 1 1 160px; }
.lf-suggestion-list { display: grid; gap: 8px; padding: 0; list-style: none; max-height: 300px; overflow: auto; }
.lf-suggestion-list li { padding: 10px; border: 1px solid var(--color-border); border-radius: var(--border-radius-large); overflow-wrap: anywhere; }
.lf-suggestion-list p { margin: 3px 0; }
</style>

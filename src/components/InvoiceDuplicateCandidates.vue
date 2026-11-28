<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { computed, inject, onBeforeUnmount, ref, watch } from 'vue'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { financeApi, financeError } from '../services/api.js'
import { formatLedgerDate } from '../date.js'
import { formatContextMoney, parseContextMoney, resolveMoneyContext } from '../money.js'

const props = defineProps({
  invoiceId: { type: Number, default: 0 },
  kind: { type: String, default: '' },
  contactId: { type: Number, default: 0 },
  reference: { type: String, default: '' },
  issueOn: { type: String, default: '' },
  dateValid: { type: Boolean, default: true },
})
const emit = defineEmits(['inspect'])
const w = inject('lfWorkspace')
const sourceTotal = ref('')
const rows = ref([])
const cursor = ref(null)
const status = ref('')
const error = ref('')
const busy = ref(false)
const opened = ref(false)
let generation = 0
let alive = true

const moneyContext = computed(() => resolveMoneyContext(w.space.value))
const amountMinor = computed(() => {
  if (!sourceTotal.value.trim()) return ''
  if (moneyContext.value === null) return null
  try { return String(parseContextMoney(sourceTotal.value, w.space.value)) } catch { return null }
})
const query = computed(() => props.invoiceId > 0 ? { invoiceId: String(props.invoiceId) } : {
  kind: props.kind,
  contactId: String(props.contactId),
  reference: props.reference.trim().toUpperCase(),
  issueOn: props.issueOn,
  ...(amountMinor.value === '' || amountMinor.value === null ? {} : { totalMinor: amountMinor.value }),
})
const active = computed(() => Number(w.activeId.value) > 0
  && Number(w.activeId.value) === Number(w.space.value?.id))
const valid = computed(() => active.value && (props.invoiceId > 0
  || (props.dateValid && amountMinor.value !== null
    && (!query.value.reference || new RegExp('^[A-Z0-9_./:-]{2,80}$').test(query.value.reference)))))
function reset() {
  ++generation
  rows.value = []
  cursor.value = null
  status.value = ''
  error.value = ''
  busy.value = false
}
watch([query, () => props.dateValid, amountMinor], reset, { flush: 'sync' })
watch(() => w.activeId.value, () => {
  sourceTotal.value = ''
  opened.value = false
  reset()
}, { flush: 'sync' })
watch(active, allowed => { if (!allowed) reset() }, { flush: 'sync' })
onBeforeUnmount(() => { alive = false; reset() })

function reasonLabel(reason) {
  return ({
    same_reference: t('librefinance', 'Same reference'),
    same_total: t('librefinance', 'Same exact total'),
    same_issue_date: t('librefinance', 'Same issue date'),
    near_issue_date: t('librefinance', 'Issue date within three days'),
  })[reason] ?? reason
}
function displayAmount(row) {
  if (moneyContext.value !== null && row.currency === moneyContext.value.currency) {
    return formatContextMoney(row.totalMinor, w.space.value, { currency: row.currency })
  }
  return t('librefinance', '{amount} minor units ({currency})', { amount: row.totalMinor, currency: row.currency })
}
async function lookup(append = false) {
  if (!valid.value || busy.value || (append && cursor.value === null)) return
  const spaceId = Number(w.activeId.value)
  const afterId = append ? cursor.value : 0
  const params = new URLSearchParams({ ...query.value, afterId: String(afterId) })
  if (!append) reset()
  const token = ++generation
  busy.value = true
  try {
    const data = await financeApi('librefinance_invoicing', spaceId,
      '/invoice-duplicate-candidates?' + params.toString().replaceAll('.', '%2E'))
    if (!alive || !active.value || Number(w.activeId.value) !== spaceId || token !== generation) return
    if (data.contractVersion !== 1
      || !['candidates', 'no_candidate', 'incomplete_evidence'].includes(data.status)
      || !Array.isArray(data.candidates)) throw new Error(t('librefinance', 'Unsupported duplicate review version.'))
    rows.value = append ? [...rows.value, ...data.candidates] : data.candidates
    status.value = rows.value.length ? 'candidates' : data.status
    cursor.value = data.nextAfterId
  } catch (cause) {
    if (!alive || token !== generation || Number(w.activeId.value) !== spaceId) return
    rows.value = []
    status.value = ''
    cursor.value = null
    error.value = financeError(cause)
  } finally {
    if (alive && token === generation) busy.value = false
  }
}
function toggle() {
  opened.value = !opened.value
  if (!opened.value) reset()
}
</script>

<template>
  <section :aria-label="t('librefinance', 'Possible duplicate documents')" class="lf-custom-fields-panel">
    <NcButton variant="secondary" type="button" :aria-expanded="opened" @click="toggle">
      {{ t('librefinance', 'Check possible duplicates') }}
    </NcButton>
    <template v-if="opened">
      <p class="lf-muted">{{ t('librefinance', 'Matches are suggestions only. No documents will be changed.') }}</p>
      <NcTextField v-if="!invoiceId" v-model="sourceTotal"
        :label="t('librefinance', 'Source document total (optional)')" inputmode="decimal" maxlength="18"
        :helper-text="t('librefinance', 'Include taxes and withholdings in the final amount; leave empty if unknown.')" />
      <NcNoteCard v-if="!valid" type="warning">
        {{ t('librefinance', 'Review the reference, issue date and exact total before checking.') }}
      </NcNoteCard>
      <NcButton variant="secondary" type="button" :disabled="!valid || busy" @click="lookup()">
        {{ t('librefinance', 'Find candidates') }}
      </NcButton>
      <NcLoadingIcon v-if="busy" :size="24" />
      <NcNoteCard v-if="error" type="error" role="alert">{{ error }}</NcNoteCard>
      <NcNoteCard v-if="status === 'incomplete_evidence'" type="info" role="status">
        {{ t('librefinance', 'Select a party and enter a reference or both issue date and exact total.') }}
      </NcNoteCard>
      <NcNoteCard v-if="status === 'no_candidate'" type="info" role="status">
        {{ t('librefinance', 'No candidates found with the supplied evidence. This does not prove uniqueness.') }}
      </NcNoteCard>
      <NcNoteCard v-if="status === 'candidates'" type="warning" role="status">
        {{ t('librefinance', 'Possible matches need review. A matching reference is not proof of the same origin.') }}
      </NcNoteCard>
      <ul v-if="rows.length" class="lf-duplicate-list">
        <li v-for="row in rows" :key="row.id" :data-candidate-id="row.id">
          <strong>{{ row.reference }} (#{{ row.id }})</strong>
          <p>{{ formatLedgerDate(row.issueOn) }} · {{ displayAmount(row) }}</p>
          <p>{{ row.matchReasons.map(reasonLabel).join(' · ') }}</p>
          <NcButton v-if="invoiceId" variant="tertiary" type="button" @click="emit('inspect', row.id)">
            {{ t('librefinance', 'Details') }}
          </NcButton>
        </li>
      </ul>
      <NcButton v-if="cursor !== null" type="button" :disabled="busy" @click="lookup(true)">
        {{ t('librefinance', 'Load more') }}
      </NcButton>
    </template>
  </section>
</template>

<style scoped>
.lf-duplicate-list { display: grid; gap: 8px; padding: 0; list-style: none; }
.lf-duplicate-list > li { border: 1px solid var(--color-border); border-radius: var(--border-radius-large); padding: 12px; overflow-wrap: anywhere; }
.lf-duplicate-list p { margin: 4px 0; }
</style>

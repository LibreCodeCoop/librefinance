// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { t } from '@nextcloud/l10n'
import { financeApi, financeError } from '../services/api.js'

/** Preserve the established local CSV/OFX decoding contract. */
async function readStatement(file, format) {
  if (!file.size || file.size > 262144) {
    throw new Error(t('librefinance', 'The file must not be empty or exceed 256 KiB.'))
  }
  const bytes = new Uint8Array(await file.arrayBuffer())
  let charset = 'utf-8'
  if (format === 'ofx') {
    const header = new TextDecoder('windows-1252').decode(bytes.slice(0, 512))
    const numeric = header.match(/^CHARSET:\s*(\d+)\s*$/mi)?.[1]
    const xml = header.match(/<\?xml[^>]*encoding=['"]([^'"]+)['"]/i)?.[1]?.toLowerCase()
    if (numeric === '1252' || ['windows-1252', 'cp1252'].includes(xml)) charset = 'windows-1252'
    else if (numeric === '8859' || ['iso-8859-1', 'latin1'].includes(xml)) charset = 'iso-8859-1'
    else if (numeric && numeric !== '65001') {
      throw new Error(t('librefinance', 'Unknown OFX encoding: {encoding}', { encoding: numeric }))
    } else if (xml && !['utf-8', 'utf8'].includes(xml)) {
      throw new Error(t('librefinance', 'Unknown OFX encoding: {encoding}', { encoding: xml }))
    }
  }
  return new TextDecoder(charset, { fatal: true }).decode(bytes)
}

/**
 * One read-only preview and one explicit, fingerprint-bound confirmation.
 * Inputs belong to the mounted dialog. A stale response never restores
 * evidence for a different selection, even after navigating A -> B -> A.
 */
export function useStatementImportPreview({ spaceId, account, file, format, allowed, open, error, onConfirmed }) {
  const preview = ref(null)
  const previewing = ref(false)
  const confirming = ref(false)
  let generation = 0
  let alive = true
  let prepared = null
  const valid = computed(() => open.value && allowed.value
    && Number(spaceId.value) > 0 && Number(account.value) > 0 && !!file.value)
  const canConfirm = computed(() => valid.value && !!preview.value?.ready
    && !!prepared && !previewing.value && !confirming.value)

  function reset() {
    ++generation
    prepared = null
    preview.value = null
    previewing.value = false
    confirming.value = false
    error.value = ''
  }
  watch([spaceId, account, file, format, allowed, open], reset, { flush: 'sync' })
  onBeforeUnmount(() => { alive = false; reset() })
  const current = (token, target) => alive && token === generation
    && Number(spaceId.value) === target && valid.value

  function message(cause) {
    if (cause.response?.data?.error === 'bank_import_preview_changed'
      || cause.response?.data?.error === 'bank_import_conflict'
      || cause.response?.data?.error === 'account_reference_busy') {
      return t('librefinance', 'The import state changed. Review the file again before confirming.')
    }
    if ([403, 404].includes(cause.response?.status)) {
      return t('librefinance', 'Import permission or destination account is no longer available.')
    }
    return financeError(cause)
  }

  async function review() {
    if (!valid.value || previewing.value || confirming.value) return
    reset()
    const token = generation
    const target = Number(spaceId.value)
    const bankAccountId = Number(account.value)
    const selectedFormat = format.value
    const selectedFile = file.value
    previewing.value = true
    try {
      const content = await readStatement(selectedFile, selectedFormat)
      if (!current(token, target)) return
      const payload = selectedFormat === 'ofx'
        ? { bankAccountId, format: 'ofx', ofx: content }
        : { bankAccountId, format: 'csv', csv: content }
      const data = await financeApi('librefinance_banking', target, '/imports/preview', 'POST', payload)
      if (!current(token, target)) return
      if (data.contractVersion !== 1 || data.postingCreated !== false
        || data.bankAccountId !== bankAccountId || data.format !== selectedFormat
        || !Array.isArray(data.rows) || (data.ready && !/^[0-9a-f]{64}$/.test(data.previewHash ?? ''))) {
        throw new Error(t('librefinance', 'Unsupported statement preview version.'))
      }
      prepared = data.ready ? { payload, previewHash: data.previewHash } : null
      preview.value = data
    } catch (cause) {
      if (current(token, target)) error.value = message(cause)
    } finally {
      if (current(token, target)) previewing.value = false
    }
  }

  async function confirm() {
    if (!canConfirm.value) return
    const token = generation
    const target = Number(spaceId.value)
    const { payload, previewHash } = prepared
    confirming.value = true
    error.value = ''
    try {
      const result = await financeApi('librefinance_banking', target, '/imports', 'POST', { ...payload, previewHash })
      if (!current(token, target)) return
      prepared = null
      preview.value = null
      confirming.value = false
      await onConfirmed(result, payload.bankAccountId)
    } catch (cause) {
      if (current(token, target)) {
        // A network failure can also follow a committed import. Never
        // blindly reuse an old fingerprint or repeat an uncertain write.
        prepared = null
        preview.value = null
        error.value = message(cause)
      }
    } finally {
      if (current(token, target)) confirming.value = false
    }
  }
  return { preview, previewing, confirming, canConfirm, reset, review, confirm }
}

// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { t } from '@nextcloud/l10n'

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const invalidRequestMessage = () => t('librefinance', 'Invalid financial request')

// The native shell owns this selection for the lifetime of its mount.
let readSelection

export function bindFinanceSelection(reader) {
  readSelection = reader
  return () => {
    if (readSelection === reader) readSelection = undefined
  }
}

export async function requestFinancialWorkspace(spaceId, url, method, data) {
  const reader = readSelection
  const selection = reader?.()
  if (!selection?.context || selection.spaceId !== Number(spaceId)) {
    throw new Error(invalidRequestMessage())
  }
  const response = await axios({
    url, method, data,
    headers: { Accept: 'application/json', 'X-LibreFinance-Context': selection.context },
  }).finally(() => {
    // A delayed success or conflict must not continue an action in the new
    // workspace, even when navigation returned to the same ID.
    if (readSelection !== reader || reader() !== selection) {
      throw new Error(invalidRequestMessage())
    }
  })
  return response.data
}

// Internal domains retain their feature IDs for per-space authorization,
// but their HTTP controllers now belong to the single LibreFinance app.
const internalDomains = new Set([
  'librefinance_reports', 'librefinance_banking',
  'librefinance_invoicing', 'librefinance_obligations',
  'librefinance_import',
])

const accepted = new Set([
  'librefinance', 'librefinance_reports', 'librefinance_banking',
  'librefinance_invoicing', 'librefinance_obligations',
  'librefinance_import', 'librefinance_nfse',
])

/**
 * Scope requests to a trusted installed app and one authorized finance space.
 * Never use a URL from a source document or imported record.
 */
export async function financeApi(app, spaceId, route, method = 'GET', data) {
  if (!accepted.has(app)
    || !Number.isSafeInteger(Number(spaceId)) || Number(spaceId) < 1
    // URLSearchParams encodes query spaces as '+'. Never allow it in a path.
    || !/^\/[a-zA-Z0-9/_=&%.-]*(?:\?[a-zA-Z0-9/_?=&%+.-]*)?$/.test(route)
    || route.includes('..')) {
    throw new Error(invalidRequestMessage())
  }
  const owner = internalDomains.has(app) ? 'librefinance' : app
  const base = '/apps/' + owner + '/api/spaces/' + Number(spaceId) + route
  return requestFinancialWorkspace(spaceId, generateUrl(base), method, data)
}
export function financeError(error) {
  return error.response?.data?.error || error.message || t('librefinance', 'Financial operation failed')
}

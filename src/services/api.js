// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { t } from '@nextcloud/l10n'

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const invalidRequestMessage = () => t('librefinance', 'Invalid financial request')

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
    || !/^\/[a-zA-Z0-9/_?=&%.-]*$/.test(route)
    || route.includes('..')) {
    throw new Error(invalidRequestMessage())
  }
  const owner = internalDomains.has(app) ? 'librefinance' : app
  const base = '/apps/' + owner + '/api/spaces/' + Number(spaceId) + route
  const response = await axios({
    url: generateUrl(base), method,
    data, headers: { Accept: 'application/json' },
  })
  return response.data
}
export function financeError(error) {
  return error.response?.data?.error || error.message || t('librefinance', 'Financial operation failed')
}

// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { test, expect } from '@playwright/test'
import { login, makeSpace, goToView } from './helpers.mjs'

test('native workspace policy editor shows scoped cursor-paginated audit history', async ({ page }) => {
  await login(page)
  await makeSpace(page, 'personal')
  // Presentation-only contract mocks. Real audit persistence and negative
  // authorization are verified separately by Nextcloud PHP integration.
  await page.route('**/apps/librefinance/api/spaces', async route => {
    if (route.request().method() !== 'GET') return route.continue()
    const response = await route.fetch()
    const data = await response.json()
    return route.fulfill({ response, json: { ...data,
      spaces: (data.spaces || []).map(s => ({ ...s, saasManaged: true })),
    } })
  })
  await page.route('**/apps/librefinance/api/saas/v1/workspaces/*/policies/effective', route => {
    return route.fulfill({ status: 200, json: { policies: [{
      key: 'imports.enabled', type: 'boolean', effectiveValue: true,
      sourceScope: 'default', blockedBy: null, revision: 0,
      inherited: true, editableByCurrentActor: true, allowedValues: [false, true],
    }] } })
  })
  const newest = {
    id: 78, policyKey: 'imports.enabled', actorUid: 'lfmaintestA',
    action: 'set', oldValueJson: null, newValueJson: 'false',
    previousRevision: 0, newRevision: 1, occurredAt: 1795460000,
  }
  const previous = {
    id: 77, policyKey: 'imports.enabled', actorUid: 'lfmaintestA',
    action: 'clear', oldValueJson: 'false', newValueJson: null,
    previousRevision: 1, newRevision: 2, occurredAt: 1795450000,
  }
  let auditRequests = 0
  await page.route('**/apps/librefinance/api/saas/v1/policy-audit/workspaces/**', route => {
    auditRequests += 1
    const cursor = new URL(route.request().url()).searchParams.get('beforeId')
    if (cursor === null) return route.fulfill({ status: 200, json: {
      events: [newest], nextBeforeId: 78,
    } })
    expect(cursor).toBe('78')
    return route.fulfill({ status: 200, json: {
      events: [previous], nextBeforeId: null,
    } })
  })
  await page.reload()
  await goToView(page, 'Gestões financeiras')
  await page.getByRole('button', { name: 'Configurar políticas' }).click()
  const editor = page.getByRole('region', { name: 'Políticas SaaS de Gestão' })
  await editor.getByRole('button', { name: 'Ver histórico de policies' }).click()
  const audit = editor.getByRole('region', {
    name: 'Histórico de alterações das policies SaaS',
  })
  await expect(audit).toContainText('lfmaintestA')
  await expect(audit).toContainText('Revisão 0 → 1')
  await expect(audit).toContainText('Antes: Herdado')
  await audit.getByRole('button', { name: 'Carregar eventos anteriores' }).click()
  await expect(audit).toContainText('Revisão 1 → 2')
  await expect(audit.getByRole('listitem')).toHaveCount(2)
  expect(auditRequests).toBe(2)
  await editor.getByRole('button', { name: 'Ocultar histórico de policies' }).click()
  await expect(audit).toHaveCount(0)
})

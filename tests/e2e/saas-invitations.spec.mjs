// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect } from '@playwright/test'
import { login, makeSpace, goToView } from './helpers.mjs'

test('SaaS owner creates and revokes a read-only external invitation', async ({ page }) => {
  await login(page)
  await makeSpace(page, 'personal')
  // Only the selector metadata is mocked here; real PHP acceptance, ACL,
  // policy, audit, expiry and revocation are exercised by saas-access.php.
  await page.route('**/apps/librefinance/api/spaces', async route => {
    if (route.request().method() !== 'GET') return route.continue()
    const response = await route.fetch()
    const body = await response.json()
    return route.fulfill({ response, json: {
      ...body, spaces: (body.spaces || []).map(s => ({ ...s, saasManaged: true })),
    } })
  })
  let rows = []
  const requests = []
  await page.route('**/apps/librefinance/api/saas/v1/workspaces/*/invitations**', async route => {
    const request = route.request()
    const method = request.method()
    if (method === 'GET') return route.fulfill({ status: 200, json: {
      invitations: rows, nextAfterId: null,
    } })
    if (method === 'POST') {
      const payload = request.postDataJSON()
      requests.push({ method: 'POST', ...payload })
      rows = [{
        id: 71, spaceId: 1, recipientUid: payload.recipientUid, role: payload.role,
        revision: 1, status: 'invited', expiresAt: payload.expiresAt,
      }]
      return route.fulfill({ status: 201, json: rows[0] })
    }
    if (method === 'DELETE') {
      const revision = new URL(request.url()).searchParams.get('expectedRevision')
      requests.push({ method: 'DELETE', revision })
      rows = [{ ...rows[0], revision: 2, status: 'revoked' }]
      return route.fulfill({ status: 200, json: rows[0] })
    }
    return route.continue()
  })
  await page.reload()
  await goToView(page, 'Gestões financeiras')
  const panel = page.getByRole('region', { name: 'Concessões financeiras externas' })
  await expect(panel).toBeVisible()
  await panel.getByRole('textbox', { name: 'Identificador do usuário Nextcloud' })
    .fill('external_test_user')
  await panel.getByRole('button', { name: 'Convidar conta externa' }).click()
  await expect(panel).toContainText('external_test_user')
  await expect(panel).toContainText('Aguardando aceite')
  await panel.getByRole('button', { name: 'Revogar acesso de external_test_user' }).click()
  await expect(panel).toContainText('Revogado')
  expect(requests).toHaveLength(2)
  expect(requests[0].recipientUid).toBe('external_test_user')
  expect(requests[0].role).toBe('viewer')
  expect(requests[0].expectedRevision).toBe(0)
  expect(requests[0].expiresAt).toBeGreaterThan(Math.floor(Date.now() / 1000))
  expect(requests[1]).toEqual({ method: 'DELETE', revision: '1' })
})

test('invited account explicitly accepts its own external financial invitation', async ({ page }) => {
  await login(page)
  await makeSpace(page, 'personal')
  let accepted = false
  const invitation = {
    id: 82, spaceId: 90021, recipientUid: 'lfmaintestA',
    role: 'auditor', status: 'invited', revision: 4,
    expiresAt: Math.floor(Date.now() / 1000) + 3600,
  }
  await page.route('**/apps/librefinance/api/saas/v1/invitations**', route => {
    return route.fulfill({ status: 200, json: {
      invitations: [accepted
        ? { ...invitation, status: 'accepted', revision: 5 }
        : invitation],
      nextAfterId: null,
    } })
  })
  await page.route('**/apps/librefinance/api/saas/v1/workspaces/*/invitations/*/accept', route => {
    expect(route.request().method()).toBe('POST')
    expect(route.request().postDataJSON()).toEqual({ expectedRevision: 4 })
    accepted = true
    return route.fulfill({ status: 200, json: {
      ...invitation, status: 'accepted', revision: 5,
    } })
  })
  await goToView(page, 'Gestões financeiras')
  const panel = page.getByRole('region', { name: 'Convites financeiros recebidos' })
  await expect(panel).toContainText('Gestão 90021')
  await expect(panel).toContainText('Aguardando aceite')
  await panel.getByRole('button', { name: 'Aceitar convite da gestão 90021' }).click()
  await expect(panel).toContainText('Aceito')
  expect(accepted).toBe(true)
})

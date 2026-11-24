// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect } from '@playwright/test'
import { login, makeSpace, goToView } from './helpers.mjs'

// This checks the Vue client protocol only. The real SaaS authorization,
// concurrency and mutation/rollback semantics are exercised in installed
// Nextcloud by tests/integration/saas-access.php.
test('SaaS member editor sends current CAS revision for grant and revocation', async ({ page }) => {
  await login(page)
  await makeSpace(page, 'personal')
  let revision = 6
  let present = false
  let posted = 0
  let deleted = 0
  let postedPayload = null
  let deletedRevision = null

  await page.route('**/apps/librefinance/api/spaces/*/members**', async route => {
    const request = route.request()
    const url = new URL(request.url())
    if (request.method() === 'GET' && url.pathname.endsWith('/members')) {
      const response = await route.fetch()
      const payload = await response.json()
      return route.fulfill({ response, json: {
        ...payload, grantRevision: revision, canManageMembers: true,
        members: [...(payload.members || []),
          { uid: 'synthetic-list-loaded', role: 'owner' },
          ...(present ? [{ uid: 'synthetic-revision-user', role: 'viewer' }] : [])],
      } })
    }
    if (request.method() === 'POST' && url.pathname.endsWith('/members')) {
      postedPayload = request.postDataJSON()
      ++posted
      revision = 7
      present = true
      return route.fulfill({ status: 200, json: { ok: true } })
    }
    if (request.method() === 'DELETE' && url.pathname.endsWith('/members/synthetic-revision-user')) {
      deletedRevision = url.searchParams.get('expectedRevision')
      ++deleted
      revision = 8
      present = false
      return route.fulfill({ status: 200, json: { ok: true } })
    }
    return route.continue()
  })
  await page.reload()
  await goToView(page, 'Acessos e compartilhamento')
  // Wait for the first member response and the reactive revision to render;
  // clicking during initial loading exercises a different UI state.
  await expect(page.getByText('synthetic-list-loaded', { exact: true })).toBeVisible()

  await page.getByRole('button', { name: 'Usuário', exact: true }).click()
  const grant = page.getByRole('dialog', { name: 'Conceder acesso a usuário' })
  await grant.getByRole('textbox', { name: 'Usuário Nextcloud' }).fill('synthetic-revision-user')
  await grant.getByRole('button', { name: 'Conceder acesso' }).click()
  await expect(page.getByRole('button', { name: 'Revogar acesso de synthetic-revision-user' })).toBeVisible()

  await page.getByRole('button', { name: 'Revogar acesso de synthetic-revision-user' }).click()
  const revoke = page.getByRole('dialog', { name: 'Revogar acesso' })
  await revoke.getByRole('button', { name: 'Revogar acesso' }).click()
  await expect(page.getByRole('button', { name: 'Revogar acesso de synthetic-revision-user' })).toHaveCount(0)
  expect(posted).toBe(1)
  expect(deleted).toBe(1)
  expect(postedPayload).toEqual({
    uid: 'synthetic-revision-user', role: 'viewer', expectedRevision: 6,
  })
  expect(deletedRevision).toBe('7')
})

test('SaaS member buttons follow the server-authorized management capability', async ({ page }) => {
  await login(page)
  await makeSpace(page, 'personal')
  let authorized = false
  await page.route('**/apps/librefinance/api/spaces/*/members**', async route => {
    const request = route.request()
    if (request.method() !== 'GET'
      || !new URL(request.url()).pathname.endsWith('/members')) return route.continue()
    // Synthetic API contract is enough for presentation testing. Avoid
    // a real fetch surviving the teardown of the Playwright route.
    return route.fulfill({ status: 200, json: {
      grantRevision: 5,
      canManageMembers: authorized,
      members: [{ uid: 'synthetic-list-loaded', role: 'owner' }],
    } })
  })
  await page.reload()
  await goToView(page, 'Acessos e compartilhamento')
  await expect(page.getByText('synthetic-list-loaded', { exact: true })).toBeVisible()
  await expect(page.getByRole('button', { name: 'Usuário', exact: true })).toHaveCount(0)
  await expect(page.getByText('Apenas quem possui permissão financeira para gerenciar papéis', { exact: false })).toBeVisible()

  authorized = true
  await page.reload()
  await goToView(page, 'Acessos e compartilhamento')
  await expect(page.getByText('synthetic-list-loaded', { exact: true })).toBeVisible()
  await expect(page.getByRole('button', { name: 'Usuário', exact: true })).toBeVisible()
})

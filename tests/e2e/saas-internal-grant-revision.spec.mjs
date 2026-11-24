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
      // This test verifies the frontend contract; avoid an unnecessary
      // upstream request that can outlive the Playwright test context.
      return route.fulfill({ status: 200, json: {
        grantRevision: revision,
        members: [
          { uid: 'synthetic-list-loaded', role: 'owner' },
          ...(present ? [{ uid: 'synthetic-revision-user', role: 'viewer' }] : []),
        ],
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

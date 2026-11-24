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

  await page.route('**/apps/librefinance/api/spaces/*/members**', async route => {
    const request = route.request()
    const url = new URL(request.url())
    if (request.method() === 'GET' && url.pathname.endsWith('/members')) {
      const response = await route.fetch()
      const payload = await response.json()
      return route.fulfill({ response, json: {
        ...payload, grantRevision: revision,
        members: [...(payload.members || []),
          ...(present ? [{ uid: 'synthetic-revision-user', role: 'viewer' }] : [])],
      } })
    }
    if (request.method() === 'POST' && url.pathname.endsWith('/members')) {
      expect(request.postDataJSON()).toEqual({
        uid: 'synthetic-revision-user', role: 'viewer', expectedRevision: 6,
      })
      ++posted
      revision = 7
      present = true
      return route.fulfill({ status: 200, json: { ok: true } })
    }
    if (request.method() === 'DELETE' && url.pathname.endsWith('/members/synthetic-revision-user')) {
      expect(url.searchParams.get('expectedRevision')).toBe('7')
      ++deleted
      revision = 8
      present = false
      return route.fulfill({ status: 200, json: { ok: true } })
    }
    return route.continue()
  })
  await page.reload()
  await goToView(page, 'Acessos e compartilhamento')

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
})

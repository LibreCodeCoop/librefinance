// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect } from '@playwright/test'

const app = '/index.php/apps/librefinance/'
const api = app + 'api'
const users = { admin: 'synthetictestadmin', a: 'lfmaintestA', b: 'lfmaintestB' }

async function authenticatedPage(browser, baseURL, uid, password) {
  const context = await browser.newContext({ baseURL })
  try {
    const page = await context.newPage()
    await page.goto('/index.php/login')
    await page.locator('#user').fill(uid)
    await page.locator('#password').fill(password)
    await page.locator('button[type=submit]').first().click()
    await page.waitForURL(url => !url.pathname.endsWith('/login'), { timeout: 25_000 })
    await page.goto(app)
    await expect(page.locator('head')).toHaveAttribute('data-user', uid)
    const token = await page.locator('head').getAttribute('data-requesttoken')
    expect(token).toBeTruthy()
    return { context, page, token, uid }
  } catch (error) {
    await context.close()
    throw error
  }
}

// Use the browser's actual authenticated cookies and CSRF token. No API
// response is intercepted or synthesized, including forbidden operations.
async function call(actor, method, path, status, data) {
  const response = await actor.page.request.fetch(api + path, {
    method, headers: { requesttoken: actor.token, Accept: 'application/json' },
    ...(data === undefined ? {} : { data }),
  })
  const observed = response.status()
  expect(observed, actor.uid + ' ' + method + ' ' + path).toBe(status)
  const body = await response.json()
  if (status === 403 || status === 404) {
    expect(Object.keys(body)).toEqual(['error'])
    expect(typeof body.error).toBe('string')
  }
  return body
}

async function selectLedger(actor, book, memo) {
  const { page } = actor
  await page.goto(app + '?spaceId=' + book.id + '&view=transactions')
  await expect(page.locator('.lf-context-identity')).toContainText(book.name)
  await expect(page.locator('[data-testid="librefinance-workspace"]'))
    .toHaveAttribute('data-modules-loaded', 'true')
  await expect(page.getByRole('heading', { name: 'Entradas e saídas', exact: true })).toBeVisible()
  await expect(page.locator('.lf-view').getByText(memo, { exact: true })).toBeVisible()
}

test('real SaaS browser sessions isolate roles, forged IDs and immediate revocation', async ({ browser, baseURL }) => {
  test.setTimeout(120_000)
  if (process.env.LF_SYNTHETIC_SAAS_BROWSER !== 'yes'
    || !/^http:\/\/127\.0\.0\.1:\d+$/.test(baseURL || '')
    || !process.env.LF_TEST_ADMIN_PASSWORD || !process.env.LF_TEST_USER_PASS) {
    throw new Error('Real SaaS browser suite requires the explicitly opted-in disposable loopback lab')
  }

  const actors = []
  let admin
  let managedEnabled = false
  try {
    admin = await authenticatedPage(browser, baseURL, users.admin, process.env.LF_TEST_ADMIN_PASSWORD)
    actors.push(admin)
    const a = await authenticatedPage(browser, baseURL, users.a, process.env.LF_TEST_USER_PASS)
    actors.push(a)
    const b = await authenticatedPage(browser, baseURL, users.b, process.env.LF_TEST_USER_PASS)
    actors.push(b)
    expect(await call(admin, 'GET', '/admin/governance', 200)).toMatchObject({ managed: false })
    await call(admin, 'PUT', '/admin/governance', 200, { managed: true })
    managedEnabled = true

    const activate = async (group, manager, actor) => {
      const enabled = await call(admin, 'PUT', '/saas/v1/admin/groups/' + group + '/enablement', 200, { enabled: true })
      const env = enabled.environment.id
      expect(env).toBeTruthy()
      await call(admin, 'POST', '/admin/groups/' + group + '/delegates', 201, { uid: manager })
      await call(actor, 'POST', '/saas/v1/environments/' + env + '/provision', 200)
      return env
    }
    const envA = await activate('lf_synth_browser_A', users.b, b)
    const envB = await activate('lf_synth_browser_B', users.a, a)
    expect(envA).not.toBe(envB)
    const book = async (actor, env, ownerUid, name, key) =>
      call(actor, 'POST', '/saas/v1/environments/' + env + '/workspaces', 201,
        { name, ownerUid, idempotencyKey: key, legalName: name, companyCountry: 'PT' })
    const a1 = await book(b, envA, users.a, 'Synthetic browser A1', 'browser-ledger-A1')
    const a2 = await book(b, envA, users.a, 'Synthetic browser A2', 'browser-ledger-A2')
    const b1 = await book(a, envB, users.b, 'Synthetic browser B1', 'browser-ledger-B1')
    expect(new Set([a1.id, a2.id, b1.id]).size).toBe(3)
    expect(new Set([a1.companyId, a2.companyId, b1.companyId]).size).toBe(3)

    const ledger = value => '/spaces/' + value.id
    // Tenant administration/group membership does not grant financial access.
    await call(b, 'GET', ledger(a1) + '/accounts', 404)
    await call(admin, 'GET', ledger(a1) + '/journals', 404)
    await call(a, 'GET', ledger(b1) + '/journals', 404)

    const grant = (value, role) => call(a, 'POST', ledger(value) + '/members', 200,
      { uid: users.b, role, expectedRevision: 0 })
    await grant(a1, 'operator')
    await grant(a2, 'viewer')

    const posting = async (actor, value, memo, amountMinor) => {
      const { accounts } = await call(actor, 'GET', ledger(value) + '/accounts', 200)
      const ids = Object.fromEntries(accounts.map(row => [row.code, row.id]))
      expect(ids['1000']).toBeTruthy()
      expect(ids['4000']).toBeTruthy()
      return { debitId: ids['1000'], creditId: ids['4000'], amountMinor,
        date: '2026-11-23', memo, externalKey: memo }
    }
    const allowed = await posting(b, a1, 'synthetic-browser-operator', 137)
    const denied = await posting(b, a2, 'synthetic-browser-forbidden', 149)
    const owner = await posting(a, a2, 'synthetic-browser-owner-A2', 263)
    await call(b, 'POST', ledger(a1) + '/journals', 201, allowed)
    await call(b, 'POST', ledger(a2) + '/journals', 403, denied)
    await call(a, 'POST', ledger(a2) + '/journals', 201, owner)
    await call(b, 'POST', ledger(a1) + '/journals', 404,
      { ...denied, externalKey: 'synthetic-browser-cross-ledger' })
    await call(b, 'POST', ledger(a2) + '/members', 403,
      { uid: users.b, role: 'manager', expectedRevision: 1 })

    // Real financial owner B has independent roles in two environments.
    // Group names are presentation metadata only for authorized internal books.
    const listing = await call(b, 'GET', '/spaces', 200)
    expect(listing.spaces.find(s => s.id === a1.id).financialEnvironment.id).toBe(envA)
    expect(listing.spaces.find(s => s.id === a2.id).financialEnvironment.id).toBe(envA)
    expect(listing.spaces.find(s => s.id === b1.id).financialEnvironment.id).toBe(envB)
    await b.page.goto(app)
    await expect(b.page.getByRole('note', { name: 'Selecione um ambiente financeiro', exact: true })).toBeVisible()
    await expect(b.page.locator('.lf-context-identity')).toHaveCount(0)
    const environmentSelect = b.page.locator('#lf-environment-select')
    await environmentSelect.click()
    await b.page.getByRole('option').filter({ hasText: listing.spaces.find(s => s.id === a1.id).financialEnvironment.name }).click()
    await expect(b.page.locator('.lf-context-identity')).toContainText(a1.name)
    const bookSelect = b.page.locator('#lf-space-select')
    await bookSelect.click()
    await expect(b.page.getByRole('option').filter({ hasText: a2.name })).toBeVisible()
    await expect(b.page.getByRole('option').filter({ hasText: b1.name })).toHaveCount(0)
    await b.page.keyboard.press('Escape')
    await environmentSelect.click()
    await b.page.getByRole('option').filter({ hasText: listing.spaces.find(s => s.id === b1.id).financialEnvironment.name }).click()
    await expect(b.page.locator('.lf-context-identity')).toContainText(b1.name)
    await expect(b.page.locator('#lf-space-select')).toHaveCount(0)

    await selectLedger(b, a1, allowed.memo)
    await expect(b.page.getByRole('button', { name: 'Registrar entrada ou saída', exact: true })).toBeVisible()
    await expect(b.page.locator('.lf-view')).not.toContainText(owner.memo)
    await selectLedger(b, a2, owner.memo)
    await expect(b.page.getByRole('button', { name: 'Registrar entrada ou saída', exact: true })).toHaveCount(0)
    await expect(b.page.locator('.lf-view')).not.toContainText(allowed.memo)
    await expect(b.page.locator('.lf-view')).not.toContainText(denied.memo)

    // Mobile/dark mode must preserve the restricted viewer presentation.
    await b.page.setViewportSize({ width: 390, height: 844 })
    await b.page.emulateMedia({ colorScheme: 'dark' })
    await expect(b.page.locator('.lf-context-identity')).toContainText(a2.name)
    await expect(b.page.getByRole('button', { name: 'Registrar entrada ou saída', exact: true })).toHaveCount(0)

    await call(a, 'DELETE', ledger(a1) + '/members/' + users.b + '?expectedRevision=1', 200)
    await call(b, 'GET', ledger(a1) + '/journals', 404)
    await call(b, 'GET', ledger(a2) + '/journals', 200)
    await b.page.goto(app + '?spaceId=' + a1.id + '&view=transactions')
    await expect(b.page.locator('[data-testid="librefinance-workspace"]')).toBeVisible()
    await expect(b.page.getByRole('note', { name: 'Selecione um ambiente financeiro', exact: true })).toBeVisible()
    await expect(b.page.locator('.lf-context-identity')).toHaveCount(0)
    await expect(b.page.getByText(allowed.memo, { exact: true })).toHaveCount(0)
    await selectLedger(b, a2, owner.memo)
    await expect(b.page.locator('.lf-context-identity')).toContainText(a2.name)
    await expect(b.page.locator('.lf-view')).not.toContainText(allowed.memo)
    // Revocation changes authority, not the original posted journal.
    const preserved = await call(a, 'GET', ledger(a1) + '/journals', 200)
    expect(JSON.stringify(preserved)).toContain(allowed.memo)
  } finally {
    try {
      if (managedEnabled) {
        await call(admin, 'PUT', '/admin/governance', 200, { managed: false })
      }
    } finally {
      await Promise.all(actors.map(actor => actor.context.close()))
    }
  }
})

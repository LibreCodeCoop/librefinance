// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect } from '@playwright/test'
import { login, makeSpace, goToView } from './helpers.mjs'

test('SaaS banking menu offers only policy-permitted CSV or OFX formats', async ({page}) => {
  await login(page)
  await makeSpace(page, 'personal')
  // UI fixture only. The actual server-side intersection and denial are
  // exercised by tests/integration/saas-access.php against Nextcloud.
  await page.route('**/apps/librefinance/api/spaces', async route => {
    if (route.request().method() !== 'GET') return route.continue()
    const response = await route.fetch()
    const body = await response.json()
    return route.fulfill({response, json: {
      ...body,
      spaces: (body.spaces || []).map(space => ({...space, saasManaged: true})),
    }})
  })
  let allowed = ['csv']
  await page.route('**/apps/librefinance/api/saas/v1/workspaces/*/policies/banking.allowedImportFormats', async route => {
    return route.fulfill({status: 200, json: {
      key: 'banking.allowedImportFormats',
      effectiveValue: allowed,
      sourceScope: 'workspace',
      allowedValues: allowed,
    }})
  })
  await page.reload()
  await goToView(page, 'Movimentações')
  const actions = page.locator('#lf-import-menu')
  await expect(actions).toBeVisible()
  await actions.click()
  const menu = page.getByRole('menu', {name: 'Importar extrato'})
  await expect(menu.getByRole('menuitem', {name: 'Arquivo CSV'})).toBeVisible()
  await expect(menu.getByRole('menuitem', {name: 'Arquivo OFX'})).toHaveCount(0)
  await menu.getByRole('menuitem', {name: 'Arquivo CSV'}).click()
  const csv = page.getByRole('dialog', {name: 'Importar extrato CSV'})
  await expect(csv.getByRole('button', {name: 'Selecionar arquivo CSV'})).toBeVisible()
  await csv.getByRole('button', {name: 'Cancelar'}).click()

  allowed = ['ofx']
  await page.reload()
  await goToView(page, 'Movimentações')
  await expect(actions).toBeVisible()
  await actions.click()
  await expect(menu.getByRole('menuitem', {name: 'Arquivo CSV'})).toHaveCount(0)
  await expect(menu.getByRole('menuitem', {name: 'Arquivo OFX'})).toBeVisible()
  await menu.getByRole('menuitem', {name: 'Arquivo OFX'}).click()
  const ofx = page.getByRole('dialog', {name: 'Importar extrato OFX'})
  await expect(ofx.getByRole('button', {name: 'Selecionar arquivo OFX'})).toBeVisible()
  await ofx.getByRole('button', {name: 'Cancelar'}).click()

  allowed = []
  await page.reload()
  await goToView(page, 'Movimentações')
  await expect(actions).toHaveCount(0)
})
test('unavailable SaaS policy fails closed in the import menu', async ({page}) => {
  await login(page)
  await makeSpace(page, 'personal')
  await page.route('**/apps/librefinance/api/spaces', async route => {
    if (route.request().method() !== 'GET') return route.continue()
    const response = await route.fetch()
    const body = await response.json()
    return route.fulfill({response, json: {
      ...body, spaces: (body.spaces || []).map(s => ({...s, saasManaged: true})),
    }})
  })
  await page.route('**/apps/librefinance/api/saas/v1/workspaces/*/policies/banking.allowedImportFormats',
    route => route.fulfill({status: 503, json: {error: 'policy_unavailable'}}))
  await page.reload()
  await goToView(page, 'Movimentações')
  await expect(page.locator('#lf-import-menu')).toHaveCount(0)
})


test('contextual statement toolbar waits for effective policy and never offers forbidden import', async ({page}) => {
  await login(page)
  await makeSpace(page, 'personal')
  const id = new URL(page.url()).searchParams.get('spaceId')
  const url = '/index.php/apps/librefinance/?spaceId=' + id + '&view=banking'
  await page.route('**/apps/librefinance/api/spaces', async route => {
    if (route.request().method() !== 'GET') return route.continue()
    const response = await route.fetch()
    const body = await response.json()
    return route.fulfill({response, json: {
      ...body, spaces: (body.spaces || []).map(space => ({...space, saasManaged: true})),
    }})
  })
  let resolvePolicy
  const pending = new Promise(resolve => { resolvePolicy = resolve })
  let requested
  const policyRequested = new Promise(resolve => { requested = resolve })
  let allowed = ['csv']
  let unavailable = false
  await page.route('**/apps/librefinance/api/saas/v1/workspaces/*/policies/banking.allowedImportFormats', async route => {
    requested()
    await pending
    if (unavailable) return route.fulfill({status:503,json:{error:'policy_unavailable'}})
    return route.fulfill({status:200,json:{
      key:'banking.allowedImportFormats', effectiveValue:allowed,
      sourceScope:'workspace', allowedValues:allowed,
    }})
  })
  await page.goto(url)
  await policyRequested
  await expect(page.getByRole('heading',{name:'Extrato da conta',exact:true})).toBeVisible()
  const action = page.getByRole('button',{name:'Importar extrato',exact:true})
  try {
    await expect(action).toHaveCount(0)
  } finally {
    resolvePolicy()
  }
  await expect(action).toBeVisible()
  await action.click()
  const dialog = page.getByRole('dialog',{name:'Importar extrato bancário'})
  await expect(dialog).toBeVisible()
  await dialog.locator('#lf-import-format').click()
  // NcSelect can split accessible option names into highlighted spans.
  const formats = page.getByRole('option')
  await expect(formats).toHaveCount(1)
  await expect(formats.first()).toHaveText(/^CSV \(valores separados po\s*r vírgula\)$/)
  await formats.first().click()
  await expect(dialog.getByRole('button',{name:'Selecionar arquivo CSV'})).toBeVisible()
  await dialog.getByRole('button',{name:'Cancelar',exact:true}).click()
  await expect(dialog).toHaveCount(0)

  for (const denied of ['empty','unavailable']) {
    allowed = []
    unavailable = denied === 'unavailable'
    const response = page.waitForResponse(response =>
      response.url().includes('/policies/banking.allowedImportFormats'))
    await page.goto(url)
    await response
    await expect(page.getByRole('heading',{name:'Extrato da conta',exact:true})).toBeVisible()
    await expect(page.locator('.lf-bank-intro')).toBeVisible()
    await expect(action).toHaveCount(0)
    await expect(dialog).toHaveCount(0)
  }
})

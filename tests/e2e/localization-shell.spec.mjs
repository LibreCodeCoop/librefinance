// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { test, expect } from '@playwright/test'
import { login, makeSpace } from './helpers.mjs'

test('native language selection localizes shell without changing workspace or route identities', async ({ page }) => {
  await login(page)
  const name = await makeSpace(page)
  const id = new URL(page.url()).searchParams.get('spaceId')
  expect(id).toMatch(/^\d+$/)
  const route = '/index.php/apps/librefinance/?spaceId=' + id
  await page.goto(route + '&view=dashboard&forceLanguage=pt_BR')
  const pt = page.getByRole('navigation', { name: 'Navegação do LibreFinance' })
  await expect(pt).toBeVisible()
  await expect(pt.getByRole('link', { name: 'Visão geral', exact: true })).toBeVisible()
  await expect(page.locator('.lf-context-identity')).toContainText(name)

  await page.goto(route + '&view=dashboard&forceLanguage=en')
  const en = page.getByRole('navigation', { name: 'LibreFinance navigation' })
  await expect(en).toBeVisible()
  await expect(en.getByRole('link', { name: 'Overview', exact: true })).toBeVisible()
  await expect(page.locator('.lf-context-identity')).toContainText(name)
  const search = en.getByRole('searchbox', { name: 'Search the menu' })
  await search.fill('Accounts and banks')
  await expect(en.getByRole('link', { name: 'Accounts and banks', exact: true })).toBeVisible()
  await search.press('Enter')
  await expect.poll(() => new URL(page.url()).searchParams.get('view')).toBe('bankAccounts')
  await expect.poll(() => new URL(page.url()).searchParams.get('spaceId')).toBe(id)
  await expect(page.locator('.lf-context-identity')).toContainText(name)
  await page.goto(route + '&view=bankAccounts&forceLanguage=pt_BR')
  await expect(page.getByRole('navigation', { name: 'Navegação do LibreFinance' })).toBeVisible()
  await expect(page.locator('.lf-context-identity')).toContainText(name)
})


test('environment and invitation panels localize accessible actions without changing identifiers', async ({ page }) => {
  await login(page)
  await makeSpace(page, 'personal')
  const id = new URL(page.url()).searchParams.get('spaceId')
  const environment = { id: 'locale-environment', name: 'Projeto Sintético', state: 'enabled_pending_admin', canManage: true }
  const invitation = { id: 93, spaceId: 90021, recipientUid: 'lfmaintestA',
    role: 'auditor', status: 'invited', revision: 4, expiresAt: Math.floor(Date.now() / 1000) + 3600 }
  await page.route('**/apps/librefinance/api/saas/v1/environments', route =>
    route.fulfill({ status: 200, json: { environments: [environment], nextCursor: null } }))
  let accepted = false
  await page.route('**/apps/librefinance/api/saas/v1/invitations**', route =>
    route.fulfill({ status: 200, json: { invitations: [accepted
      ? { ...invitation, status: 'accepted', revision: 5 } : invitation], nextAfterId: null } }))
  await page.route('**/apps/librefinance/api/saas/v1/workspaces/90021/invitations/93/accept', route => {
    expect(route.request().method()).toBe('POST')
    expect(route.request().postDataJSON()).toEqual({ expectedRevision: 4 })
    accepted = true
    return route.fulfill({ status: 200, json: { ...invitation, status: 'accepted', revision: 5 } })
  })
  const url = '/index.php/apps/librefinance/?spaceId=' + id + '&view=spaces&forceLanguage='
  await page.goto(url + 'pt_BR')
  await expect(page.getByRole('region', { name: 'Ambientes LibreFinance' })).toContainText(environment.name)
  await expect(page.getByRole('button', { name: 'Ativar ambiente Projeto Sintético' })).toBeVisible()
  const pt = page.getByRole('region', { name: 'Convites financeiros recebidos' })
  await expect(pt).toContainText('Gestão 90021')
  await expect(pt.getByRole('button', { name: 'Aceitar convite da gestão 90021' })).toBeVisible()
  await page.goto(url + 'en')
  await expect(page.getByRole('region', { name: 'LibreFinance environments' })).toContainText(environment.name)
  await expect(page.getByRole('button', { name: 'Activate environment Projeto Sintético' })).toBeVisible()
  const en = page.getByRole('region', { name: 'Received financial invitations' })
  await expect(en).toContainText('Workspace 90021')
  await expect(en).toContainText('Awaiting acceptance')
  await en.getByRole('button', { name: 'Accept invitation for workspace 90021' }).click()
  await expect(en).toContainText('Accepted')
  expect(accepted).toBe(true)
  await page.goto(url + 'pt_BR')
  await expect(page.getByRole('region', { name: 'Convites financeiros recebidos' })).toContainText('Aceito')
})

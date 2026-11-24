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

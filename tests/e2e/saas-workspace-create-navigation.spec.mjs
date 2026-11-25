// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect } from '@playwright/test'
import { login, makeSpace, goToView } from './helpers.mjs'

test('a delayed workspace creation respects subsequent browser navigation', async ({ page }) => {
  await login(page)
  const originalName = await makeSpace(page, 'personal')
  await goToView(page, 'Visão geral')
  const original = new URL(page.url())
  await goToView(page, 'Gestões financeiras')
  await page.getByRole('button', { name: 'Nova gestão', exact: true }).click()
  const dialog = page.getByRole('dialog', { name: 'Criar gestão financeira' })
  const newName = 'Synthetic late creation ' + Date.now().toString(36)
  await dialog.getByRole('textbox', { name: 'Nome da gestão' }).fill(newName)
  await page.locator('#lf-space-kind-new').click()
  await page.getByRole('option').nth(2).click()

  let deliver
  let release
  const committed = new Promise(resolve => { deliver = resolve })
  const delayed = new Promise(resolve => { release = resolve })
  await page.route('**/apps/librefinance/api/spaces', async route => {
    if (route.request().method() !== 'POST') return route.continue()
    // Use the real server write; only delay delivery of its response.
    const response = await route.fetch()
    deliver(response.status())
    await delayed
    await route.fulfill({ response })
  })
  try {
    await dialog.getByRole('button', { name: 'Criar gestão independente', exact: true }).click()
    expect(await committed).toBe(201)
    await page.goBack()
    await expect(dialog).toBeHidden()
    await expect(page).toHaveURL(original.href)
    const refreshed = page.waitForResponse(response =>
      response.request().method() === 'GET' && response.url().endsWith('/api/spaces'))
    release()
    expect((await refreshed).status()).toBe(200)
    // Observe the new authorized listing, not just the completion of HTTP.
    await page.locator('#lf-space-select').click()
    await expect(page.getByRole('option').filter({ hasText: newName })).toBeVisible()
    await page.keyboard.press('Escape')
    await expect(page.locator('.lf-context-identity')).toContainText(originalName)
    await expect(page).toHaveURL(original.href)
    await expect(page.getByRole('dialog', { name: 'Cadastrar empresa' })).toHaveCount(0)
    if (process.env.LF_WORKSPACE_CREATE_REVIEW_CAPTURE === '1') {
      await page.locator('.lf-context-identity').screenshot({ path: 'test-results/workspace-create-navigation-history.png' })
    }
  } finally {
    release()
  }
})

test('a delayed workspace creation respects a cancelled and reopened dialog', async ({ page }) => {
  await login(page)
  const originalName = await makeSpace(page, 'personal')
  await goToView(page, 'Visão geral')
  await goToView(page, 'Gestões financeiras')
  const original = new URL(page.url())
  await page.getByRole('button', { name: 'Nova gestão', exact: true }).click()
  const dialog = page.getByRole('dialog', { name: 'Criar gestão financeira' })
  const newName = 'Synthetic late creation ' + Date.now().toString(36)
  await dialog.getByRole('textbox', { name: 'Nome da gestão' }).fill(newName)
  await page.locator('#lf-space-kind-new').click()
  await page.getByRole('option').nth(2).click()

  let deliver
  let release
  const committed = new Promise(resolve => { deliver = resolve })
  const delayed = new Promise(resolve => { release = resolve })
  await page.route('**/apps/librefinance/api/spaces', async route => {
    if (route.request().method() !== 'POST') return route.continue()
    // Use the real server write; only delay delivery of its response.
    const response = await route.fetch()
    deliver(response.status())
    await delayed
    await route.fulfill({ response })
  })
  try {
    await dialog.getByRole('button', { name: 'Criar gestão independente', exact: true }).click()
    expect(await committed).toBe(201)
    await dialog.getByRole('button', { name: 'Cancelar', exact: true }).click()
    await expect(dialog).toBeHidden()
    await page.getByRole('button', { name: 'Nova gestão', exact: true }).click()
    const nextDraft = dialog.getByRole('textbox', { name: 'Nome da gestão' })
    await nextDraft.fill('Synthetic next draft')
    await expect(page).toHaveURL(original.href)
    const refreshed = page.waitForResponse(response =>
      response.request().method() === 'GET' && response.url().endsWith('/api/spaces'))
    release()
    expect((await refreshed).status()).toBe(200)
    // The response belongs to the previous dialog, not to this draft.
    await expect(dialog).toBeVisible()
    await expect(nextDraft).toHaveValue('Synthetic next draft')
    await expect(dialog.getByRole('button', { name: 'Criar gestão independente', exact: true })).toBeEnabled()
    await dialog.getByRole('button', { name: 'Cancelar', exact: true }).click()
    await expect(page.getByRole('list', { name: 'Áreas de minha propriedade' })
      .getByText(newName, { exact: true })).toBeVisible()
    await expect(page.locator('.lf-context-identity')).toContainText(originalName)
    await expect(page).toHaveURL(original.href)
    await expect(page.getByRole('dialog', { name: 'Cadastrar empresa' })).toHaveCount(0)
    if (process.env.LF_WORKSPACE_CREATE_REVIEW_CAPTURE === '1') {
      await page.locator('.lf-context-identity').screenshot({ path: 'test-results/workspace-create-navigation-cancel.png' })
    }
  } finally {
    release()
  }
})

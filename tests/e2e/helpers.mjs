// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { expect } from '@playwright/test'

export function labIdentity() {
  const password = process.env.LF_TEST_USER_PASS
  if (!password) throw new Error('Missing LF_TEST_USER_PASS for synthetic Nextcloud lab')
  return { user: 'lfmaintestA', password }
}

export async function login(page) {
  const { user, password } = labIdentity()
  await page.goto('/index.php/login')
  await page.locator('#user').fill(user)
  await page.locator('#password').fill(password)
  await page.locator('button[type=submit]').first().click()
  await page.waitForURL(url => !url.pathname.endsWith('/login'), { timeout: 25_000 })
  const errors = []
  page.on('pageerror', error => errors.push(String(error.message).slice(0, 260)))
  page.on('console', message => {
    if (message.type() === 'error') errors.push(message.text().slice(0, 260))
  })
  const response = await page.goto('/index.php/apps/librefinance/')
  try {
    await expect(page.getByRole('heading', { name: 'Comece com seu espaço financeiro' })).toBeVisible({ timeout: 10_000 })
  } catch {
    // A previous synthetic lab test may have left spaces; use the stable shell.
    try {
      await expect(page.locator('.lf-product-name')).toHaveText('LibreFinance')
    } catch (cause) {
      const details = await page.evaluate(() => ({
        mount: Boolean(document.getElementById('librefinance-app')),
        nodes: document.getElementById('librefinance-app')?.childElementCount,
        title: document.title,
      }))
      console.error('LibreFinance UI boot diagnostics',
        JSON.stringify({ status: response?.status(), ...details, errors }))
      throw cause
    }
  }
}

export async function goToView(page, view) {
  const nav = page.getByRole('navigation', { name: 'Navegação do LibreFinance' })
  await expect(nav).toBeVisible()
  if (view === 'Migração de dados') {
    await nav.getByText('Configurações', { exact: true }).click()
    await page.getByRole('button', { name: 'Dados e migração' }).click()
    await page.getByRole('button', { name: 'Abrir migração de dados' }).click()
    return
  }
  const parent = ({
    'Regras tributárias': 'Tributação',
    'NFS-e Brasil': 'Tributação',
    'Clientes e fornecedores': 'Cadastros',
    'Produtos e serviços': 'Cadastros',
    'Categorias': 'Cadastros',
    'Empresas': 'Cadastros',
  })[view]
  const target = nav.getByText(view, { exact: true })
  if (parent) {
    // The submenu can be open but outside the scroll viewport after creating a
    // space using the footer action. Do not collapse it just because it is
    // scrolled out of view. Inspect the native Nextcloud aria-expanded state.
    const trigger = nav.getByRole('link', { name: parent, exact: true })
    // Module discovery and navigation remount asynchronously after a space
    // change. Let Playwright retry the current locator instead of retaining a
    // DOM element that may be detached during the module refresh.
    await expect(trigger).toBeVisible({ timeout: 20_000 })
    if ((await trigger.getAttribute('aria-expanded')) !== 'true') await trigger.click()
  }
  await target.click({ timeout: 20_000 })
}

export async function selectFinanceSpace(page, name) {
  const selection = page.locator('#lf-space-select')
  await selection.click()
  // NcSelect highlights labels across spans; DOM textContent is intact.
  await page.getByRole('option').filter({ hasText: name }).click()
  await expect(page.locator('.lf-context-identity')).toContainText(name)
}

export async function makeSpace(page, kind = 'organization') {
  const name = 'E2E ' + Date.now().toString(36) + Math.random().toString(36).slice(2, 8)
  await page.getByRole('button', { name: 'Nova gestão financeira' }).click()
  await expect(page.getByRole('dialog', { name: 'Criar gestão financeira' })).toBeVisible()
  await page.getByRole('textbox', { name: 'Nome desta gestão financeira' }).fill(name)
  await page.locator('#lf-new-space-kind').click()
  const index = ({ personal: 0, collective: 1, organization: 2 })[kind]
  // NcSelect's highlighted accessibility text may contain injected whitespace.
  await page.getByRole('option').nth(index).click()
  await page.getByRole('dialog', { name: 'Criar gestão financeira' })
    .getByRole('button', { name: 'Criar gestão' }).click()
  await expect(page.locator('.lf-context-identity')).toContainText(name)
  // Do not pass while a previous space is still being refreshed. Wait for the
  // actual module-discovery contract for the new active space to complete.
  await expect(page.locator('[data-testid="librefinance-workspace"]'))
    .toHaveAttribute('data-modules-loaded', 'true', { timeout: 20_000 })
  await expect(page.locator('.lf-loading')).toHaveCount(0)
  return name
}

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
    await goToView(page, 'Dados e migração')
    return
  }
  const parent = ({
    'Regras tributárias': 'Gerenciar',
    'NFS-e Brasil': 'Gerenciar',
    'Plano de contas': 'Gerenciar',
    'Lançamentos contábeis': 'Gerenciar',
    'Assistente financeiro': 'Gerenciar',
    'Clientes e fornecedores': 'Gerenciar',
    'Pessoas e estabelecimentos': 'Gerenciar',
    'Organizações (opcional)': 'Gerenciar',
    'Produtos e serviços': 'Gerenciar',
    'Categorias': 'Gerenciar',
    'Dados da organização': 'Gerenciar',
    'Gestões financeiras': 'Gerenciar',

    'Acessos e compartilhamento': 'Gerenciar',
    'Campos personalizados': 'Gerenciar',
    'Formas de pagamento': 'Gerenciar',
    'Recursos instalados': 'Gerenciar',
    'Dados e migração': 'Gerenciar',
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
  if (parent) {
    const subgroup = ({
      'Clientes e fornecedores':'Cadastros', 'Pessoas e estabelecimentos':'Cadastros',
      'Produtos e serviços':'Cadastros', 'Categorias':'Cadastros',
      'Dados da organização':'Gestões e identidade', 'Organizações (opcional)':'Gestões e identidade',
      'Gestões financeiras':'Gestões e identidade',
      'Plano de contas':'Financeiro e fiscal', 'Lançamentos contábeis':'Financeiro e fiscal',
      'Formas de pagamento':'Financeiro e fiscal',
      'Regras tributárias':'Financeiro e fiscal','NFS-e Brasil':'Financeiro e fiscal',
      'Acessos e compartilhamento':'Equipe e personalização',
      'Campos personalizados':'Equipe e personalização',
      'Recursos instalados':'Recursos e dados','Dados e migração':'Recursos e dados',
      'Assistente financeiro':'Recursos e dados',
    })[view]
    if (subgroup) {
      const link=nav.getByRole('link', { name: subgroup, exact:true })
      await expect(link).toBeVisible({timeout:20_000})
      if ((await link.getAttribute('aria-expanded')) !== 'true') await link.click()
    }
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
  await goToView(page, 'Gestões financeiras')
  await page.getByRole('button', { name: 'Nova gestão', exact: true }).click()
  await expect(page.getByRole('dialog', { name: 'Criar gestão financeira' })).toBeVisible()
  await page.getByRole('dialog', { name: 'Criar gestão financeira' })
    .getByRole('textbox', { name: 'Nome da gestão' }).fill(name)
  await page.locator('#lf-space-kind-new').click()
  const index = ({ personal: 0, collective: 1, organization: 2 })[kind]
  // NcSelect's highlighted accessibility text may contain injected whitespace.
  await page.getByRole('option').nth(index).click()
  await page.getByRole('dialog', { name: 'Criar gestão financeira' })
    .getByRole('button', { name: 'Criar gestão independente', exact: true }).click()
  await expect(page.locator('.lf-context-identity')).toContainText(name)
  // Do not pass while a previous space is still being refreshed. Wait for the
  // actual module-discovery contract for the new active space to complete.
  await expect(page.locator('[data-testid="librefinance-workspace"]'))
    .toHaveAttribute('data-modules-loaded', 'true', { timeout: 20_000 })
  await expect(page.locator('.lf-loading')).toHaveCount(0)
  if (kind === 'organization') {
    const onboarding = page.getByRole('dialog', { name: 'Cadastrar empresa' })
    await expect(onboarding).toBeVisible({timeout:20_000})
    await onboarding.getByRole('button', { name: 'Cancelar' }).click()
    await goToView(page, 'Visão geral')
  }
  return name
}

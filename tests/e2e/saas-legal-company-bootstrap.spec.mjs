// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { test, expect } from '@playwright/test'
import { login, makeSpace, goToView } from './helpers.mjs'

// The browser fixture verifies the native UI/JSON contract only.
// Durable transaction/ACL/idempotency checks run on installed Nextcloud
// in tests/integration/saas-access.php.
test('SaaS administrator creates an independent legal company on mobile in dark mode', async ({ page }) => {
  await login(page)
  await makeSpace(page, 'personal')
  const env = {
    id: 'synthetic-legal-environment', name: 'Ambiente A',
    state: 'active', canManage: true,
  }
  const rows = []
  const creates = []
  await page.route('**/apps/librefinance/api/saas/v1/environments', route => {
    return route.fulfill({ status: 200, json: { environments: [env], nextCursor: null } })
  })
  await page.route('**/apps/librefinance/api/saas/v1/environments/*/policies/effective', route => {
    return route.fulfill({ status: 200, json: { policies: [] } })
  })
  await page.route('**/apps/librefinance/api/saas/v1/environments/*/workspaces', route => {
    const request = route.request()
    if (request.method() === 'GET') {
      return route.fulfill({ status: 200, json: { workspaces: rows } })
    }
    if (request.method() === 'POST') {
      creates.push(request.postDataJSON())
      rows.push({ id: 901, name: 'Livro de empresa A', ownerUid: 'synthetic-owner',
        kind: 'organization' })
      return route.fulfill({ status: 201, json: {
        id: 901, companyId: 902, name: 'Livro de empresa A',
        ownerUid: 'synthetic-owner', kind: 'organization',
        environmentId: env.id,
      } })
    }
    return route.continue()
  })
  await goToView(page, 'Gestões financeiras')
  // Navigate while the desktop sidebar is visible, then verify the actual
  // form and confirmation on a mobile viewport in dark mode.
  await page.setViewportSize({ width: 390, height: 844 })
  await page.emulateMedia({ colorScheme: 'dark' })
  await expect(page.getByRole('heading', { name: 'Gerenciar gestões por ambiente' })).toBeVisible()
  const panel = page.locator('.lf-saas-workspaces')
  await expect(panel).toBeVisible()
  await panel.getByRole('textbox', { name: 'Nome da gestão' }).fill('Livro de empresa A')
  await panel.getByRole('textbox', { name: 'Usuário Nextcloud do proprietário' })
    .fill('synthetic-owner')
  // NcCheckboxRadioSwitch uses a visible label over its native input;
  // interact with the label as a keyboard/mouse user would, not check()
  // on the covered input element.
  await panel.getByText('Cadastrar empresa jurídica junto com o novo livro').click()
  await expect(panel.getByRole('checkbox', {
    name: 'Cadastrar empresa jurídica junto com o novo livro',
  })).toBeChecked()
  await panel.getByRole('textbox', { name: 'Razão social' }).fill('Empresa Sintética A')
  await panel.getByRole('textbox', { name: 'País da empresa (ISO 3166-1, duas letras)' })
    .fill('PT')
  await panel.getByRole('button', { name: 'Revisar criação' }).click()
  const dialog = page.getByRole('dialog', { name: 'Confirmar gestão financeira independente' })
  await expect(dialog).toContainText('Empresa Sintética A (PT)')
  await dialog.getByRole('button', { name: 'Criar empresa e gestão' }).click()
  await expect(panel.getByRole('list', { name: 'Gestões administrativas do ambiente' }))
    .toContainText('Livro de empresa A')
  expect(creates).toHaveLength(1)
  expect(creates[0]).toMatchObject({
    name: 'Livro de empresa A', ownerUid: 'synthetic-owner',
    kind: 'organization', legalName: 'Empresa Sintética A',
    companyCountry: 'PT', companyTaxId: null,
  })
  expect(creates[0].idempotencyKey).toMatch(/^[A-Za-z0-9_-]{8,64}$/)
})

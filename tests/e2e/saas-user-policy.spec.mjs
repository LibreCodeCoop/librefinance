// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { test, expect } from '@playwright/test'
import { login, makeSpace, goToView } from './helpers.mjs'

test('authenticated personal policy hides financial dashboard KPI cards', async ({ page }) => {
  await login(page)
  await makeSpace(page, 'personal')
  // Mark only UI fixture metadata; PHP integration checks real Nextcloud
  // membership, scope, parent denial and revision in saas-access.php.
  await page.route('**/apps/librefinance/api/spaces', async route => {
    if (route.request().method() !== 'GET') return route.continue()
    const response = await route.fetch()
    const data = await response.json()
    return route.fulfill({ response, json: {
      ...data, spaces: (data.spaces || []).map(s => ({ ...s, saasManaged: true })),
    } })
  })
  let userSetting = true
  let revision = 0
  let inherited = true
  const current = () => ({
    key: 'dashboard.kpis.enabled', type: 'boolean', effectiveValue: userSetting,
    sourceScope: inherited ? 'default' : 'user', blockedBy: userSetting ? null : 'user',
    revision, inherited, editableByCurrentActor: true,
    supportedScopes: ['instance', 'environment', 'workspace', 'user'],
    allowedValues: [false, true],
  })
  await page.route('**/apps/librefinance/api/saas/v1/workspaces/*/policies/me/effective', route => {
    return route.fulfill({ status: 200, json: { policies: [current()] } })
  })
  await page.route('**/apps/librefinance/api/saas/v1/workspaces/*/policies/me/dashboard.kpis.enabled**', route => {
    const request = route.request()
    if (request.method() === 'GET') {
      return route.fulfill({ status: 200, json: current() })
    }
    if (request.method() === 'PUT') {
      const body = request.postDataJSON()
      expect(body).toEqual({ valueJson: 'false', expectedRevision: 0 })
      userSetting = false
      inherited = false
      revision = 1
      return route.fulfill({ status: 200, json: current() })
    }
    if (request.method() === 'DELETE') {
      expect(new URL(request.url()).searchParams.get('expectedRevision')).toBe('1')
      userSetting = true
      inherited = true
      revision = 2
      return route.fulfill({ status: 200, json: current() })
    }
    return route.continue()
  })
  await page.reload()
  await goToView(page, 'Gestões financeiras')
  const panel = page.getByRole('region', { name: 'Políticas SaaS de user' })
  await expect(panel).toContainText('Exibir indicadores resumidos no painel')
  const toggle = panel.getByRole('switch', { name: /Permitir exibir indicadores resumidos no painel/i })
  await toggle.focus()
  await toggle.press('Space')
  await expect(toggle).not.toBeChecked()
  await panel.getByRole('button', { name: 'Salvar restrição Exibir indicadores resumidos no painel' }).click()
  await expect(panel).toContainText('Efetivo: Bloqueado')
  await goToView(page, 'Visão geral')
  await expect(page.locator('.lf-overview-kpis')).toHaveCount(0)
  await expect(page.getByText('Indicadores resumidos ocultos por política de apresentação.')).toBeVisible()
  await goToView(page, 'Gestões financeiras')
  await panel.getByRole('button', { name: 'Herdar política Exibir indicadores resumidos no painel' }).click()
  await expect(panel).toContainText('Efetivo: Permitido')
  await goToView(page, 'Visão geral')
  await expect(page.locator('.lf-overview-kpis')).toBeVisible()
})

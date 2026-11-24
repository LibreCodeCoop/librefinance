// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { test, expect } from '@playwright/test'
import { login, makeSpace, goToView, selectFinanceSpace, openFinanceAction } from './helpers.mjs'

test('Nextcloud official navigation and dashboard load without iframe', async ({ page }) => {
  await login(page)
  await expect(page.locator('.lf-product-name')).toHaveText('LibreFinance')
  await expect(page.getByRole('navigation', { name: 'Navegação do LibreFinance' })).toBeVisible()
  await expect(page.locator('iframe')).toHaveCount(0)
  // With no financial book, the first-use action must still open SaaS
  // management. A financial context is not a prerequisite for this action.
  const management = page.getByRole('link', { name: 'Gerenciar', exact: true })
  const firstRun = page.getByRole('button', { name: 'Gerenciar ou criar gestões', exact: true })
  await expect(management.or(firstRun).first()).toBeVisible()
})

test('new SaaS administrator can open environment setup without creating a ledger', async ({ page }) => {
  let createdBooks = 0
  await page.route('**/apps/librefinance/api/spaces*', route => {
    const { pathname } = new URL(route.request().url())
    if (!pathname.endsWith('/api/spaces')) return route.continue()
    if (route.request().method() === 'POST') ++createdBooks
    if (route.request().method() === 'GET') {
      return route.fulfill({ status: 200, json: { spaces: [] } })
    }
    return route.continue()
  })
  await page.route('**/apps/librefinance/api/saas/v1/environments*', route => {
    const { pathname } = new URL(route.request().url())
    if (route.request().method() !== 'GET'
      || !pathname.endsWith('/api/saas/v1/environments')) return route.continue()
    return route.fulfill({ status: 200, json: { environments: [], nextCursor: null } })
  })
  await login(page)
  const entry = page.getByRole('button', { name: 'Gerenciar ou criar gestões', exact: true })
  await expect(entry).toBeVisible()
  await entry.click()
  await expect(page.getByRole('heading', { name: 'Gestões financeiras' })).toBeVisible()
  await expect(page.getByRole('heading', { name: 'Ambientes LibreFinance' })).toBeVisible()
  await expect(page.getByText('Nenhum ambiente SaaS está disponível para esta conta.')).toBeVisible()
  expect(createdBooks).toBe(0)
})

test('eligible group shows suspended SaaS environment without financial grants', async ({ page }) => {
  await login(page)
  // Use a real synthetic financial book to test the suspended-tenant view.
  await makeSpace(page, 'personal')
  await goToView(page, 'Gestões financeiras')
  await expect(page.getByRole('heading', { name: 'Ambientes LibreFinance' })).toBeVisible()
  const group = page.getByRole('list', { name: 'Ambientes SaaS disponíveis' })
    .getByRole('listitem').filter({ hasText: 'lf_synth_finance_group' })
  await expect(group).toContainText('Estado: Suspenso')
  await expect(group.getByRole('button', { name: /Ativar ambiente/ })).toHaveCount(0)
})

test('SaaS environment panel explains an empty enabled-group list after loading', async ({ page }) => {
  await login(page)
  await makeSpace(page, 'personal')
  await page.route('**/apps/librefinance/api/saas/v1/environments', route => {
    if (route.request().method() !== 'GET') return route.continue()
    return route.fulfill({ status: 200, json: { environments: [], nextCursor: null } })
  })
  await goToView(page, 'Gestões financeiras')
  await expect(page.getByRole('heading', { name: 'Ambientes LibreFinance' })).toBeVisible()
  await expect(page.getByText('Nenhum ambiente SaaS está disponível para esta conta.')).toBeVisible()
  await expect(page.getByText('As gestões pessoais existentes continuam independentes.')).toBeVisible()
  await expect(page.getByRole('list', { name: 'Ambientes SaaS disponíveis' })).toHaveCount(0)
  await expect(page.getByRole('button', { name: 'Ativar ambiente' })).toHaveCount(0)
})

test('SaaS administration chooses one environment and retries creation idempotently', async ({ page }) => {
  await login(page)
  await makeSpace(page, 'personal')
  const environments = [
    { id: 'tenant-test-A', name: 'Ambiente A', state: 'active', canManage: true },
    { id: 'tenant-test-B', name: 'Ambiente B', state: 'active', canManage: true },
  ]
  const workspaces = {
    'tenant-test-A': [{ id: 41001, name: 'Gestão A', ownerUid: 'ownerA', kind: 'organization' }],
    'tenant-test-B': [{ id: 41002, name: 'Gestão B', ownerUid: 'ownerB', kind: 'collective' }],
  }
  const attemptedKeys = []
  // Real Nextcloud UI; deterministic server-owned responses for visual context
  // switching only. The real PHP permission matrix is exercised separately.
  await page.route('**/apps/librefinance/api/saas/v1/environments', route => {
    if (route.request().method() !== 'GET') return route.continue()
    return route.fulfill({ status: 200, json: { environments } })
  })
  await page.route('**/apps/librefinance/api/saas/v1/environments/*/workspaces', async route => {
    const url = new URL(route.request().url())
    const id = url.pathname.split('/').at(-2)
    if (route.request().method() === 'GET') {
      return route.fulfill({ status: 200, json: { workspaces: workspaces[id] || [] } })
    }
    const data = route.request().postDataJSON()
    attemptedKeys.push(data.idempotencyKey)
    expect(id).toBe('tenant-test-A')
    expect(data.ownerUid).toBe('ownerA')
    expect(data.name).toBe('Nova gestão A')
    if (attemptedKeys.length === 1) return route.abort('failed')
    const created = {
      id: 41003, name: data.name, ownerUid: data.ownerUid, kind: data.kind,
    }
    workspaces[id].push(created)
    return route.fulfill({ status: 201, json: created })
  })

  await goToView(page, 'Gestões financeiras')
  await expect(page.getByText('Se você administra vários grupos')).toBeVisible()
  await expect(page.getByRole('list', { name: 'Gestões administrativas do ambiente' })).toHaveCount(0)
  const selection = page.locator('#lf-saas-selected-environment')
  await selection.click()
  // NcSelect splits long visual labels across spans; use the full title
  // inside its semantic option instead of a fragmented accessible name.
  await page.getByRole('option')
    .filter({ has: page.getByTitle('Ambiente A', { exact: true }) }).click()
  const list = page.getByRole('list', { name: 'Gestões administrativas do ambiente' })
  await expect(list).toContainText('Gestão A')
  await expect(list).not.toContainText('Gestão B')
  await page.getByRole('textbox', { name: 'Nome da gestão' }).last().fill('Nova gestão A')
  await page.getByRole('textbox', { name: 'Usuário Nextcloud do proprietário' }).fill('ownerA')
  await page.getByRole('button', { name: 'Revisar criação' }).click()
  await expect(page.getByRole('dialog', { name: 'Confirmar gestão financeira independente' })).toBeVisible()
  const confirm = page.getByRole('dialog', { name: 'Confirmar gestão financeira independente' })
  await confirm.getByRole('button', { name: 'Criar gestão independente' }).click()
  await expect(confirm.getByRole('alert')).toBeVisible()
  await confirm.getByRole('button', { name: 'Criar gestão independente' }).click()
  await expect(list).toContainText('Nova gestão A')
  expect(attemptedKeys).toHaveLength(2)
  expect(attemptedKeys[0]).toBe(attemptedKeys[1])

  await selection.click()
  await page.getByRole('option')
    .filter({ has: page.getByTitle('Ambiente B', { exact: true }) }).click()
  await expect(list).toContainText('Gestão B')
  await expect(list).not.toContainText('Gestão A')
  await expect(list).not.toContainText('Nova gestão A')
})

test('SaaS workspace policy editor restricts, audits and restores inheritance', async ({ page }) => {
  await login(page)
  await makeSpace(page, 'personal')
  // The synthetic owner already has a real disposable Nextcloud book; mark
  // its listing as a SaaS fixture for this UI-only test. Real markers and
  // access restrictions are asserted in PHP integration tests.
  await page.route('**/apps/librefinance/api/spaces', async route => {
    if (route.request().method() !== 'GET') return route.continue()
    const response = await route.fetch()
    const data = await response.json()
    return route.fulfill({
      response, json: { ...data, spaces: (data.spaces || [])
        .map(space => ({ ...space, saasManaged: true })) },
    })
  })
  await page.reload()
  let row = {
    key: 'imports.enabled', type: 'boolean', effectiveValue: true,
    sourceScope: 'instance', blockedBy: null, revision: 0,
    inherited: true, editableByCurrentActor: true, allowedValues: [false, true],
  }
  const changes = []
  // Only view metadata is mocked here; authorization, audit and persistence
  // are verified separately by the real PHP integration suite.
  await page.route('**/apps/librefinance/api/saas/v1/workspaces/*/policies/effective', route => {
    return route.fulfill({ status: 200, json: { policies: [row] } })
  })
  // The DELETE request carries expectedRevision in the query string.
  await page.route('**/apps/librefinance/api/saas/v1/workspaces/*/policies/imports.enabled**', route => {
    if (route.request().method() === 'PUT') {
      const sent = route.request().postDataJSON()
      changes.push({ action: 'set', ...sent })
      expect(sent.expectedRevision).toBe(0)
      expect(sent.valueJson).toBe('false')
      row = { ...row, effectiveValue: false, sourceScope: 'workspace',
        blockedBy: 'workspace', revision: 1, inherited: false,
        allowedValues: [false, true] }
      return route.fulfill({ status: 200, json: row })
    }
    if (route.request().method() === 'DELETE') {
      const url = new URL(route.request().url())
      changes.push({ action: 'clear', expectedRevision: Number(url.searchParams.get('expectedRevision')) })
      expect(url.searchParams.get('expectedRevision')).toBe('1')
      row = { ...row, effectiveValue: true, sourceScope: 'instance',
        blockedBy: null, revision: 2, inherited: true }
      return route.fulfill({ status: 200, json: row })
    }
    return route.continue()
  })

  await goToView(page, 'Gestões financeiras')
  await page.getByRole('button', { name: 'Configurar políticas' }).click()
  const editor = page.getByRole('region', { name: 'Políticas SaaS de Gestão' })
  await expect(editor).toContainText('Origem: Instância Nextcloud')
  await expect(editor).toContainText('Efetivo: Permitido')
  const importSwitch = editor.getByRole('switch', { name: 'Permitir importação de dados' })
  await importSwitch.focus()
  await importSwitch.press('Space')
  await expect(importSwitch).not.toBeChecked()
  await editor.getByRole('button', { name: 'Salvar restrição Importação de dados' }).click()
  await expect(editor).toContainText('Efetivo: Bloqueado')
  await expect(editor).toContainText('Origem: Gestão')
  await expect(editor).toContainText('Revisão: 1')
  await editor.getByRole('button', { name: 'Herdar política Importação de dados' }).click()
  await expect(editor).toContainText('Efetivo: Permitido')
  await expect(editor).toContainText('Revisão: 2')
  expect(changes).toEqual([
    { action: 'set', valueJson: 'false', expectedRevision: 0 },
    { action: 'clear', expectedRevision: 1 },
  ])
})

test('creating organization displays a real independent chart of accounts', async ({ page }) => {
  await login(page)
  const name = await makeSpace(page)
  await expect(page.locator('.lf-context-identity')).toContainText(name)
  await goToView(page, 'Plano de contas')
  await expect(page.getByRole('heading', { name: 'Plano de contas' })).toBeVisible()
  await expect(page.locator('.lf-data-table tbody tr')).toHaveCount(4)
})

test('secondary API failure preserves chart and identifies missing dataset', async ({ page }) => {
  await login(page)
  await page.route('**/index.php/apps/librefinance/api/spaces/*/items',
    route => route.abort('failed'))
  await makeSpace(page)
  await goToView(page, 'Plano de contas')
  await expect(page.locator('.lf-data-table tbody tr')).toHaveCount(4)
  await expect(page.getByRole('alert')).toContainText('items')
  await page.unroute('**/index.php/apps/librefinance/api/spaces/*/items')
  await page.getByRole('button', { name: 'Atualizar dados' }).click()
  await expect(page.getByRole('alert')).toHaveCount(0)
  await expect(page.locator('.lf-data-table tbody tr')).toHaveCount(4)
})

test('reporting works inside the core without its former standalone app', async ({ page }) => {
  await login(page)
  await makeSpace(page)
  const spaceId = new URL(page.url()).searchParams.get('spaceId')
  expect(Number(spaceId)).toBeGreaterThan(0)
  await goToView(page, 'Relatórios')
  await expect(page.getByRole('heading', { name: 'Relatórios', exact: true })).toBeVisible()
  const response = await page.request.get('/index.php/apps/librefinance/api/spaces/'
    + spaceId + '/profit-loss?from=2026-01-01&through=2026-12-31', {
    headers: { 'OCS-APIREQUEST': 'true' },
  })
  expect(response.status()).toBe(200)
})

test('@extensions old settings bookmarks open the direct management function', async ({ page }) => {
  await login(page)
  await makeSpace(page, 'organization')
  const id = new URL(page.url()).searchParams.get('spaceId')
  const base = '/index.php/apps/librefinance/?spaceId=' + id
  for (const [legacy, title] of [
    ['access', 'Acessos e compartilhamento'],
    ['fields', 'Campos personalizados'],
    ['payments', 'Formas de pagamento'],
    ['modules', 'Recursos instalados'],
    ['data', 'Migração e conferência'],
  ]) {
    await page.goto(base + '&view=settings&section=' + legacy)
    await expect(page.locator('.lf-page-header').getByRole('heading', { name: title, exact: true })).toBeVisible()
    await expect(page.locator('.lf-settings-tabs')).toHaveCount(0)
  }
})

test('@extensions manage owned workspaces and hide the select when only one remains', async ({ page }) => {
  await login(page)
  await makeSpace(page,'organization')
  const second=await makeSpace(page,'personal')
  await expect(page.locator('#lf-space-select')).toBeVisible()
  // Other tests share the synthetic account. Simulate a precisely scoped
  // one-workspace response instead of assuming a fresh, empty account.
  await page.route('**/apps/librefinance/api/spaces', async route => {
    const response = await route.fetch()
    const data = await response.json()
    const selected = data.spaces.find(space => space.name === second)
    if (!selected) throw new Error('Synthetic workspace missing from list')
    await route.fulfill({ response, json: { ...data, spaces: [selected] } })
  })
  await page.reload()
  await expect(page.locator('#lf-space-select')).toHaveCount(0)
  await expect(page.getByLabel('Gestão financeira atual')).toContainText(second)
  await page.unroute('**/apps/librefinance/api/spaces')
  await page.reload()
  await expect(page.locator('#lf-space-select')).toBeVisible()
  await goToView(page,'Gestões financeiras')
  const row=page.locator('.lf-native-space-list > li').filter({hasText:second})
  await row.hover()
  await row.getByRole('button',{name:'Ações da gestão '+second}).click()
  await page.getByRole('menuitem',{name:'Editar nome e cor'}).click()
  const rename=page.getByRole('dialog',{name:'Editar gestão financeira'})
  await rename.getByRole('textbox',{name:'Nome da gestão'}).fill('Revised finance workspace')
  await rename.getByRole('button',{name:'Salvar nome'}).click()
  const edited=page.locator('.lf-native-space-list > li')
    .filter({hasText:'Revised finance workspace'})
  await expect(edited).toBeVisible()
  await edited.hover()
  await edited.getByRole('button',{name:'Ações da gestão Revised finance workspace'}).click()
  await page.getByRole('menuitem',{name:'Arquivar'}).click()
  const archive=page.getByRole('dialog',{name:'Arquivar gestão financeira'})
  await expect(archive.getByText('lançamentos e auditoria serão preservados',
    {exact:false})).toBeVisible()
  await archive.getByRole('button',{name:'Arquivar gestão'}).click()
  // The shared user still has unrelated synthetic workspaces, so the
  // selector must remain visible after archival of this one.
  await expect(page.locator('#lf-space-select')).toBeVisible()
  await goToView(page,'Gestões financeiras')
  const archived=page.locator('.lf-native-space-list > li')
    .filter({hasText:'Revised finance workspace'})
  await archived.hover()
  await archived.getByRole('button',{name:'Ações da gestão arquivada Revised finance workspace'}).click()
  await page.getByRole('menuitem',{name:'Restaurar'}).click()
  await expect(page.locator('#lf-space-select')).toBeVisible()
  // Wait for the active and archived lists to finish refreshing in sequence.
  await expect(page.getByRole('list',{name:'Gestões financeiras arquivadas'})
    .getByText('Revised finance workspace')).toHaveCount(0)
  await expect(page.getByRole('list',{name:'Áreas de minha propriedade'})
    .getByText('Revised finance workspace')).toBeVisible()
})

test('browser history restores finance views without creating new history entries', async ({ page }) => {
  await login(page)
  await makeSpace(page)
  await goToView(page, 'Plano de contas')
  await expect(page.getByRole('heading', { name: 'Plano de contas' })).toBeVisible()
  await expect(page.getByRole('navigation', { name: 'Navegação do LibreFinance' })
    .getByRole('link', { name: 'Plano de contas' })).toHaveAttribute('href', /[?&]view=accounts/)
  await goToView(page, 'Relatórios')
  await expect(page.getByRole('heading', { name: 'Relatórios', exact: true })).toBeVisible()
  await page.goBack()
  await expect(page.getByRole('heading', { name: 'Plano de contas' })).toBeVisible()
  await page.goForward()
  await expect(page.getByRole('heading', { name: 'Relatórios', exact: true })).toBeVisible()
})

test('keyboard menu search opens the unique authorized page without inventing browser routes', async ({ page }) => {
  await login(page)
  await makeSpace(page)
  const nav = page.getByRole('navigation', { name: 'Navegação do LibreFinance' })
  const search = nav.getByRole('searchbox', { name: 'Buscar no menu' })
  await search.fill('relatórios')
  await expect(nav.getByRole('link', { name: 'Relatórios' })).toBeVisible()
  await search.press('Enter')
  await expect(page.getByRole('heading', { name: 'Relatórios', exact: true })).toBeVisible()
  await expect(search).toHaveValue('')
  await expect(page).toHaveURL(/[?&]view=reports/)
})

test('finance entry fields are nonoverlapping on desktop and stacked on mobile', async ({ page }) => {
  await login(page)
  await makeSpace(page, 'personal')
  await page.getByRole('button', { name: 'Registrar primeira despesa' }).click()
  const entry = page.getByRole('dialog', { name: 'Registrar entrada ou saída' })
  await expect(entry).toBeVisible()
  const fields = entry.locator('.lf-form-grid > .lf-field')
  const desktop = await fields.first().evaluate(el => ({
    first: el.getBoundingClientRect().toJSON(),
    next: el.nextElementSibling?.getBoundingClientRect().toJSON(),
  }))
  expect(desktop.first.right).toBeLessThanOrEqual(desktop.next.left + 2)
  await page.setViewportSize({ width: 390, height: 844 })
  await expect(entry).toBeVisible()
  const mobile = await fields.first().evaluate(el => ({
    first: el.getBoundingClientRect().toJSON(),
    next: el.nextElementSibling?.getBoundingClientRect().toJSON(),
  }))
  expect(mobile.first.bottom).toBeLessThanOrEqual(mobile.next.top + 2)
  expect(mobile.first.width).toBeGreaterThan(170)
  await expect(page.locator('.modal-container')).toBeVisible()
  const surfaceColor = await page.locator('.modal-container').evaluate(el => getComputedStyle(el).backgroundColor)
  expect(surfaceColor).not.toBe('rgba(0, 0, 0, 0)')
  await page.screenshot({ path: '/tmp/librefinance-entry-dialog-mobile-390.png', animations: 'disabled' })
})

test('company form opens in official dialog, saves and edits without cross-space leakage', async ({ page }) => {
  await login(page)
  const first = await makeSpace(page)
  await goToView(page, 'Dados da organização')
  await page.getByRole('button', { name: 'Cadastrar empresa' }).click()
  const modal = page.getByRole('dialog', { name: 'Cadastrar empresa' })
  await expect(modal).toBeVisible()
  await modal.getByRole('textbox', { name: 'Nome legal' }).fill('Synthetic Cooperative')
  await modal.locator('#lf-company-country').click()
  await page.getByRole('option').filter({ hasText: 'Brasil (BR)' }).click()
  await modal.getByText('Outras informações (opcionais)').click()
  await modal.getByRole('textbox', { name: 'Identificador fiscal local' }).fill('11223344000181')
  await modal.getByRole('button', { name: 'Salvar empresa' }).click()
  await expect(page.getByRole('row', { name: /Synthetic Cooperative/ })).toBeVisible()
  await page.getByRole('button', { name: 'Editar Synthetic Cooperative' }).click()
  const editing = page.getByRole('dialog', { name: 'Editar empresa' })
  await editing.getByText('Outras informações (opcionais)').click()
  await editing.getByRole('textbox', { name: 'Nome comercial' }).fill('Nova identidade')
  await editing.getByRole('button', { name: 'Salvar empresa' }).click()
  await expect(page.getByRole('row', { name: /Synthetic Cooperative/ }))
    .toContainText('Nova identidade')
  const second = await makeSpace(page)
  await goToView(page, 'Dados da organização')
  await expect(page.getByText('Synthetic Cooperative')).toHaveCount(0)
  await selectFinanceSpace(page, first)
  await goToView(page, 'Dados da organização')
  await expect(page.getByRole('row', { name: /Synthetic Cooperative/ })).toBeVisible()
  expect(first).not.toBe(second)
})

test('@extensions user saves, edits, and archives report models for their financial space', async ({ page }) => {
  await login(page)
  await makeSpace(page, 'organization')
  await goToView(page, 'Relatórios')
  await page.getByRole('button', { name: 'Novo relatório', exact: true }).click()
  const editor = page.getByRole('dialog', { name: 'Novo relatório' })
  await editor.getByRole('textbox', { name: 'Nome do relatório' }).fill('Monthly cooperative result')
  await editor.getByRole('button', { name: 'Salvar relatório' }).click()
  await expect(editor).toHaveCount(0)
  await expect(page.getByRole('heading', { name: 'Monthly cooperative result' })).toBeVisible()
  await page.getByRole('button', { name: 'Editar', exact: true }).click()
  const editing = page.getByRole('dialog', { name: 'Editar relatório' })
  await editing.getByRole('textbox', { name: 'Nome do relatório' }).fill('Revised cooperative result')
  await editing.getByRole('button', { name: 'Salvar relatório' }).click()
  await expect(page.getByRole('heading', { name: 'Revised cooperative result' })).toBeVisible()
  await page.reload()
  await expect(page.getByRole('button', { name: /Revised cooperative result/ })).toBeVisible()
  await page.getByRole('button', { name: /Revised cooperative result/ }).click()
  await page.getByRole('button', { name: 'Arquivar', exact: true }).click()
  const archive = page.getByRole('dialog', { name: 'Arquivar relatório' })
  await archive.getByRole('button', { name: 'Arquivar modelo' }).click()
  await expect(page.getByRole('button', { name: /Revised cooperative result/ })).toHaveCount(0)
  await expect(page.getByRole('heading', { name: 'Resultado do período' })).toBeVisible()
})

test('@extensions international company form supports persistent typed fields', async ({ page }) => {
  await login(page)
  await makeSpace(page)
  await goToView(page, 'Dados da organização')
  await page.getByRole('button', { name: 'Personalizar campos de empresas' }).click()
  await page.getByRole('button', { name: 'Novo campo' }).click()
  const form = page.getByRole('dialog', { name: 'Novo campo personalizado' })
  await form.getByRole('group', { name: 'Escolher local do campo' })
    .getByRole('button', { name: 'Empresa / organização' }).click()
  await form.getByRole('textbox', { name: 'Nome do campo' }).fill('Local registration')
  await form.locator('summary').filter({ hasText: 'Identificador técnico' }).click()
  await form.getByRole('textbox', { name: 'Chave para integrações' }).fill('local_registry')
  await form.getByText('Obrigatório ao salvar').click()
  await expect(form.getByRole('checkbox', { name: 'Obrigatório ao salvar' })).toBeChecked()
  await form.getByRole('button', { name: 'Salvar campo' }).click()
  await expect(page.getByText('Local registration')).toBeVisible()
  await goToView(page, 'Dados da organização')
  await page.getByRole('button', { name: 'Cadastrar empresa' }).click()
  const entity = page.getByRole('dialog', { name: 'Cadastrar empresa' })
  await entity.getByRole('textbox', { name: 'Nome legal' }).fill('Synthetic Portugal Ltd')
  await entity.locator('#lf-company-country').click()
  await page.getByRole('option').filter({ hasText: 'Portugal (PT)' }).click()
  await entity.getByRole('textbox', { name: 'Local registration' }).fill('LIS-ABC-123')
  await entity.getByRole('button', { name: 'Salvar empresa' }).click()
  await expect(page.getByRole('row', { name: /Synthetic Portugal Ltd/ })).toBeVisible()
  await page.reload()
  await expect(page.getByRole('heading', { name: 'Dados da organização', exact: true })).toBeVisible()
  await expect(page.getByRole('navigation', { name: 'Navegação do LibreFinance' })
    .getByRole('link', { name: 'Gerenciar' })).toHaveAttribute('aria-expanded', 'true')
  await goToView(page, 'Dados da organização')
  await page.getByRole('button', { name: 'Editar Synthetic Portugal Ltd' }).click()
  const edit = page.getByRole('dialog', { name: 'Editar empresa' })
  await expect(edit.getByRole('textbox', { name: 'Local registration' })).toHaveValue('LIS-ABC-123')
  // NcSelect leaves its searchable input empty and renders the selection as a chip.
  await expect(edit.getByText('Portugal (PT)', { exact: true })).toBeVisible()
})

test('journal direction chooses expense or revenue without a dropdown', async ({ page }) => {
  await login(page)
  await makeSpace(page)
  await goToView(page, 'Lançamentos contábeis')
  await page.getByRole('button', { name: 'Registrar entrada ou saída', exact: true }).click()
  const dialog = page.getByRole('dialog', { name: 'Registrar entrada ou saída' })
  await expect(dialog.locator('#lf-journal-type')).toHaveCount(0)
  const expense = dialog.getByRole('button', { name: /Despesa Dinheiro saiu/ })
  const income = dialog.getByRole('button', { name: /Receita Dinheiro entrou/ })
  await expect(expense).toHaveAttribute('aria-pressed', 'true')
  await income.click()
  await expect(income).toHaveAttribute('aria-pressed', 'true')
  await expect(dialog.getByText('receita recebida')).toBeVisible()
  await expense.click()
  await expect(dialog.getByText('despesa paga')).toBeVisible()
})

test('categories are created using Nextcloud dialog controls and are space scoped', async ({ page }) => {
  await login(page)
  await makeSpace(page)
  await goToView(page, 'Categorias')
  await page.getByRole('button', { name: 'Nova categoria' }).click()
  const dialog = page.getByRole('dialog', { name: 'Cadastrar categoria' })
  const code = 'E2E_' + Math.random().toString(36).slice(2, 8).toUpperCase()
  await dialog.getByRole('textbox', { name: 'Código', exact: true }).fill(code)
  await dialog.getByRole('textbox', { name: 'Nome da categoria' })
    .fill('Synthetic categorization')
  await dialog.locator('#lf-category-account').click()
  await page.getByRole('option').last().click()
  await dialog.getByRole('button', { name: 'Salvar categoria' }).click()
  await expect(page.getByRole('row', { name: new RegExp(code) })).toBeVisible()
})

test('rapid switching between two finance spaces discards stale visual state', async ({ page }) => {
  await login(page)
  const first = await makeSpace(page)
  const second = await makeSpace(page)
  await goToView(page, 'Plano de contas')
  await selectFinanceSpace(page, first)
  await selectFinanceSpace(page, second)
  await goToView(page, 'Plano de contas')
  await expect(page.locator('.lf-context-identity')).toContainText(second)
  await expect(page.locator('.lf-data-table tbody tr')).toHaveCount(4)
  await expect(page.getByRole('alert')).toHaveCount(0)
})

test('@extensions report data renders in the same Nextcloud page with authorized totals', async ({ page }) => {
  await login(page)
  await makeSpace(page)
  await goToView(page, 'Relatórios')
  await expect(page.getByRole('heading', { name: 'Relatórios', exact: true })).toBeVisible()
  await expect(page.getByRole('heading', { name: 'Resultado do período' })).toBeVisible()
  await page.getByRole('button', { name: /Fluxo de caixa realizado/ }).click()
  await expect(page.getByText('Saldo final', { exact: true })).toBeVisible()
  await page.getByRole('button', { name: /Balancete/ }).click()
  await expect(page.getByRole('heading', { name: 'Balancete' })).toBeVisible()
  const accounts = page.locator('.lf-report-preview .lf-data-table tbody tr')
  await expect(accounts).toHaveCount(4)
  await expect(accounts.first()).toContainText('R$ 0,00')
  await expect(page.locator('iframe')).toHaveCount(0)
})

test('@extensions financial assistant never submits provider data without explicit consent', async ({ page }) => {
  await login(page)
  await makeSpace(page)
  await goToView(page, 'Assistente financeiro')
  await expect(page.getByRole('heading', { name: 'Assistente financeiro' })).toBeVisible()
  await expect(page.getByText('Nenhum valor será enviado sem sua confirmação', { exact: false }))
    .toBeVisible()
  await page.getByRole('button', { name: 'Pré-visualizar valores' }).click()
  await expect(page.getByRole('heading', { name: 'Informações que seriam compartilhadas' })).toBeVisible()
  await expect(page.getByRole('button', { name: 'Solicitar análise' })).toBeDisabled()
})

test('@extensions fiscal staging stays a draft and cannot be mistaken for a national service invoice', async ({ page }) => {
  await login(page)
  await makeSpace(page)
  await goToView(page, 'Dados da organização')
  let cnpj = '999999990001'
  for (const weights of [
    [5,4,3,2,9,8,7,6,5,4,3,2],
    [6,5,4,3,2,9,8,7,6,5,4,3,2],
  ]) {
    const remainder = [...cnpj].reduce((sum, digit, i) =>
      sum + Number(digit) * weights[i], 0) % 11
    cnpj += remainder < 2 ? '0' : String(11 - remainder)
  }
  await page.getByRole('button', { name: 'Cadastrar empresa' }).click()
  const companyDialog = page.getByRole('dialog', { name: 'Cadastrar empresa' })
  await companyDialog.getByRole('textbox', { name: 'Nome legal' }).fill('Synthetic Issuer')
  await companyDialog.locator('#lf-company-country').click()
  await page.getByRole('option').filter({ hasText: 'Brasil (BR)' }).click()
  await companyDialog.getByText('Outras informações (opcionais)').click()
  await companyDialog.getByRole('textbox', { name: 'Identificador fiscal local' }).fill(cnpj)
  await companyDialog.getByRole('button', { name: 'Salvar empresa' }).click()
  await goToView(page, 'NFS-e Brasil')
  await expect(page.getByText('NÃO assina XML', { exact: false })).toBeVisible()
  await page.getByRole('button', { name: 'Novo rascunho' }).click()
  const draftDialog = page.getByRole('dialog', { name: 'Preparar serviço para NFS-e' })
  await draftDialog.locator('#lf-nfse-company').click()
  await page.getByRole('option').first().click()
  await draftDialog.getByRole('textbox', { name: 'Código município IBGE' }).fill('3304557')
  await draftDialog.getByRole('textbox', { name: 'Código de serviço' }).fill('01.01')
  await draftDialog.getByRole('textbox', { name: 'Descrição do serviço' }).fill('Synthetic fiscal service')
  await draftDialog.getByRole('textbox', { name: 'Valor (BRL)' }).fill('80,00')
  await draftDialog.getByRole('button', { name: 'Salvar rascunho' }).click()
  await expect(page.getByText('Nenhuma DPS foi enviada', { exact: false })).toBeVisible()
  await expect(page.getByRole('row', { name: /Synthetic fiscal service/ })).toContainText('Rascunho')
})

test('native Nextcloud dialog supports keyboard dismissal without creating a record', async ({ page }) => {
  await login(page)
  await makeSpace(page)
  await goToView(page, 'Plano de contas')
  const rows = page.locator('.lf-data-table tbody tr')
  await expect(rows).toHaveCount(4)
  await page.getByRole('button', { name: 'Criar conta' }).click()
  await expect(page.getByRole('dialog', { name: 'Criar conta financeira' })).toBeVisible()
  await page.keyboard.press('Escape')
  await expect(page.getByRole('dialog', { name: 'Criar conta financeira' })).toHaveCount(0)
  await expect(rows).toHaveCount(4)
})

test('core normalized import stages registrations, bank movements and transfers with explicit confirmation', async ({ page }) => {
  await login(page)
  await makeSpace(page, 'organization')
  await goToView(page, 'Migração de dados')
  await expect(page.getByRole('heading', { name: 'Migração e conferência' })).toBeVisible()
  const company = 'e2e-finance-' + Date.now().toString(36)
  const fixture = {
    format: 'librefinance.normalized.v1',
    source: { system: 'generic', companyId: company },
    company: {
      sourceId: company, legalName: 'Synthetic Migrated Organization',
      country: 'BR', taxId: '12345678000190', tradeName: null,
      municipalRegistration: null, stateRegistration: null,
    },
    contacts: [], categories: [],
    accounts: [{
      sourceId: 'bank-source-1', code: 'BANK_E2E', name: 'Synthetic Imported Bank',
      kind: 'bank', currency: 'BRL', openingBalanceMinor: 10000,
      currentBalanceMinor: 12500, balanceAsOf: '2026-11-20',
      bankName: 'Synthetic Institution', number: '000011223344', enabled: true,
    }, {
      sourceId: 'bank-source-2', code: 'BANK2_E2E',
      name: 'Synthetic Transfer Destination',
      kind: 'bank', currency: 'BRL', openingBalanceMinor: 2000,
      currentBalanceMinor: 2000, balanceAsOf: '2026-11-20',
      bankName: 'Synthetic Institution', number: null, enabled: true,
    }],
  }
  await page.getByRole('textbox', { name: 'Ou cole o JSON normalizado' })
    .fill(JSON.stringify(fixture))
  await page.getByRole('button', { name: 'Validar e pré-visualizar' }).click()
  await expect(page.getByRole('heading', { name: 'Resultado da prévia' })).toBeVisible()
  await expect(page.getByText('Pronto para confirmar')).toBeVisible()
  // NcCheckboxRadioSwitch renders its interactive label over the native input.
  await page.getByText('Confirmo a empresa de destino e autorizo somente os registros', { exact: false }).click()
  await expect(page.getByRole('checkbox')).toBeChecked()
  await page.getByRole('button', { name: 'Importar lote validado' }).click()
  await expect(page.getByRole('heading', { name: 'Importação concluída' })).toBeVisible()
  await expect(page.getByRole('status').filter({ hasText: 'Novos: 3' })).toBeVisible()

  const historical = {
    format: 'librefinance.transactions.v1',
    source: { system: 'generic', companyId: company },
    transactions: [{
      sourceId: 'movement-1', accountSourceId: 'bank-source-1',
      type: 'income', bookingDate: '2026-11-20',
      amountMinor: 750, currency: 'BRL',
      description: 'Synthetic source receipt',
    }],
  }
  await expect(page.getByRole('button', { name: 'Extratos históricos' })).toBeEnabled()
  await page.getByRole('button', { name: 'Extratos históricos' }).click()
  await page.getByRole('textbox', { name: 'Ou cole o JSON normalizado' })
    .fill(JSON.stringify(historical))
  await page.getByRole('button', { name: 'Validar e pré-visualizar' }).click()
  await expect(page.getByText('Pronto para confirmar')).toBeVisible()
  // NcCheckboxRadioSwitch renders its interactive label over the native input.
  await page.getByText('Confirmo a empresa de destino e autorizo somente os registros', { exact: false }).click()
  await expect(page.getByRole('checkbox')).toBeChecked()
  await page.getByRole('button', { name: 'Importar lote validado' }).click()
  await expect(page.getByRole('status').filter({ hasText: 'Novos: 1' })).toBeVisible()

  const transfer = {
    format: 'librefinance.transfers.v1',
    source: { system: 'generic', companyId: company },
    transfers: [{
      sourceId: 'transfer-1',
      fromAccountSourceId: 'bank-source-1',
      toAccountSourceId: 'bank-source-2',
      bookingDate: '2026-11-20', amountMinor: 200,
      currency: 'BRL', description: 'Synthetic internal transfer',
    }],
  }
  await expect(page.getByRole('button', { name: 'Transferências', exact: true })).toBeEnabled()
  await page.getByRole('button', { name: 'Transferências', exact: true }).click()
  await page.getByRole('textbox', { name: 'Ou cole o JSON normalizado' })
    .fill(JSON.stringify(transfer))
  await page.getByRole('button', { name: 'Validar e pré-visualizar' }).click()
  await expect(page.getByText('Pronto para confirmar')).toBeVisible()
  // NcCheckboxRadioSwitch renders its interactive label over the native input.
  await page.getByText('Confirmo a empresa de destino e autorizo somente os registros', { exact: false }).click()
  await expect(page.getByRole('checkbox')).toBeChecked()
  await page.getByRole('button', { name: 'Importar lote validado' }).click()
  await expect(page.getByRole('status').filter({ hasText: 'Novos: 1' })).toBeVisible()

  const inventory = {
    format: 'librefinance.inventory.v1',
    source: { system: 'generic', companyId: company, asOf: '2026-11-21' },
    counts: {
      companies: 1, accounts: 2, transactions: 1, transfers: 1,
      documents: 2, nfse_receipts: 1,
    },
  }
  await expect(page.getByRole('button', { name: 'Auditoria de cobertura' })).toBeEnabled()
  await page.getByRole('button', { name: 'Auditoria de cobertura' }).click()
  await page.getByRole('textbox', { name: 'Ou cole o JSON normalizado' })
    .fill(JSON.stringify(inventory))
  await page.getByRole('button', { name: 'Validar e pré-visualizar' }).click()
  await expect(page.getByRole('heading', { name: 'Resultado da prévia' })).toBeVisible()
  await expect(page.getByRole('row', { name: /documents/ })).toContainText('not_implemented')
  await expect(page.getByRole('row', { name: /transfers/ })).toContainText('counts_match')
  await expect(page.getByText('Contagens coincidentes não demonstram igualdade', { exact: false }))
    .toBeVisible()
  await expect(page.getByRole('button', { name: 'Importar lote validado' })).toHaveCount(0)

  await goToView(page, 'Plano de contas')
  const bank = page.getByRole('row', { name: /Synthetic Imported Bank/ })
  await expect(bank).toContainText('R$ 0,00')
  // Imported historical bank rows are now evidence in the same activity
  // timeline. They must not be posted as accounting journals automatically.
  await goToView(page, 'Movimentações')
  const evidence = page.getByRole('row', { name: /Synthetic source receipt/ })
  await expect(evidence).toContainText('A conferir')
  await expect(evidence).toContainText('R$ 7,50')
  // The transfer is verified in the normalized inventory preview above;
  // the retired independent transfer-history view does not exist.
})

test('visual QA: desktop dashboard and native navigation fit a 1280-pixel viewport', async ({ page }) => {
  await login(page)
  await makeSpace(page)
  await expect(page.getByRole('heading', { name: 'Visão geral' })).toBeVisible()
  // Capture the settled financial state, not an in-flight aggregate placeholder.
  await expect(page.locator('.lf-overview-kpis .lf-kpi-amount').first())
    .toContainText('R$', { timeout: 20_000 })
  await expect(page.locator('.lf-nav-section')).toHaveCount(1)
  await expect(page.locator('.lf-nav-section').first()).toHaveText('LIBREFINANCE')
  const metrics = await page.evaluate(() => {
    const nav = document.querySelector('#librefinance-app .app-navigation')
    const content = document.querySelector('[data-testid="librefinance-workspace"]')
    const header = nav?.querySelector('.lf-navigation-context')
    const list = nav?.querySelector('.app-navigation__list')
    const search = nav?.querySelector('.lf-menu-search')
    return {
      navWidth: nav?.getBoundingClientRect().width || 0,
      navBackground: nav ? getComputedStyle(nav).backgroundColor : 'transparent',
      headerBottom: header?.getBoundingClientRect().bottom || 0,
      listTop: list?.getBoundingClientRect().top || 0,
      searchBottom: search?.getBoundingClientRect().bottom || 0,
      contentWidth: content?.getBoundingClientRect().width || 0,
      scrollWidth: document.documentElement.scrollWidth,
      clientWidth: document.documentElement.clientWidth,
    }
  })
  expect(metrics.listTop).toBeGreaterThanOrEqual(metrics.headerBottom - 2)
  expect(metrics.listTop).toBeGreaterThanOrEqual(metrics.searchBottom - 2)
  expect(metrics.navWidth).toBeGreaterThan(220)
  expect(metrics.navBackground).not.toBe('transparent')
  expect(metrics.navBackground).not.toBe('rgba(0, 0, 0, 0)')
  expect(metrics.contentWidth).toBeGreaterThan(500)
  expect(metrics.scrollWidth).toBeLessThanOrEqual(metrics.clientWidth + 2)
  await page.screenshot({ path: '/tmp/librefinance-native-dashboard-1280.png', fullPage: true })
})

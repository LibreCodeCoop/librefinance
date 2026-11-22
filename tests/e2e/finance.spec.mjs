// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { test, expect } from '@playwright/test'
import { login, makeSpace, goToView, selectFinanceSpace } from './helpers.mjs'

test('Nextcloud official navigation and dashboard load without iframe', async ({ page }) => {
  await login(page)
  await expect(page.locator('.lf-product-name')).toHaveText('LibreFinance')
  await expect(page.getByRole('navigation', { name: 'Navegação do LibreFinance' })).toBeVisible()
  await expect(page.locator('iframe')).toHaveCount(0)
  await expect(page.getByRole('link', { name: 'Gerenciar', exact: true })).toBeVisible()
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

test('@extensions one Nextcloud navigation switches operational views without iframe', async ({ page }) => {
  await login(page)
  await makeSpace(page, 'organization')
  for (const [entry, heading] of [
    ['Bancos e caixa', 'Bancos e caixa'],
    ['A pagar e receber', 'A pagar e receber'],
    ['Relatórios', 'Relatórios'],
    ['Gestões financeiras', 'Gestões financeiras'],
    ['Migração de dados', 'Migração e conferência'],
    ['NFS-e Brasil', 'NFS-e Brasil'],
  ]) {
    await goToView(page, entry)
    await expect(page.getByRole('heading', { name: heading, exact: true })).toBeVisible()
    await expect(page.locator('iframe')).toHaveCount(0)
    await expect(page).toHaveURL(new RegExp('[?&]view='))
  }
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

test('@extensions task-oriented navigation has one agenda and one management group', async ({ page }) => {
  await login(page)
  await makeSpace(page, 'organization')
  const nav=page.getByRole('navigation', { name: 'Navegação do LibreFinance' })
  await expect(nav.locator('.lf-nav-section')).toHaveCount(1)
  for (const entry of ['Visão geral','A pagar e receber','Bancos e caixa',
    'Relatórios','Gerenciar']) {
    await expect(nav.getByRole('link', { name: entry, exact: true })).toBeVisible()
  }
  await expect(nav.getByRole('link', { name: 'A pagar', exact:true })).toHaveCount(0)
  await expect(nav.getByRole('link', { name: 'A receber', exact:true })).toHaveCount(0)
  await expect(nav.getByRole('link', { name: 'Cadastros' })).toHaveCount(0)
  await expect(nav.getByRole('link', { name: 'Mais ferramentas' })).toHaveCount(0)
  await expect(nav.getByRole('button', { name: 'Configurações' })).toHaveCount(0)
  await expect(nav.getByRole('link', { name: 'Configurações', exact: true })).toHaveCount(0)
  for (const entry of ['Acessos e compartilhamento','Campos personalizados','Formas de pagamento',
    'Recursos instalados','Dados e migração']) {
    await goToView(page, entry)
    await expect(page).toHaveURL(/[?&]view=/)
  }
  await expect(nav.locator('.lf-native-management-group')).toHaveCount(5)
  for (const group of ['Gestões e identidade','Cadastros','Financeiro e fiscal',
    'Equipe e personalização','Recursos e dados']) {
    await expect(nav.getByRole('link', {name: group, exact: true})).toHaveCount(1)
  }
  await expect(page.locator('.lf-settings-tabs')).toHaveCount(0)
  await goToView(page, 'Regras tributárias')
  await expect(page.getByRole('heading', { name: 'Regras tributárias' })).toBeVisible()
  await goToView(page, 'Plano de contas')
  await expect(page.getByRole('heading', { name: 'Plano de contas' })).toBeVisible()
  await goToView(page, 'Lançamentos contábeis')
  await expect(page.getByRole('button', { name: 'Novo lançamento', exact: true })).toHaveCount(1)
  await goToView(page, 'A pagar e receber')
  await expect(page.getByRole('heading', { name: 'A pagar e receber' })).toBeVisible()
  await nav.getByRole('searchbox', { name: 'Buscar no menu' }).fill('nota fiscal')
  await expect(nav.getByRole('link', { name: 'NFS-e Brasil' })).toBeVisible()
  await expect(nav.getByRole('link', { name: 'A pagar e receber' })).toHaveCount(0)
  await nav.getByRole('searchbox', { name: 'Buscar no menu' }).press('Escape')
  await expect(nav.getByRole('link', { name: 'A pagar e receber' })).toBeVisible()
  await goToView(page, 'Migração de dados')
  await expect(page.getByRole('heading', { name: 'Migração e conferência' })).toBeVisible()
  await page.getByRole('button', { name: 'Voltar aos recursos' }).click()
  await expect(page.getByRole('heading', { name: 'Recursos instalados' })).toBeVisible()
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

test('@extensions mixed payable/receivable list filters both directions and due dates', async ({ page }) => {
  await login(page)
  await makeSpace(page)
  await goToView(page,'A pagar e receber')
  await page.getByRole('button',{name:'Novo a pagar'}).click()
  await page.getByRole('dialog',{name:'Novo a pagar'})
    .getByRole('button',{name:'Somente conta a pagar (sem fatura)'}).click()
  const debit=page.getByRole('dialog',{name:'Conta simples'})
  await debit.getByRole('textbox',{name:'Descrição'}).fill('Synthetic outgoing commitment')
  await debit.getByRole('textbox',{name:'Valor'}).fill('75,00')
  await debit.getByRole('button',{name:'Cadastrar título'}).click()
  await page.getByRole('button',{name:'Novo a receber'}).click()
  await page.getByRole('dialog',{name:'Novo a receber'})
    .getByRole('button',{name:'Somente cobrança (sem fatura)'}).click()
  const credit=page.getByRole('dialog',{name:'Cobrança simples'})
  await credit.getByRole('textbox',{name:'Descrição'}).fill('Synthetic incoming commitment')
  await credit.getByRole('textbox',{name:'Valor'}).fill('125,00')
  await credit.getByRole('button',{name:'Cadastrar título'}).click()
  await page.locator('#lf-finance-direction').click()
  await page.getByRole('group',{name:'Filtrar por direção'}).getByRole('button',{name:'Tudo'}).click()
  const outgoing=page.getByRole('row',{name:/Synthetic outgoing commitment/})
  const incoming=page.getByRole('row',{name:/Synthetic incoming commitment/})
  await expect(outgoing).toContainText('Saída prevista')
  await expect(outgoing).toContainText('−R$')
  await expect(incoming).toContainText('Entrada prevista')
  await expect(incoming).toContainText('+R$')
  const valueHeader = page.getByRole('columnheader',{name:/Valor previsto/})
  await valueHeader.getByRole('button',{name:'Ordenar por Valor previsto'}).click()
  await expect(valueHeader).toHaveAttribute('aria-sort','ascending')
  await expect(page.locator('.lf-data-table tbody tr').first())
    .toContainText('Synthetic outgoing commitment')
  await valueHeader.getByRole('button',{name:'Ordenar por Valor previsto'}).click()
  await expect(valueHeader).toHaveAttribute('aria-sort','descending')
  await expect(page.locator('.lf-data-table tbody tr').first())
    .toContainText('Synthetic incoming commitment')
  const findAgenda = page.getByRole('searchbox',{name:'Pesquisar na agenda'})
  await findAgenda.fill('Synthetic outgoing')
  await expect(outgoing).toBeVisible()
  await expect(incoming).toHaveCount(0)
  await findAgenda.fill('')
  await expect(incoming).toBeVisible()
  await page.locator('#lf-finance-direction').click()
  const receiveOption=page.getByRole('group',{name:'Filtrar por direção'}).getByRole('button',{name:'A receber'})
  await expect(receiveOption).toBeVisible()
  // NcPopover animates and remounts its portal on filter/list updates.
  await page.waitForTimeout(300)
  await receiveOption.click()
  await expect(incoming).toBeVisible()
  await expect(outgoing).toHaveCount(0)
  await page.locator('#lf-finance-direction').click()
  const payableOption=page.getByRole('group',{name:'Filtrar por direção'}).getByRole('button',{name:'A pagar'})
  await expect(payableOption).toBeVisible()
  await page.waitForTimeout(300)
  await payableOption.click()
  await expect(outgoing).toBeVisible()
  await expect(incoming).toHaveCount(0)
  await page.locator('#lf-obligations-due').click()
  await page.getByRole('group',{name:'Filtrar por vencimento'})
    .getByRole('button',{name:'Próximos 7 dias'}).click()
  await expect(outgoing).toBeVisible()
  await expect(page.getByRole('heading',{name:'Agenda de entradas e saídas'})).toBeVisible()
  await expect(page.getByText('sem misturar com o extrato bancário', {exact:false})).toBeVisible()
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

test('@extensions sales and purchases share the same agenda and old URLs', async ({ page }) => {
  await login(page)
  await makeSpace(page, 'organization')
  await goToView(page, 'A pagar e receber')
  await expect(page).toHaveURL(/[?&]view=finance/)
  await expect(page.locator('#lf-finance-direction')).toHaveCount(0)
  await expect(page.getByRole('button',{name:'Novo a receber'})).toBeVisible()
  await expect(page.getByRole('button',{name:'Novo a pagar'})).toBeVisible()
  await page.getByRole('button', { name: 'Novo a receber' }).click()
  await page.getByRole('dialog', { name: 'Novo a receber' })
    .getByRole('button', { name: 'Criar fatura de venda' }).click()
  await expect(page).toHaveURL(/[?&]view=documents/)
  await expect(page.getByRole('heading',{name:'Faturas',exact:true})).toBeVisible()
  await expect(page.locator('#lf-commercial-direction')).toHaveCount(0)
  await expect(page.getByRole('dialog',{name:'Nova fatura de venda'})).toBeVisible()
  await page.getByRole('dialog',{name:'Nova fatura de venda'})
    .getByRole('button',{name:'Cancelar'}).click()
  await page.getByRole('button',{name:'Nova fatura de compra'}).click()
  await expect(page.getByRole('dialog',{name:'Nova fatura de compra'})).toBeVisible()
  const spaceId=new URL(page.url()).searchParams.get('spaceId')
  await page.goto('/index.php/apps/librefinance/?spaceId='+spaceId+'&view=payables')
  await expect(page.getByRole('heading',{name:'A pagar e receber'})).toBeVisible()
  await expect(page.getByText('Nenhum resultado para os filtros')).toBeVisible()
  await expect(page.locator('#lf-finance-direction')).toHaveCount(0)
  await expect(page.getByRole('button',{name:'Limpar filtros'})).toBeVisible()
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

test('@extensions personal workspace offers unified finance and manager tools', async ({ page }) => {
  await login(page)
  await makeSpace(page, 'personal')
  const nav=page.getByRole('navigation', { name: 'Navegação do LibreFinance' })
  for (const entry of ['A pagar e receber','Bancos e caixa','Gerenciar'])
    await expect(nav.getByRole('link',{name:entry})).toBeVisible()
  await goToView(page,'A pagar e receber')
  await expect(page.getByRole('button',{name:'Novo a receber'})).toBeVisible()
  await expect(page.getByRole('button',{name:'Novo a pagar'})).toBeVisible()
  await expect(page.locator('#lf-space-select')).toBeVisible()
  await nav.getByRole('searchbox',{name:'Buscar no menu'}).fill('migracao')
  await expect(nav.getByRole('link',{name:'Dados e migração',exact:true})).toBeVisible()
  await nav.getByRole('searchbox',{name:'Buscar no menu'}).fill('naoexiste123')
  await expect(nav.getByText('Nenhuma função encontrada')).toBeVisible()
})

test('@extensions disabling invoicing preserves standalone pay/receive flow', async ({ page }) => {
  await login(page)
  await makeSpace(page, 'organization')
  const nav=page.getByRole('navigation',{name:'Navegação do LibreFinance'})
  const search=nav.getByRole('searchbox',{name:'Buscar no menu'})
  await search.fill('cobranca')
  await expect(nav.getByRole('link',{name:'A pagar e receber'})).toBeVisible()
  await search.press('Escape')
  await goToView(page,'A pagar e receber')
  await expect(nav.getByRole('link',{name:'Faturas',exact:true})).toBeVisible()
  await goToView(page,'Recursos instalados')
  const module=page.locator('.lf-settings-module[data-module-id="librefinance_invoicing"]')
  await module.getByRole('button',{name:'Desativar'}).click()
  await expect(module.getByText('Desativado neste espaço')).toBeVisible()
  await goToView(page,'A pagar e receber')
  await expect(page.getByRole('heading',{name:'A pagar e receber'})).toBeVisible()
  await expect(nav.getByRole('link',{name:'Faturas',exact:true})).toHaveCount(0)
  await page.getByRole('button',{name:'Novo a receber'}).click()
  await expect(page.getByRole('dialog',{name:'Novo a receber'})
    .getByRole('button',{name:'Somente cobrança (sem fatura)'})).toBeVisible()
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

test('@extensions dashboard uses genuine cash-flow aggregates and no duplicate entry action', async ({ page }) => {
  await login(page)
  await makeSpace(page, 'collective')
  await expect(page.getByRole('heading', { name: 'Visão geral' })).toBeVisible()
  await expect(page.getByText('Seu espaço financeiro está pronto')).toBeVisible()
  await expect(page.getByText('Gestão financeira criada.')).toHaveCount(0)
  await expect(page.getByText('Saldo de caixa', { exact: true })).toBeVisible()
  await expect(page.getByRole('heading', { name: 'Movimento do caixa' })).toBeVisible()
  await expect(page.getByRole('heading', { name: 'Acesso rápido' })).toBeVisible()
  await expect(page.getByRole('button', { name: 'Registrar entrada ou saída', exact: true })).toHaveCount(1)
  await expect(page.getByRole('button', { name: 'Novo lançamento', exact: true })).toHaveCount(0)
  await expect(page.getByText('Sem variação de caixa neste intervalo')).toBeVisible()
  const expectedMonth = new Date().getFullYear() + '-'
    + String(new Date().getMonth() + 1).padStart(2, '0') + '-01'
  const monthRequest = page.waitForResponse(response =>
    response.url().includes('/librefinance/api/spaces/')
      && response.url().includes('/profit-loss?from=' + expectedMonth)
      && response.status() === 200)
  await page.getByRole('button', { name: 'Este mês' }).click()
  await monthRequest
  await expect(page.getByRole('button', { name: 'Este mês' })).toHaveAttribute('aria-pressed', 'true')
  await page.locator('.lf-data-methodology summary').click()
  await expect(page.getByText('extratos pendentes de conciliação', { exact: false })).toBeVisible()
})

test('@extensions first-time user can record an expense, review its effects and reconcile that exact bank item', async ({ page }) => {
  await login(page)
  await makeSpace(page, 'personal')
  await expect(page.getByRole('heading', { name: 'Visão geral' })).toBeVisible()
  await page.getByRole('button', { name: 'Registrar primeira despesa' }).click()
  const entry = page.getByRole('dialog', { name: 'Novo lançamento contábil' })
  await expect(entry).toBeVisible()
  await entry.getByRole('textbox', { name: 'Valor' }).fill('12,345')
  await entry.getByRole('textbox', { name: 'Descrição' }).fill('Synthetic UX onboarding expense')
  await entry.getByRole('button', { name: 'Contabilizar movimentação' }).click()
  await expect(entry.getByRole('alert')).toContainText('até duas casas decimais')
  await entry.getByRole('textbox', { name: 'Valor' }).fill('25,90')
  await entry.locator('#lf-journal-counterpart').click()
  await page.getByRole('option').first().click()
  await expect(entry.getByText('Confira antes de contabilizar')).toBeVisible()
  await expect(entry.locator('.lf-dialog-summary')).toContainText('Caixa')
  await expect(entry.locator('.lf-dialog-summary')).toContainText('R$ 25,90')
  await entry.getByRole('button', { name: 'Contabilizar movimentação' }).click()
  await expect(entry).toHaveCount(0)
  await expect(page.getByRole('row', { name: /Synthetic UX onboarding expense/ })).toBeVisible()
  await goToView(page, 'Visão geral')
  await expect(page.getByText('Seu espaço financeiro está pronto')).toHaveCount(0)
  await expect(page.locator('.lf-overview-kpi--expense .lf-kpi-amount')).toHaveText('R$ 25,90')
  await goToView(page, 'Bancos e caixa')
  await page.getByRole('button', { name: 'Importar extrato CSV' }).click()
  const importing = page.getByRole('dialog', { name: 'Importar extrato CSV' })
  const reference = 'synthetic-ux-expense-' + Date.now().toString(36)
  await importing.locator('input[type="file"]').setInputFiles({
    name: 'synthetic-ux-extrato.csv', mimeType: 'text/csv',
    buffer: Buffer.from('id,date,amount,description\n' + reference
      + ',2026-11-20,-25.90,Synthetic UX onboarding expense\n'),
  })
  await importing.getByRole('button', { name: 'Importar para revisão' }).click()
  await expect(importing).toHaveCount(0)
  const movement = page.getByRole('row', { name: /Synthetic UX onboarding expense/ })
  await expect(movement).toContainText('Pendente')
  await movement.getByRole('button', { name: 'Conciliar' }).click()
  const reconcile = page.getByRole('dialog', { name: 'Conciliar movimentação' })
  await expect(reconcile.locator('.lf-dialog-summary')).toContainText('Synthetic UX onboarding expense')
  await expect(reconcile.locator('.lf-dialog-summary')).toContainText('Caixa')
  await expect(reconcile.locator('.lf-dialog-summary')).toContainText('25,90')
  await expect(reconcile.locator('.lf-dialog-summary')).toContainText('Saída')
  await reconcile.locator('#lf-bank-journal').click()
  await page.getByRole('option').first().click()
  await reconcile.getByRole('button', { name: 'Confirmar conciliação' }).click()
  await expect(reconcile).toHaveCount(0)
  await expect(movement).toContainText('Conciliada')
  await goToView(page, 'Visão geral')
  await expect(page.locator('.lf-overview-kpi--expense .lf-kpi-amount')).toHaveText('R$ 25,90')
})

test('@extensions cancelling a CSV import clears the selected file and does not import anything', async ({ page }) => {
  await login(page)
  await makeSpace(page)
  await goToView(page, 'Bancos e caixa')
  await page.getByRole('button', { name: 'Importar extrato CSV' }).click()
  const dialog = page.getByRole('dialog', { name: 'Importar extrato CSV' })
  await dialog.locator('input[type="file"]').setInputFiles({
    name: 'synthetic-discarded.csv', mimeType: 'text/csv',
    buffer: Buffer.from('id,date,amount,description\ndiscard-test,2026-11-20,-10.00,Discard me\n'),
  })
  await expect(dialog.getByText('synthetic-discarded.csv')).toBeVisible()
  await dialog.getByRole('button', { name: 'Cancelar' }).click()
  await expect(dialog).toHaveCount(0)
  await page.getByRole('button', { name: 'Importar extrato CSV' }).click()
  await expect(dialog.getByText('Nenhum arquivo selecionado')).toBeVisible()
  await expect(dialog.getByRole('button', { name: 'Importar para revisão' })).toBeDisabled()
  await dialog.getByRole('button', { name: 'Cancelar' }).click()
  await expect(page.getByText('Discard me')).toHaveCount(0)
})

test('finance entry fields are nonoverlapping on desktop and stacked on mobile', async ({ page }) => {
  await login(page)
  await makeSpace(page, 'personal')
  await page.getByRole('button', { name: 'Registrar primeira despesa' }).click()
  const entry = page.getByRole('dialog', { name: 'Novo lançamento contábil' })
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
  const form = page.getByRole('dialog', { name: 'Criar campo adicional' })
  await form.getByRole('textbox', { name: 'Nome do campo' }).fill('Local registration')
  await form.getByRole('textbox', { name: 'Identificador técnico' }).fill('local_registry')
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
  await page.getByRole('button', { name: 'Novo lançamento', exact: true }).click()
  const dialog = page.getByRole('dialog', { name: 'Novo lançamento contábil' })
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

test('@extensions standalone payable may have explicit administrative settlement without ledger', async ({ page }) => {
  await login(page)
  await makeSpace(page)
  await goToView(page, 'A pagar e receber')
  await page.getByRole('button', { name: 'Novo a pagar' }).click()
  await page.getByRole('dialog', { name: 'Novo a pagar' })
    .getByRole('button', { name: 'Somente conta a pagar (sem fatura)' }).click()
  const dialog=page.getByRole('dialog', { name: 'Conta simples' })
  await dialog.getByRole('textbox', { name: 'Descrição' }).fill('Synthetic operating obligation')
  await dialog.getByRole('textbox', { name: 'Valor' }).fill('350,00')
  await dialog.getByRole('button', { name: 'Cadastrar título' }).click()
  const row=page.getByRole('row', { name: /Synthetic operating obligation/ })
  await expect(row).toBeVisible()
  await row.getByRole('button', { name: /Mais ações de/ }).click()
  await page.getByRole('menuitem', { name: 'Baixa sem movimentação' }).click()
  const settling=page.getByRole('dialog', { name: 'Registrar baixa administrativa (sem banco)' })
  await expect(settling.getByRole('textbox', { name: 'Valor da baixa' })).toHaveValue('350,00')
  await settling.getByRole('button', { name: 'Registrar baixa' }).click()
  await expect(page.getByText('Nenhum lançamento contábil foi feito', { exact: false })).toBeVisible()
  await expect(row).toContainText('Baixado administrativamente')
})

test('@extensions payable payment posts one cash journal and updates the existing title', async ({ page }) => {
  await login(page)
  await makeSpace(page)
  await goToView(page, 'A pagar e receber')
  await page.getByRole('button', { name: 'Novo a pagar' }).click()
  await page.getByRole('dialog', { name: 'Novo a pagar' })
    .getByRole('button', { name: 'Somente conta a pagar (sem fatura)' }).click()
  const form=page.getByRole('dialog', { name: 'Conta simples' })
  const title='Synthetic cash payment '+Date.now().toString(36)
  await form.getByRole('textbox', { name: 'Descrição' }).fill(title)
  await form.getByRole('textbox', { name: 'Valor' }).fill('350,00')
  await form.getByRole('button', { name: 'Cadastrar título' }).click()
  const row=page.getByRole('row', { name: new RegExp(title, 'i') })
  await expect(row).toContainText('350')
  await row.getByRole('button', { name: 'Pagar', exact: true }).click()
  const payment=page.getByRole('dialog', { name: 'Registrar pagamento' })
  await expect(payment.getByText('Não realiza transferência no banco real',
    { exact:false })).toBeVisible()
  await payment.locator('#lf-cash-counter').click()
  await page.getByRole('option').filter({ hasText: 'Despesas' }).click()
  await expect(payment.getByRole('button', { name: 'Registrar sem duplicar' })).toBeEnabled()
  await payment.getByRole('button', { name: 'Registrar sem duplicar' }).click()
  await expect(page.getByText('Movimentação e compromisso atualizados juntos.',
    { exact:false })).toBeVisible()
  await expect(row).toContainText('Liquidado por lançamento')
  await expect(row).toContainText('Movimentado:')
  await expect(row).toContainText('350')
})

test('@extensions invoice is entered once and remains linked to the same receivable', async ({ page }) => {
  await login(page)
  await makeSpace(page)
  await goToView(page, 'Clientes e fornecedores')
  await page.getByRole('button', { name: 'Novo contato' }).click()
  const contactDialog=page.getByRole('dialog', { name: 'Novo contato' })
  await contactDialog.getByRole('textbox', { name: 'Nome', exact: true }).fill('Synthetic commercial client')
  await contactDialog.getByRole('button', { name: 'Salvar cadastro' }).click()
  await expect(page.getByRole('row', { name: /Synthetic commercial client/ })).toBeVisible()
  await goToView(page, 'A pagar e receber')
  await page.getByRole('button', { name: 'Novo a receber' }).click()
  await page.getByRole('dialog', { name: 'Novo a receber' })
    .getByRole('button', { name: 'Criar fatura de venda' }).click()
  const invoiceDialog=page.getByRole('dialog', { name: 'Nova fatura de venda' })
  const number='E2E-INV-'+Date.now().toString(36)
  await invoiceDialog.getByRole('textbox', { name: 'Número' }).fill(number)
  await invoiceDialog.getByRole('textbox', { name: 'País (ISO 2, obrigatório)' }).fill('PT')
  await invoiceDialog.locator('#lf-invoice-contact').click()
  await page.getByRole('option').first().click()
  await invoiceDialog.getByRole('textbox', { name: 'Descrição da linha 1' }).fill('Synthetic service')
  await invoiceDialog.getByRole('textbox', { name: 'Preço unitário' }).fill('120,00')
  await invoiceDialog.getByRole('button', { name: 'Salvar rascunho' }).click()
  const invoiceRow=page.getByRole('row', { name: new RegExp(number, 'i') })
  await expect(invoiceRow).toContainText('120')
  await invoiceRow.getByRole('button', { name: 'Detalhes' }).click()
  await page.getByRole('button', { name: 'Confirmar emissão comercial' }).click()
  await expect(page.getByText('Não é uma NFS-e', { exact: false })).toBeVisible()
  await page.getByRole('button', { name: 'Ver conta vinculada' }).click()
  await expect(page.getByRole('heading', { name: 'A pagar e receber' })).toBeVisible()
  const linked=page.getByRole('row', { name: new RegExp(number, 'i') })
  await expect(linked).toContainText('Fatura #')
  await linked.getByRole('button', { name: 'Abrir fatura' }).click()
  await expect(page.getByRole('heading', { name: 'Faturas de venda' })).toBeVisible()
  await expect(page.getByRole('dialog', { name: 'Detalhes da fatura' })).toBeVisible()
  await page.getByRole('button', { name: 'Registrar recebimento', exact: true }).click()
  const receipt=page.getByRole('dialog', { name: 'Registrar recebimento' })
  await expect(receipt.getByText('regime de caixa', { exact:false })).toBeVisible()
  await receipt.getByRole('textbox', { name: 'Valor recebido ou pago' }).fill('40,00')
  await receipt.getByRole('button', { name: 'Registrar sem duplicar' }).click()
  await expect(page.getByRole('dialog', { name: 'Detalhes da fatura' })).toBeVisible()
  await expect(page.getByRole('dialog', { name: 'Detalhes da fatura' })
    .getByText('Situação: Recebimento parcial')).toBeVisible()
  await page.getByRole('button', { name: 'Ver conta vinculada' }).click()
  await expect(linked).toContainText('Movimentado:')
  await expect(linked).toContainText('80')
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

test('mobile layout uses responsive Nextcloud shell without horizontal overflow', async ({ page }) => {
  await login(page)
  await makeSpace(page, 'collective')
  await page.setViewportSize({ width: 390, height: 844 })
  await expect(page.locator('[data-testid="librefinance-workspace"]')).toBeVisible()
  await expect(page.getByRole('heading', { name: 'Visão geral' })).toBeVisible()
  const metrics = await page.evaluate(() => ({
    scroll: document.documentElement.scrollWidth,
    client: document.documentElement.clientWidth,
  }))
  expect(metrics.scroll).toBeLessThanOrEqual(metrics.client + 2)
  await expect(page.locator('iframe')).toHaveCount(0)
  const toggle = page.locator('button.app-navigation-toggle')
  await expect(toggle).toBeVisible()
  await toggle.click()
  const menu = page.getByRole('navigation', { name: 'Navegação do LibreFinance' })
  const search = menu.getByRole('searchbox', { name: 'Buscar no menu' })
  await expect(search).toBeVisible()
  await search.fill('extrato')
  await expect(menu.getByRole('link', { name: 'Bancos e caixa' })).toBeVisible()
  await page.screenshot({ path: '/tmp/librefinance-mobile-menu-390.png' })
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
  await goToView(page, 'Bancos e caixa')
  await expect(page.getByRole('row', { name: /Synthetic source receipt/ })).toContainText('Pendente')
  await expect(page.getByRole('tab', { name: 'Transferências' })).toHaveCount(0)
  await page.locator('.lf-transfer-history summary').click()
  await expect(page.locator('.lf-transfer-history')
    .getByRole('row', { name: /Aguardando conferência/ })).toContainText('R$ 2,00')
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

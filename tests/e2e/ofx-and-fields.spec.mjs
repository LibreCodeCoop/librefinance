// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { test, expect } from '@playwright/test'
import { login, makeSpace, goToView } from './helpers.mjs'

test('CSV menu offers a native Nextcloud file picker and downloadable example', async ({page})=>{
  await login(page)
  await makeSpace(page,'personal')
  await goToView(page,'Movimentações')
  await page.locator('#lf-import-menu').click()
  const menu=page.getByRole('menu',{name:'Importar extrato'})
  await expect(menu.getByRole('menuitem',{name:'Arquivo CSV'})).toBeVisible()
  await expect(menu.getByRole('menuitem',{name:'Arquivo OFX'})).toBeVisible()
  await menu.getByRole('menuitem',{name:'Arquivo CSV'}).click()
  const importing=page.getByRole('dialog',{name:'Importar extrato CSV'})
  await expect(importing).toBeVisible()
  const sample=importing.getByRole('link',{name:'Baixar exemplo de CSV'})
  await expect(sample).toHaveAttribute('download','exemplo-extrato.csv')
  const href=await sample.getAttribute('href')
  expect(decodeURIComponent(href)).toContain('id,date,amount,description')
  const download=page.waitForEvent('download')
  await sample.click()
  expect((await download).suggestedFilename()).toBe('exemplo-extrato.csv')
  await expect(importing.getByRole('button',{name:'Selecionar arquivo CSV'})).toBeVisible()
  await expect(importing.locator('input[type=file]')).toHaveCount(1)
  await importing.getByRole('button',{name:'Cancelar'}).click()
  await page.locator('#lf-import-menu').click()
  await page.getByRole('menu',{name:'Importar extrato'})
    .getByRole('menuitem',{name:'Arquivo OFX'}).click()
  const ofx=page.getByRole('dialog',{name:'Importar extrato OFX'})
  await expect(ofx.getByRole('button',{name:'Selecionar arquivo OFX'})).toBeVisible()
  await expect(ofx.getByRole('link',{name:'Baixar exemplo de CSV'})).toHaveCount(0)
  await ofx.getByRole('button',{name:'Cancelar'}).click()
})

test('OFX 1.x bank file imports inline without posting a second journal', async ({page})=>{
  await login(page)
  await makeSpace(page,'personal')
  await goToView(page,'Movimentações')
  await page.locator('#lf-import-menu').click()
  await page.getByRole('menu',{name:'Importar extrato'})
    .getByRole('menuitem',{name:'Arquivo OFX'}).click()
  const importing=page.getByRole('dialog',{name:'Importar extrato OFX'})
  const suffix=Date.now().toString(36)
  const description='OFX cobrança de café '+suffix
  const ofx=[
    'OFXHEADER:100','DATA:OFXSGML','VERSION:102','CHARSET:1252','',
    '<OFX><STMTRS><CURDEF>BRL','<BANKTRANLIST>',
    '<STMTTRN><DTPOSTED>20261122','<TRNAMT>-14.27',
    '<FITID>ui-ofx-'+suffix,'<MEMO>'+description,'</STMTTRN>',
    '</BANKTRANLIST></STMTRS></OFX>',
  ].join('\n')
  const latinOfx=Buffer.from(ofx,'latin1')
  await importing.locator('input[type=file]').setInputFiles({
    name:'statement.ofx',mimeType:'application/x-ofx',buffer:latinOfx,
  })
  await expect(importing.getByText('statement.ofx')).toBeVisible()
  await importing.getByRole('button',{name:'Pré-visualizar extrato',exact:true}).click()
  await expect(importing.getByRole('region',{name:'Prévia da importação do extrato'})).toBeVisible()
  await importing.getByRole('button',{name:'Confirmar importação',exact:true}).click()
  await expect(importing).toHaveCount(0)
  await expect(page.getByRole('heading',{name:'Movimentações'})).toBeVisible()
  const pending=page.getByRole('row',{name:new RegExp(description)})
  await expect(pending).toHaveCount(1)
  await expect(pending).toContainText('R$ 14,27')
  await expect(pending).toContainText('A conferir')
  await page.locator('#lf-import-menu').click()
  await page.getByRole('menu',{name:'Importar extrato'})
    .getByRole('menuitem',{name:'Arquivo OFX'}).click()
  const repeated=page.getByRole('dialog',{name:'Importar extrato OFX'})
  await repeated.locator('input[type=file]').setInputFiles({
    name:'statement.ofx',mimeType:'application/x-ofx',buffer:latinOfx,
  })
  await repeated.getByRole('button',{name:'Pré-visualizar extrato',exact:true}).click()
  await expect(repeated.getByRole('region',{name:'Prévia da importação do extrato'})).toBeVisible()
  await repeated.getByRole('button',{name:'Confirmar importação',exact:true}).click()
  await expect(repeated).toHaveCount(0)
  await expect(pending).toHaveCount(1)
  await expect(page.getByRole('status')).toContainText('Movimentações importadas: 0;')
})

test('field creation first chooses its area; internal fields remain editable to managers', async ({page})=>{
  await login(page)
  await makeSpace(page,'organization')
  await goToView(page,'Campos personalizados')
  await expect(page.getByRole('heading',{name:'Campos personalizados',exact:true})).toBeVisible()
  await expect(page.locator('#lf-field-studio-entity')).toHaveCount(0)
  await expect(page.getByRole('note',{name:'Nenhum campo personalizado'})).toBeVisible()
  await page.getByRole('button',{name:'Novo campo'}).click()
  const editor=page.getByRole('dialog',{name:'Novo campo personalizado'})
  const target=editor.getByRole('group',{name:'Escolher local do campo'})
  for (const entry of ['Empresa / organização','Clientes e fornecedores','Produtos e serviços',
    'Faturas de venda','Faturas de compra','Movimentações realizadas']) {
    await expect(target.getByRole('button',{name:entry})).toBeVisible()
  }
  await target.getByRole('button',{name:'Clientes e fornecedores'}).click()
  const label='Contrato interno '+Date.now().toString(36)
  await editor.getByRole('textbox',{name:'Nome do campo'}).fill(label)
  // NcCheckboxRadioSwitch styles the label above the native input;
  // click the actual visible text to exercise its public UI interaction.
  await editor.getByText('Mostrar no formulário',{exact:true}).click()
  await expect(editor.getByRole('checkbox',{name:'Mostrar no formulário'})).not.toBeChecked()
  await expect(editor.getByRole('checkbox',{name:'Disponível na API comum'})).not.toBeChecked()
  // Explicitly enabling API access remains possible even if the form is hidden.
  await editor.getByText('Disponível na API comum',{exact:true}).click()
  await expect(editor.getByRole('checkbox',{name:'Disponível na API comum'})).toBeChecked()
  await expect(editor.getByText('Oculto no formulário, mas disponível',{exact:false})).toBeVisible()
  await editor.getByText('Disponível na API comum',{exact:true}).click()
  await expect(editor.getByRole('checkbox',{name:'Disponível na API comum'})).not.toBeChecked()
  await expect(editor.getByText('Este campo será um dado interno',{exact:false})).toBeVisible()
  await expect(editor.getByRole('checkbox',{name:'Obrigatório ao salvar'})).toBeDisabled()
  await editor.getByRole('button',{name:'Salvar campo'}).click()
  await expect(editor).toHaveCount(0)
  const item=page.locator('.lf-custom-field-row').filter({hasText:label})
  await expect(item).toBeVisible()
  await expect(item).toContainText('Interno')
  await expect(item).toContainText('API restrita a gestores')
  await goToView(page,'Clientes e fornecedores')
  await page.getByRole('button',{name:'Novo contato'}).click()
  const contact=page.getByRole('dialog',{name:'Novo contato'})
  await expect(contact.getByRole('textbox',{name:label})).toBeVisible()
  await contact.getByRole('textbox',{name:'Nome',exact:true}).fill('Internal metadata user')
  await contact.getByRole('textbox',{name:label}).fill('Synthetic manager-only reference')
  await contact.getByRole('button',{name:'Salvar cadastro'}).click()
  await expect(contact).toHaveCount(0)
})

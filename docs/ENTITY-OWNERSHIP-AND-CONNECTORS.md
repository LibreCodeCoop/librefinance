<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Gestões financeiras, organizações e instituições financeiras

## Contratos atualmente implementados

Uma **gestão financeira** (space) é a fronteira de autorização, moeda,
livro razão, contas, movimentações, anexos de módulos e auditoria.
Uma **organização** (company) é um cadastro jurídico identificado por
`space_id` e `company.id`, com identidade visual própria (`brand_color`,
logotipo), dados fiscais e informações de endereço. Cadastrar uma organização
NÃO cria uma segunda contabilidade, um caixa independente ou novas permissões.

Uma gestão pessoal pode operar sem empresa. Uma gestão organizacional também
pode ser criada antes de concluir o cadastro jurídico, mas **não deve ser
interpretada como apta a emitir documentos fiscais** enquanto o emissor e
seus requisitos não forem validados. Vários CNPJs numa única gestão atualmente
compartilham saldos. Isso **não oferece contabilidade segregada por CNPJ**.
Empresas que exigem livros e conciliação próprios devem usar gestões
financeiras separadas até existir um contrato contábil multiempresa verificável.

A cor do espaço identifica o contexto de trabalho; a cor da organização é
identidade visual da empresa. Alterar uma não altera a outra. O produto deve
evitar apresentar as duas configurações como equivalentes.

## Provedores e meios de pagamento: separação obrigatória

Os seguintes conceitos não são intercambiáveis:

1. **Organização titular:** empresa cadastrada na gestão; em contas
   pessoais, a titularidade pode ser pessoa física.
2. **Conta financeira:** conta bancária, carteira ou caixa existente na
   gestão, com moeda e saldo próprios.
3. **Instituição/provedor:** Sicoob, Itaú, Bradesco, Caixa, Banco do Brasil,
   PagBank, Stripe ou PayPal. Não significa que um conector esteja disponível.
4. **Conexão:** autorização revogável, escopo, ambiente, estado, vencimento
   e referência opaca a credenciais, nunca segredos armazenados no navegador.
5. **Método de pagamento:** opção exibida na cobrança/pagamento; não comprova
   que uma conexão bancária exista.
6. **Extrato/evento:** evidência externa idempotente a conferir, não lançamento
   contábil automático nem baixa de fatura.

Os vínculos mínimos para um futuro conector são
`space_id + bank_account_id + owner_kind + owner_id + provider_id`,
com referência de credencial protegida e trilha de auditoria. O servidor
deve validar titularidade, autorização, segregação de gestão, concorrência,
revogação, replay e reconciliação. Não criar vínculos presumidos com a primeira
empresa cadastrada.

## Organização do menu

- **Gerenciar > Gestões financeiras:** espaços e fronteiras de acesso/livro.
- **Gerenciar > Cadastros > Dados da organização:** empresa, CNPJ,
  endereço, marca, logotipo e emissores.
- **Gerenciar > Cadastros > Contas e bancos:** contas e caixas.
- **Financeiro > Movimentações > Importar extrato:** arquivos sintéticos
  CSV/OFX ou arquivos do usuário autorizados explicitamente.
- **Gerenciar > Formas de pagamento:** opções comerciais, sem segredos de
  gateway. Integrações externas futuras precisam de uma área própria de
  conexões, associada ao titular e à conta, não uma lista de bancos
  colocada arbitrariamente no cadastro de empresa.

## Paridade com Akaunting: situação, não promessa

O projeto LibreCode Banking Connectors do Akaunting já possui um contrato
de adaptadores e um conector Sicoob de leitura; a própria documentação
declara que **não está pronto para produção**. Isso não equivale a ter
implementado Sicoob, PagBank, Itaú, Bradesco, Caixa, Banco do Brasil,
Stripe e PayPal no LibreFinance. Copiar a interface sem contrato de
segurança e titularidade seria uma falsa paridade.

Não usar extratos reais de Nextcloud em fixtures, CI, screenshots, PRs ou
logs. Para importação OFX utilizar exclusivamente identificadores, datas,
valores e descrições gerados sinteticamente.

<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Navegacao e operacoes financeiras

O LibreFinance possui navegacao orientada a tarefas:

- **Visao geral:** indicadores calculados a partir dos lancamentos reais.
- **A pagar e receber:** uma agenda unica de entradas e saidas previstas,
  incluindo documentos de venda/compra e titulos avulsos.
- **Bancos e caixa:** movimentos do caixa, extratos importados, transferencias
  e conciliacao dos lancamentos bancarios efetivos.
- **Relatorios:** demonstrativos, com segregacao por gestao.
- **Gerenciar:** gestoes financeiras, clientes e fornecedores, produtos e
  servicos, categorias, empresas, plano de contas, lancamentos contabeis,
  regras fiscais, NFS-e, assistente e a administracao de pessoas e grupos,
  campos personalizados, formas de pagamento, recursos instalados e dados
  para migracao, conforme permissao.

Nao ha menus separados e permanentes para Contas a receber e Contas a pagar,
nem dois grupos distintos de Cadastros e Mais ferramentas. Gerenciar apresenta
quatro separadores apenas visuais (Cadastros, Financeiro e fiscal, Equipe e
personalizacao, Recursos e dados); cada funcionalidade e aberta diretamente
sem outra pagina ou abas de Configuracoes. As URLs antigas de Configuracoes
continuam redirecionadas para a funcionalidade correspondente.

## Agenda unica, mas nao extrato bancario

A agenda reune **valores previstos**, identificados como entrada ou saida.
Cada linha exibe direcao, origem, valor e saldo com sinal, vencimento, situacao
e acoes. Filtros por direcao (ambos, a receber, a pagar), situacao e vencimento
(todos, vencidos, proximos sete dias) sao aplicados pelo servidor antes da
paginacao. Filtrar ou navegar nao transforma uma cobranca aberta em entrada
contabilizada. O extrato de caixa efetivo pertence a Bancos e caixa.

Para registrar uma operacao, use **Nova entrada** (seta para dentro:
valor a receber) ou **Nova saida** (seta para fora: valor a pagar),
independentemente do filtro atual da agenda. A setas seguem o mesmo padrao
do painel e dos lancamentos contabeis. As duas acoes criam compromissos
previstos e nao transferencias bancarias. O usuario decide entre:

1. **Simples:** descricao, valor e vencimento; cria somente um titulo avulso.
2. **Detalhada:** itens, contato, tributos e vencimento; cria documento
   comercial cujo titulo e criado automaticamente na confirmacao.

As vendas e compras detalhadas podem ser consultadas em Documentos comerciais,
na mesma pagina. Um compromisso vinculado oferece Abrir documento, e o
documento oferece Ver conta vinculada, sem registros duplicados.

## Liquidacao e lastro contabil

O usuario pode registrar um recebimento/pagamento novo com conta financeira,
classificacao, valor e data, vinculando lancamento e saldo atomicamente.
Alternativamente pode associar um lancamento contabil previamente registrado.
A baixa administrativa e separada e nao gera movimento real de caixa.
O sistema diferencia valores contabilizados e baixados administrativamente.

Nao ha pagamento bancario externo, emissao fiscal homologada ou conciliacao
automatica implicita. Confirmar fatura cria titulo operacional, mas nao
contabiliza competencia. Documentos tributados ou com retencao precisam de
partidas revisadas: o fluxo automatico simplificado e bloqueado em PHP para
nao lancar indevidamente o valor bruto como receita/despesa.

## Gestao financeira e seletor

Cada gestao e uma unidade de isolamento com seus registros e permissoes.
Se o usuario so possui **uma gestao ativa**, a navegacao mostra seu nome,
sem seletor. O seletor so aparece com **duas ou mais gestoes ativas acessiveis**.

Na pagina **Gerenciar > Gestoes financeiras**, qualquer usuario autenticado
pode criar uma gestao. Somente o **proprietario** pode renomear, arquivar ou
restaurar as suas. Gestoes compartilhadas sao somente leitura para
administracao; papeis e permissoes sao controlados pelo servidor.

Arquivar **nao exclui** contas, documentos, diarios ou auditorias. Suspende
os acessos operacionais ate restauracao, disponivel ao proprietario, inclusive
quando a gestao arquivada era sua unica gestao ativa.
Nao ha exclusao definitiva de historicos contabeis nesta interface.

## Compatibilidade e limites

URLs historicas view=receivables, view=payables, view=sales, view=purchases,
view=invoicing e view=obligations abrem a agenda. Links
view=settings&section=access|fields|payments|modules|data seguem para
Pessoas e grupos, Campos personalizados, Formas de pagamento,
Recursos instalados ou Dados e migracao, respectivamente. Quando a URL indica
pagar/receber, o filtro correspondente e recuperado.
Contratos antigos das APIs financeiras permanecem validos; os novos filtros
sao opcionais e validados antes da paginacao.

Esta evolucao nao certifica migracao historica do Akaunting, contabilidade
por competencia, pagamento bancario externo nem emissao de NFS-e.
Essas frentes exigem homologacoes proprias.

<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# ADR 0003: Uma interface financeira, domínios coesos e extensões regionais

- Situação: **arquitetura de interface implementada, consolidação física em fases**
- Data: 2026-11-21
- Abrangência: LibreFinance no Nextcloud 36 de laboratório
- Compatibilidade: Nextcloud + PHP, sem serviço Node no tempo de execução

## Problema observado

Seis aplicativos funcionais foram entregues com seus próprios formulários, estilos,
endpoints, controles de navegação e páginas isoladas. A interface do núcleo reunia
cadastro, ledger, contas, permissões, AI, importador e diversos formulários em um
único componente com ~900 linhas e abria módulos em iframe.

Esse desenho não acompanha o fluxo do usuário: visão geral → venda/compra →
fatura/conta a pagar → banco → conciliação → lançamento → relatório.
O número de aplicativos instalados não deve determinar quantas interfaces o
usuário precisa aprender. A experiência do Akaunting, Xero, QuickBooks e Wave
foi consultada somente como referência de arquitetura de navegação; não há
CSS, template, componente ou código copiado desses produtos.

## Decisão implementada agora

1. **Uma entrada visual**: `/index.php/apps/librefinance/` aceita a navegação
   tipada `view` e o `spaceId` autorizado; o valor de `view` tem lista
   fechada. Um sidebar de `NcAppNavigation` agrupa visão geral, operações,
   análise/cadastros e administração.
2. **Nenhum iframe**: o shell usa `NcContent`, `NcAppContent`, `NcDialog`,
   `NcButton`, `NcSelect`, `NcTextField`, `NcNoteCard` e outros componentes
   oficiais `@nextcloud/vue` (v9.14.1, Vue 3), com ícones Material Design.
   Tabelas HTML semânticas usam variáveis de tema Nextcloud porque a versão
   da biblioteca instalada não fornece um componente de data grid.
   Entrada de arquivos usa `<input type=file>` nativo oculto e ação
   `NcButton`.
3. **URLs legadas preservadas por redirecionamento autorizado**: o
   PageController de cada módulo verifica `FinanceApi::requireModule` no
   servidor antes de redirecionar exclusivamente à nova interface, com
   `spaceId` numérico e `view` constante. Não há redirecionamentos arbitrários.
   Endpoints existentes e tabelas permanecem inalterados.
4. **Dados por domínio**: a SPA consulta endpoints protegidos dos módulos,
   mas os apresenta como partes de um produto só. Uma resposta de um espaço
   obsoleta não pode sobrescrever o espaço mais recentemente selecionado.
   Falhas de consultas independentes não devem ocultar dados válidos.
5. **Operações com estados explícitos**: um extrato pendente NÃO é uma
   partida contabilizada; uma baixa operacional NÃO liquida o razão
   automaticamente; NFS-e permanece rascunho. Saldos importados não são
   inscritos no livro contábil automaticamente.

## Agrupamento de domínios recomendado

| Domínio de negócio | Superfície do produto | Backend atual | Destino arquitetural |
|---|---|---|---|
| Espaços, empresas, identidade, grupos, papéis | Administração | `librefinance` | **Core** |
| Plano de contas, lançamentos, saldos, categorias | Contabilidade | `librefinance` | **Core** (livro razão autoritativo) |
| Clientes, fornecedores, produtos e serviços | Cadastros | `librefinance` | **Core** |
| Vendas/compras, faturas, impostos genéricos | Faturamento | `librefinance_invoicing` | **Core** ao validar migração de tabelas |
| Contas a pagar/receber e baixas | Compromissos | `librefinance_obligations` | **Core** ao unificar vínculos com faturas |
| Extratos e conciliação bancária genérica | Bancos | `librefinance_banking` | **Core** para extratos e conciliação |
| Relatórios genéricos, pesquisa, exportação | Análise | `librefinance_reports` | **Core**, adaptadores para relatórios especiais |
| Importador/migração e validação de origem | Administração | `librefinance_import` | **Core**, operação de migração ativável |
| NFS-e, DPS, ADN, documentos e legislação brasileira | Fiscalidade Brasil | `librefinance_nfse` | **Aplicativo opcional independente** |
| Sicoob, OAuth/mTLS, webhooks e credenciais | Provedor bancário | ainda não implementado integralmente no novo app | **Aplicativo conector opcional independente** |
| Rateios e produção cooperativista | Produção cooperativa | ainda não implementado no Nextcloud | Extensão de domínio **opcional** com contratos explícitos |
| AI genérica TaskProcessing | Assistente | `librefinance` | **Core**, consentimento antes de saída de dados |

O core deverá manter contratos públicos específicos para provedores, ao invés
de importar módulos Laravel ou de assumir APIs regionais como universais.

## Limites de transição para não perder dados

**Não copiar fisicamente nem apagar aplicativos agora.** Vários aplicativos
possuem tabelas e migrações próprias (`lfi_*`, `lfb_*`, `lfob_*`,
`lfm_*`, etc.). A consolidação física exige plano de rollout, migração de
esquema com rollback, chaves de origem idempotentes, reexecução em
SQLite/PostgreSQL/MariaDB, permissões por empresa e compatibilidade de
endpoints antes de desabilitar um módulo.

1. Primeiro integrar as telas e redirecionar URLs, mantendo contratos existentes
   (feito neste incremento).
2. Depois extrair serviços de domínio públicos para o core por contrato,
   com testes diferenciais e versão de API.
3. Transferir persistência apenas após um caminho de migração **reversível**
   em bases existentes, incluindo chaves e auditoria.
4. Retirar o app antigo somente após não haver referências ao seu banco,
   usuários ou rotas que ainda dependam de sua implementação.
5. Provar integridade de contas, documentos e fiscalidade **por ID e valor**;
   coincidência de contagem é insuficiente para afirmar 100% de paridade.

## Riscos e testes esperados

- Segurança: menu oculto não substitui ACL PHP, `requireModule` e papel.
  Tentativa de acesso direto negada, proteção multiempresa verificada no servidor.
- Mobile e tema: validar viewport 390px, dark/light, leitor de tela, foco
  visível, Escape em diálogos, navegação por teclado e reduced motion.
- Concorrência/UI: espaço A → B em navegação rápida; cancelar/ignorar respostas
  obsoletas; cancelar tarefas no `onBeforeUnmount`.
- Integrações: não enviar NFS-e real, não copiar OpenBao, não migrar dados
  contábeis reais ou contornar autenticação durante teste visual.
- CI: validar build, lint, API+Behat, PostgreSQL/MariaDB/SQLite e Chromium
  sobre versões instaladas; arquivos de migração e tela devem ser testados
  juntos antes de revisão/merge.

## Estado de conclusão

Interface única e controles oficiais foram implementados localmente. A
consolidação física das tabelas em core, as emissões fiscais reais e a paridade
funcional integral são **pendências**, nunca evidências concluídas por esta ADR.

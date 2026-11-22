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
- **Areas financeiras:** unidade de isolamento do dinheiro, contas, historico
  e permissoes; aparece fora dos cadastros de empresa, junto ao seletor.
- **Gerenciar:** contatos, produtos/servicos, categorias, dados fiscais de
  empresas, plano de contas, lancamentos contabeis, regras fiscais, NFS-e,
  assistente, acessos, campos personalizados, formas de pagamento e migracao,
  conforme as permissoes do usuario.

Nao ha menus separados e permanentes para Contas a receber e Contas a pagar,
nem dois grupos distintos de Cadastros e Mais ferramentas. Gerenciar apresenta
quatro **grupos recolhiveis nativos do Nextcloud** (Cadastros, Financeiro e
fiscal, Equipe e personalizacao, Recursos e dados); cada funcionalidade e
aberta diretamente, sem outra pagina ou abas de Configuracoes. As URLs antigas de Configuracoes
continuam redirecionadas para a funcionalidade correspondente.

## Agenda unica, mas nao extrato bancario

A agenda reune **valores previstos**, identificados como entrada ou saida.
Cada linha exibe direcao, origem, valor e saldo com sinal, vencimento, situacao
e acoes. Filtros por direcao (ambos, a receber, a pagar), situacao e vencimento
(todos, vencidos, proximos sete dias) sao aplicados pelo servidor antes da
paginacao. Direcao, situacao e vencimento usam botoes curtos em vez de
seletores com tres opcoes. Cabecalhos de referencia, descricao, vencimento e
valor previsto ordenam por consulta SQL e continuacao por cursor de ID:
nenhum clique ordena apenas as primeiras cinquenta linhas carregadas.
A busca textual por descricao/referencia tambem filtra antes da paginacao. Filtrar ou navegar nao transforma uma cobranca aberta em entrada
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

## Areas financeiras, dados de empresas e seguranca de exclusao

Uma **area financeira** (API `space`) isola o proprio livro financeiro,
contas, historico, categorias e permissoes. Exemplo: "Minha casa" e "Meu
negocio" precisam de areas separadas, nao necessariamente duas empresas
juridicas. **Dados fiscais da empresa** sao apenas cadastros legais dentro
da area ja existente; uma area pode conter mais de uma empresa, e uma area
pessoal pode nao conter nenhuma. Isso nao cria outra contabilidade.

A pagina **Areas financeiras** esta acessivel ao lado do seletor de area,
fora do menu de cadastros. Quando existe apenas uma area ativa, aparece
o nome, sem seletor. Um usuario autenticado pode criar areas; apenas
o **proprietario** pode renomear, arquivar ou restaurar as suas. A lista
usa o `NcListItem` oficial do Nextcloud com icones de contexto e menu
nativo de abrir, renomear, arquivar ou restaurar.

**Excluir permanentemente** nao aparece por uma razao de integridade:
existem referencias e auditoria do nucleo, de faturamento, obrigacoes,
bancos, importacao, relatorios e do app separado de NFS-e, sem uma garantia
de cascata segura e sem barreiras de escrita entre todos os modulos.
Arquivar e reversivel e preserva lancamentos, documentos e trilha de
auditoria. Nao implementar exclusao em cascata nem apagar somente a linha
`lf_spaces`, o que deixaria dados orfaos. Uma eventual exclusao de area
virgem requer contrato atomico para instalacao multi-modulo, testes de
concorrencia e auditoria da retencao obrigatoria. Esta revisao **nao
introduz** exclusao definitiva.

## Compatibilidade e limites

URLs historicas view=receivables, view=payables, view=sales, view=purchases,
view=invoicing e view=obligations abrem a agenda. Links
view=settings&section=access|fields|payments|modules|data seguem para
Acessos e compartilhamento, Campos personalizados, Formas de pagamento,
Recursos instalados ou Dados e migracao, respectivamente. Quando a URL indica
pagar/receber, o filtro correspondente e recuperado.
Contratos antigos das APIs financeiras permanecem validos; os novos filtros
sao opcionais e validados antes da paginacao.

Esta evolucao nao certifica migracao historica do Akaunting, contabilidade
por competencia, pagamento bancario externo nem emissao de NFS-e.
Essas frentes exigem homologacoes proprias.


## Pessoa fisica, casa e organizacao

O aplicativo usa o mesmo motor de partidas contabeis e pagamentos para
tres tipos de gestao: `personal` (pessoal ou da casa), `collective` (grupo)
e `organization` (empresa ou organizacao). Cada gestao tem contas e registros
isolados. Uma gestao pessoal **nao exige** empresa, CNPJ ou emissao fiscal.

Na gestao pessoal, o painel explica movimentos realizados e o cadastro de
contatos se apresenta como **Pessoas e estabelecimentos**; as classificacoes
`customer`, `vendor` e `both` sao apresentadas como **De quem recebo**,
**A quem pago** e **Pago e recebo**. Nenhuma classificacao existente no banco
e alterada. O cadastro de empresas permanece **opcional**: util para quem
tambem registra documentos empresariais, mas dispensavel para a casa.
Controles avancados permanecem acessiveis por Gerenciar com as mesmas
permissoes da gestao.

Financas pessoais e empresariais nao devem compartilhar automaticamente
saldos, clientes ou razao; para acompanhar um negocio individual crie
uma gestao separada. Nao se deve confundir os novos rotulos com suporte ja
homologado a orcamentos familiares, metas, consolidacao de varias gestoes,
impostos pessoais ou sincronizacao bancaria.

## Atalhos discretos para os campos personalizados

O editor de definicoes e unico, em Gerenciar > Campos personalizados.
**Personalizar campos** e uma acao terciaria e padronizada nas telas de
empresas, contatos, produtos/servicos e lancamentos contabeis. Apenas
gestores e proprietarios veem o atalho. Ele abre diretamente a entidade
correspondente do editor e respeita o `?entity=` da URL, inclusive em
recargas de pagina e favoritos, sem criar um segundo CRUD. O PHP valida
permissoes e tipos independentemente de exibir/ocultar o atalho no frontend.

Acessos e compartilhamento em Gerenciar administra usuarios e grupos Nextcloud: nao
suportam novos campos no atual contrato `company/contact/item/journal`.
A administracao de acessos nao deve prometer um campo configuravel sem
migracoes, API e regras de privacidade proprias.


## Busca global do Nextcloud e filtros locais

O provedor `librefinance-records` implementa `OCP\Search\IInAppSearch` e
e registrado via `IRegistrationContext::registerSearchProvider()`. A busca
do cabecalho do Nextcloud pesquisa compromissos, contatos, produtos/servicos
e empresas, apenas nas areas a que o usuario autenticado tem acesso. Ela
respeita modulos de obrigacoes desativados e evita retornar valores,
documentos e identificadores fiscais no resumo. Uma busca por pessoa nao
pode descobrir a existencia dos registros privados de outra.

Ao selecionar um resultado, o Nextcloud abre o LibreFinance na area e
entidade corretas, com o termo de busca na URL e aplicado ao servidor.
O campo da propria lista e independente da busca global e pode pesquisar
em todas as paginas dos cadastros de empresas, contatos, itens ou da
agenda. Isso nao implica que digitar no cabecalho altere automaticamente
os dados da pagina abaixo: a API oficial fornece resultados e links.

As buscas e a ordenacao textual usam `OCP\DB\QueryBuilder::iLike()` e
parametros vinculados, com escaping de wildcards, sem concatenar SQL vindo
da URL. As consultas filtram por autorizacao antes de limitar a resposta,
e respostas assincronas atrasadas nao sobrescrevem resultados de outra area.

## Padroes de listagem e limites restantes

Listas de **areas financeiras** usam `NcListItem` e menus `NcActionButton`.
Na **agenda financeira**, tabela semantica (o pacote Nextcloud Vue nao
disponibiliza um `NcTable` generico), `NcButton` nos cabecalhos, atributo
`aria-sort`, menus `NcActions` e os filtros no mesmo layout de densidade
dos demais apps. As demais tabelas aproveitam os tokens de tema e estilos
compartilhados; transformar todos os endpoints em grade ordenavel exige
contratos proprios por tipo, nao uma ordenacao apenas client-side de
listas paginadas. Nao se deve confundir estes ajustes com a homologacao
integral de todas as listas ou com uma nova biblioteca de tabelas.

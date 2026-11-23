<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Navegacao e operacoes financeiras

O LibreFinance possui navegacao orientada a tarefas:

- **Visao geral:** indicadores calculados a partir dos lancamentos reais.
- **Financeiro:** um registro principal de compromissos previstos e uma
  fila unica de tarefas a concluir. Os compromissos de faturas confirmadas
  entram no mesmo registro de contas a pagar/receber avulsas.
  - **Documento ainda em elaboracao:** aparece na fila como rascunho e so
    vira compromisso financeiro depois da confirmacao comercial.
  - **Extrato importado pendente:** aparece na mesma fila como evidencia a
    conciliar e nao como uma segunda receita, despesa ou obrigacao.
  - **Consultar:** abre a consulta detalhada de documentos comerciais ou
    extratos, sem criar abas permanentes nem um CRUD operacional paralelo.
- **Relatorios:** demonstrativos, com segregacao por gestao.
- **Gestoes financeiras:** unidade de isolamento do dinheiro, contas, historico
  e permissoes; configurada em Gerenciar, nao como cadastro cotidiano.
- **Gerenciar:** contatos, produtos/servicos, categorias, dados fiscais de
  empresas, plano de contas, lancamentos contabeis, regras fiscais, NFS-e,
  assistente, acessos, campos personalizados, formas de pagamento e migracao,
  conforme as permissoes do usuario.

Nao ha entradas independentes no menu lateral nem abas permanentes
para Faturas ou Bancos e caixa. O registro Financeiro apresenta de forma
integrada compromissos e pendencias; a consulta completa e contextual. Somente um menu
**Novo** separa dinheiro ja movimentado (Despesa ja paga, Receita ja
recebida), compromisso futuro (Conta a pagar, Cobranca a receber) e
documentacao comercial (Fatura de venda, Fatura de compra).
As movimentacoes ja realizadas abrem o mesmo formulario e o mesmo livro
contabil existentes em Lancamentos contabeis; nao criam um novo CRUD
nem exigem titulo avulso artificial. A acao **Importar extrato** tambem fica nesse
cabecalho. O fluxo abre diretamente o formulario escolhido, sem passar por
um segundo dialogo de selecao. Nao existem menus separados e permanentes
para Contas a receber e Contas a pagar, nem dois grupos distintos de
Cadastros e Mais ferramentas. Gerenciar apresenta
cinco **grupos recolhiveis nativos do Nextcloud** (Gestoes e identidade,
Cadastros, Financeiro e fiscal, Equipe e personalizacao, Recursos e dados); cada funcionalidade e
aberta diretamente, sem outra pagina ou abas de Configuracoes. As URLs antigas de Configuracoes
continuam redirecionadas para a funcionalidade correspondente.

## Agenda unica, mas nao extrato bancario

A agenda reune **valores previstos**, identificados como entrada ou saida.
Cada linha exibe direcao, origem, valor e saldo com sinal, vencimento, situacao
e acoes. Filtros por direcao (ambos, a receber, a pagar), situacao e vencimento
(todos, vencidos, proximos sete dias) sao aplicados pelo servidor antes da
paginacao. Direcao, situacao e vencimento usam menus de filtro NcPopover
juntos na barra superior da lista, ao lado da pesquisa, sem grupo solto
acima da tabela. Cabecalhos de referencia, descricao, vencimento e
valor previsto ordenam por consulta SQL e continuacao por cursor de ID:
nenhum clique ordena apenas as primeiras cinquenta linhas carregadas.
A busca textual por descricao/referencia tambem filtra antes da paginacao.
Filtros relativos a "hoje" usam a data local do navegador, validada pelo
servidor numa janela maxima de um dia em torno do dia UTC. A mesma data
de referencia e reutilizada durante a paginacao por cursor, evitando que
uma passagem da meia-noite altere registros durante a leitura. Este
parametro opcional controla apenas a consulta, nao muda vencimentos nem
a contabilizacao. Filtrar ou navegar nao transforma uma cobranca aberta
em entrada contabilizada. O registro importado do banco aparece em **Conferir extrato**
e permanece pendente ate ser reconciliado com lancamento contabil. Importar
nao significa pagar ou receber automaticamente.

Para registrar uma operacao, clique em **Novo** no topo do Financeiro:
**Conta a pagar** ou **Cobranca a receber** criam compromissos avulsos,
sem fatura, com valor e vencimento. **Fatura de venda** ou **Fatura de
compra** abrem diretamente a edicao de itens, contatos e tributos. Uma
fatura confirmada cria seu titulo vinculado. Nao cadastre novamente esse
titulo no painel A pagar e receber. O titulo oferece Abrir fatura, e a
fatura oferece Ver conta vinculada.

**Importar extrato** abre a conferencia bancaria e solicita conta e CSV.
O extrato nao e outra lista de contas: e uma evidencia externa
pendente que aparece na fila de tarefas do Financeiro. Selecionar
**Conciliar** na fila abre o dialogo de correspondencia da linha exata,
sem registrar automaticamente pagamento. Uma evidencia nao
cria uma nova despesa, receita, obrigacao ou pagamento; localizar e
vincular o lancamento contabil existente e a primeira alternativa.
Se o usuario tiver apenas o movimento no extrato e nenhum lancamento
interno, deve registrar e contabilizar a movimentacao correta, conforme
a classificacao; depois realiza a conciliacao. A baixa administrativa
sem movimentacao e distinta de uma liquidacao contabil. Transferencias
entre contas proprias precisam ser verificadas nas duas pontas, sem
duplicar receita ou despesa.

## Padroes nativos de interface

As listas de dados usam diretamente a superficie do NcAppContent, sem
borda decorativa de card sobre a tabela. Nenhum cabecalho/coluna aparece
quando nao ha linhas: o NcEmptyContent diferencia lista vazia, filtro sem
resultado e falta de configuracao inicial. Bancos e caixa apresenta filtros
de conta e situacao no alto da propria lista, alem dos estados distintos
para falta de conta financeira, extrato vazio e transferencias inexistentes.

Contas, categorias, contatos, itens, empresas, extratos e documentos
comerciais usam cabecalhos acionaveis para ordenar as linhas **carregadas**.
Para essas listas, a ordenacao nao representa todas as paginas do servidor;
o componente sinaliza explicitamente o escopo local. A agenda de obrigacoes
tem ordenacao no servidor antes da paginacao, evitando falsos resultados
em listas financeiras extensas. Os valores monetarios sao comparados
em unidades inteiras, e a ordenacao de texto segue pt-BR.

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

## Gestoes financeiras, identidade juridica e seguranca de exclusao

Uma **area financeira** (API `space`) isola o proprio livro financeiro,
contas bancarias e caixa, saldos, historico, categorias e permissoes.
Ela nao corresponde a uma empresa juridica, departamento ou centro de custos:
criar outra area impede a visualizacao de caixa e titulos em um unico
livro. Crie outra area apenas quando quiser uma gestao realmente isolada,
como "Minha casa" e "Meu negocio". Nao crie uma area somente para
adicionar outra filial ou alterar dados cadastrais. **Dados fiscais da
empresa** sao identidades juridicas para documentos dentro de uma area.
Uma area pode conter mais de uma identidade fiscal, mas isso NAO cria
livros contabilizados ou demonstracoes separadas por empresa. Se duas
entidades juridicas precisarem de caixa, apuracao, livros ou acesso
independentes, devem usar areas financeiras distintas ate existir
segregacao por empresa homologada no nucleo. Uma area pessoal pode nao
conter empresa alguma.

### Cadastro completo da identidade juridica

Em **Gerenciar -> Dados fiscais da empresa**, use **Cadastrar empresa**
ou a acao de edicao da empresa desejada. O formulario apresenta grupos
de identificacao legal e comercial, pais, identificador fiscal, inscricoes
estadual/regional e municipal/local, contato (e-mail, telefone, site),
endereco postal, logotipo privado e campos personalizados por entidade.
As inscricoes ja pertenciam ao banco, mas estavam escondidas no frontend.
Os dados padronizados de contato e endereco sao introduzidos pela migration
aditiva `Version000400Date20261122233000` e nao modificam nenhum registro
financeiro. A versao de desenvolvimento e 0.18.1 para permitir upgrade
seguro de instalacoes existentes.

Campos especificos de um pais, forma tributaria, regime fiscal ou
requisito municipal devem ser definidos no editor **Campos personalizados**
ou em um modulo fiscal homologado. O cadastro nao verifica CNPJ ou outras
identidades perante a Receita, nao transfere saldos e nao configura
emissao fiscal homologada. Empresas existentes permanecem editaveis;
clientes de API antigos que nao enviam o objeto opcional `profile`
nao apagam endereco e contatos que ja estao armazenados.

### Formas de pagamento sem registros

Quando nao existem metodos, exibir `NcEmptyContent` e apenas a acao
primaria para cadastrar; nao renderizar tabela ou cabecalhos vazios.
Com registros, cabecalhos ordenam os itens carregados e `NcActions`
oferece editar/excluir. A exclusao continua restrita pelo backend
a metodos sem lancamentos vinculados. Os filtros de extrato bancario
tambem ficam ocultos em uma lista genuinamente vazia.

A pagina **Gestoes financeiras** esta em Gerenciar > Gestoes e identidade,
nao como acao permanente ao lado do seletor. Quando existe apenas uma area ativa, aparece
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

URLs historicas view=receivables, view=payables e view=obligations
abrem a agenda, com a direcao adequada. URLs historicas view=sales,
view=purchases e view=invoicing abrem o painel Faturas dentro de Financeiro,
preservando o tipo quando informado. view=banking abre Conferir extrato,
sem uma entrada propria no menu lateral. Links
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

Listas de **gestoes financeiras** usam `NcListItem` e menus `NcActionButton`.
Na **agenda financeira**, tabela semantica (o pacote Nextcloud Vue nao
disponibiliza um `NcTable` generico), `NcButton` nos cabecalhos, atributo
`aria-sort`, menus `NcActions` e os filtros no mesmo layout de densidade
dos demais apps. As demais tabelas aproveitam os tokens de tema e estilos
compartilhados; transformar todos os endpoints em grade ordenavel exige
contratos proprios por tipo, nao uma ordenacao apenas client-side de
listas paginadas. Nao se deve confundir estes ajustes com a homologacao
integral de todas as listas ou com uma nova biblioteca de tabelas.


## Identidade da gestao, faturas e estados vazios (0.18.2)

As rotas historicas de documentos (view=documents, invoicing, sales e
purchases) permanecem inalteradas, mas agora apontam para a consulta
contextual do documento dentro do Financeiro; view=banking abre a
conferencia de extratos. A tela principal view=finance nao possui
abas independentes para estas origens.
Uma fatura confirmada cria o compromisso vinculado em A pagar e receber.
Uma cobranca ou conta sem fatura tambem cria compromisso, porem sem
documento detalhado. Conciliacao bancaria e contabilizacao real sao
operacoes separadas; nao criar cobranca novamente ao confirmar a fatura.

Quando uma gestao nao tem valores a pagar/receber nem faturas, a lista
correspondente usa NcEmptyContent sem filtros, cabecalhos ou avisos
permanentes. Quando ha registros, os filtros ficam disponiveis; a pesquisa
sem resultados ainda permite Limpar filtros. Em Bancos e caixa, o extrato
continua sendo a lista principal. Transferencias internas nao criam
receitas ou despesas e aparecem apenas como historico recolhido se existirem;
isto nao implica pareamento ou reconciliacao automatica das pontas.

Gestoes financeiras ficam sob Gerenciar > Gestoes e identidade. Uma
gestao (space) corresponde a um livro financeiro independente, pessoal,
coletivo ou organizacional, mas nao e automaticamente uma pessoa juridica.
Ao criar gestao de organizacao, o formulario da identidade juridica e
oferecido na sequencia, com nome, pais, cadastro fiscal, endereco, contatos,
logotipo e campos personalizados por entidade Company. A imagem continua
em armazenamento privado do Nextcloud apos salvar a empresa.

NcColorPicker nativo do Nextcloud permite escolher cores independentes para
a gestao e para a identidade da empresa. A migration aditiva
Version000500Date20261122213000 cria colunas accent_color em lf_spaces e
brand_color em lf_companies, com padrao #64748B, validacao hexadecimal e
compatibilidade de API: color opcional no cadastro/edicao de gestoes e
brandColor dentro de profile no cadastro/edicao da empresa. Nao alterar o
tema global ou apagar registros anteriores.

Empresas na mesma gestao compartilham os mesmos saldos e permissoes.
Cores, logo ou campos adicionais nao sao contabilidade segregada por CNPJ;
nao prometer paridade completa com Akaunting, fiscalidade homologada ou
conciliacao automatica.


## Registro operacional unico e fila de trabalho (revisao 2026-11-22)

O usuario nao deve precisar decidir em qual CRUD esta a mesma obrigacao.
A fonte do valor devido e a obrigacao; quando uma fatura confirmada a
origina, a relacao lf_invoice_obligation_links permite abrir os detalhes
do documento sem cadastrar outro compromisso. A tela Financeiro exibe
apenas a obrigacao para faturas confirmadas, inclusive parcialmente pagas
ou liquidadas. Nao soma nem exibe novamente a fatura nessa agenda.

A fila de documentos usa GET /invoices?status=attention: o filtro
server-side seleciona rascunhos e tambem documentos historicos emitidos
SEM vinculo verificado a obrigacao. Nao faz match por valores ou referencias.
A selecao ocorre antes da paginacao e respeita o indice composto de vinculos
por gestao financeira e fatura. Documentos ja emitidos com vinculo
confirmado ficam SOMENTE na agenda de obrigacoes.
GET /transactions?status=pending retorna apenas evidencias bancarias
ainda nao conciliadas. As duas consultas continuam individualmente paginadas,
sem soma financeira ficticia ou alegacao de contagem total a partir da
primeira pagina. Concluidos/conciliados nao retornam nessa fila.
Os filtros historicos de status das faturas continuam disponiveis sem
mudanca para consumidores da API.
As consultas completas continuam disponiveis pela acao secundaria Consultar,
e os enderecos historicos sao preservados.

O motor financeiro nao foi unificado fisicamente: o modelo de faturas
contem documentos comerciais e itens, o de obrigacoes contem saldos e
vencimentos e o extrato preserva movimentos externos para conciliacao.
Consolidar esses tres dominios em um CRUD ou tabela unica eliminaria
vinculos auditaveis e criaria problemas para liquidacoes parciais e
conciliacoes. Na interface, esta separacao e detalhe de implementacao.

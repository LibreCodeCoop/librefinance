<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Como usar o Financeiro

O LibreFinance abre com uma unica **Visao geral**. A operacao diaria acontece
em **Financeiro > Movimentacoes**. Quando o recurso opcional esta habilitado,
**Financeiro > Faturas** apresenta vendas e compras na mesma pagina.

Os nomes das telas nao sao nomes de tabelas ou termos contabeis. A pessoa
escolhe o que deseja fazer e o sistema utiliza os registros financeiros
correspondentes sem duplicar valores.

## Registrar uma entrada ou saida

1. Abra **Movimentacoes** e clique em **Registrar**.
2. Escolha **Entrada** (dinheiro que recebo) ou **Saida** (dinheiro que pago).
3. Escolha **Ja aconteceu** ou **Vai acontecer**, quando agendamentos estiverem
   habilitados na gestao.
4. Informe descricao, valor e data. Quando o dinheiro ja movimentou, informe
   a conta financeira e, se houver mais de uma opcao, a categoria.

O sistema escolhe a unica classificacao possivel quando isso e seguro.
Nao inventa a conta quando existem varias opcoes validas. Se a operacao
ainda nao aconteceu, registra somente o valor previsto. O registro previsto
nao altera o saldo contabilizado. A operacao ja realizada registra uma
movimentacao contabil de verdade.

Se a conta ou cobranca ja aparece na lista, utilize **Pagar** ou **Receber**
na propria linha. Isso registra o pagamento e vincula a operacao original,
sem cadastrar uma segunda receita ou despesa. Valores parcialmente pagos
continuam com o saldo previsto na lista.

### Prévia de recorrência

Em uma movimentação prevista autônoma ainda aberta e sem pagamento,
selecione **Pré-visualizar recorrência**. Escolha de 1 a 24 ocorrências,
intervalo de 1 a 12 meses e a regra para datas que não existem no mês
de destino (usar o último dia ou rejeitar). A prévia mostra o valor
original e as datas de emissão e vencimento calculadas a partir das
âncoras originais. Ao alterar uma opção, solicite uma nova prévia.
Por exemplo, um vencimento em 31/01/2028, com intervalo mensal e ajuste,
prevê 29/02/2028 e 31/03/2028. A consulta não cria títulos, não registra
pagamentos nem altera o saldo; a opção não aparece para faturas vinculadas
ou obrigações parcialmente pagas.

## Entender a lista

- **Prevista:** dinheiro que ainda falta pagar ou receber. Nao entra no saldo.
- **Realizada:** dinheiro efetivamente registrado em uma conta ou caixa.
- **A conferir:** movimento vindo do extrato bancario que ainda nao foi
  relacionado a um registro; nao e nova receita ou despesa.
- **Transferencia:** dinheiro saiu de uma conta propria e entrou em outra.
  Nao e uma nova receita ou despesa do conjunto.

A lista contem uma unica linha para cada movimento contabilizado e uma
linha para cada valor previsto ainda aberto, inclusive quando o valor
foi criado por uma fatura. Extratos pendentes aparecem identificados, sem
participar do saldo contabilizado. Depois da conciliacao, a evidencia
externa nao aparece como um segundo pagamento.

A barra superior filtra por conta, situacao e entrada/saida, e pesquisa pela
descricao. Esses filtros sao aplicados no servidor antes da paginacao.
Sem registros, sao ocultados; o Nextcloud mostra o estado vazio apropriado.
A tela ocupa a largura disponivel do aplicativo, incluindo monitores grandes.

## Vendas e compras detalhadas

Abra **Faturas** somente quando for necessario discriminar produtos, servicos,
quantidades, tributos ou manter um documento comercial detalhado.
A lista e unica e, quando existem registros, oferece o filtro **Tipo**:
**Todas**, **Vendas** e **Compras**. Esse filtro consulta o servidor antes da
paginacao; nao sao abas ou dois CRUDs diferentes.

Para criar, clique sempre em **Nova fatura**. A primeira etapa do mesmo dialogo
pergunta se a fatura e uma **Venda** ou **Compra**. Somente depois aparecem
os campos do documento e os itens. O filtro aplicado a listagem nao decide o
tipo do novo documento: isso precisa ser uma escolha explicita de quem cadastra.

Confirmar um documento cria a conta ou cobranca prevista vinculada quando
o modulo de obrigacoes estiver ativo. O documento nao significa que
o dinheiro entrou, nem significa nota fiscal autorizada pelo governo.

Quando houver pagamento, utilize o saldo em Movimentacoes. O vinculo com
o documento permanece disponivel sem outra entrada de receita ou despesa.
Se o documento tiver tributos ou retencoes, as salvaguardas do servico
contabil permanecem: nao e permitido presumir uma classificacao incorreta
somente para simplificar a interface.

## Contas, bancos, extratos e transferencias

O cadastro **Gerenciar > Cadastros > Contas e bancos** identifica onde o
dinheiro fica, incluindo dinheiro em caixa. A pessoa informa o nome da
conta; o codigo interno necessario para os registros e gerado pela interface,
sem exigir conhecimento contabil.

Em **Movimentacoes**, o controle **Todas as contas** faz parte dos filtros
da propria lista, junto de Tipo (Entrada/Saida), Situacao e Pesquisa.
Selecionar uma conta nao abre outro modulo: apenas limita o historico
consultado, e mostra seu saldo contabilizado, se houver.

O menu **Importar extrato** tem duas opcoes: **Arquivo CSV** e **Arquivo OFX**.
Ambas usam o seletor de arquivos nativo do Nextcloud, sem gravar a copia
original no Files. O CSV inclui o botao **Baixar exemplo de CSV**.
Quando existem varias contas, a pessoa precisa selecionar explicitamente
qual recebeu o arquivo. O arquivo nao registra outro pagamento:
cada movimento importado aparece em Movimentacoes com situacao
**A conferir**. Na propria linha, clique em **Conferir** e vincule
a uma movimentacao ja registrada com mesmo valor e conta. Ao confirmar,
a evidencia pendente deixa de aparecer como segunda linha. Se nenhum
registro correspondente foi encontrado, procure em movimentacoes mais
antigas ou registre o dinheiro uma unica vez antes de conferir.
Nao ha um novo CRUD, lista paralela nem tela obrigatoria de conciliacao.

Para detalhes de formatos, privacidade e configuracao dos campos internos,
consulte docs/OFX-AND-CUSTOM-FIELDS.md.

Quando houver pelo menos duas contas, a acao **Transferir entre contas**
registra uma transferencia ja realizada em uma unica operacao balanceada.
Ela nao envia dinheiro por qualquer banco real.

**Conexao automatica com bancos ainda nao esta disponivel.**
Instituicao, agencia, numero da conta, consentimento, credenciais seguras
e sincronizacao sao trabalho separado; nao informe senhas no cadastro.
Os contratos de providers atuais incluem apenas um adaptador manual.

## Recursos opcionais e gestoes distintas

Movimentacoes funciona mesmo sem o recurso de faturas ou agendamentos,
porque utiliza o livro contabil nativo. Quando o modulo de agendamentos
esta desativado, a opcao de dinheiro futuro nao aparece. Sem modulo
bancario, a conferencia de extratos nao aparece. Campos e acoes de
gerenciamento respeitam as permissoes de cada gestao.

Cada gestao financeira possui contabilidade e acesso isolados.
Gerenciar > Financeiro e fiscal mantem as ferramentas contabeis avancadas,
mas nao apresenta outro CRUD concorrente de entradas e saidas.
O backend e a fonte de verdade sobre permissoes, saldos, idempotencia,
liquidacoes, audibilidade e vinculacao documental.

## Para desenvolvedores: contrato da lista consolidada

GET /api/spaces/{spaceId}/activity

Parametros: cursor (opcional), direction=all|in|out,
state=all|planned|posted|pending, accountId (opcional), search (opcional).
Resposta: items e nextCursor. O cursor e composto por data, prioridade
da fonte e ID do registro. O backend ordena todas as fontes pelo mesmo
criterio, nunca junta paginas locais soltas do Vue.

Fontes, sem alterar tabelas ou migracoes:

- journals + postings de contas financeiras: fonte journal, efetivo,
  com countsAsCash=true;
- obligations abertas/parciais: fonte obligation, saldo previsto,
  com countsAsCash=false; documentos confirmados se vinculam a elas;
- movimentacoes de extrato pendentes: fonte statement, evidencia,
  com countsAsCash=false.

As consultas verificam a gestao e o modulo habilitado no servidor,
filtram antes da paginacao e nao presumem conciliacao automatica.
Ao selecionar uma conta especifica, a lista nao atribui arbitrariamente
uma conta bancaria a obrigacoes futuras ainda nao liquidadas.

A API continua somente leitura. Criar, pagar, conciliar e transferir
reutiliza os servicos e endpoints existentes com verificacao de permissao,
protecoes contra duplicidade, valores monetarios inteiros, auditoria
e verificacoes de concorrencia. A experiencia de usuario nao requer
nem substitui os detalhes contabeis de implementacao.

A refatoracao nao introduz migracoes, nao modifica o plano de contas
historico e nao habilita integracoes bancarias ou emissoes fiscais.

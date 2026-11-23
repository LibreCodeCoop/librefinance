<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Importação OFX/CSV e campos personalizados

## Como importar extratos

Em Financeiro > Movimentações, escolha Importar extrato > Arquivo CSV
ou Arquivo OFX. Escolha a conta à qual pertence o arquivo, quando houver
mais de uma. O seletor nativo NcFilePicker lê um arquivo local; não o
salva automaticamente no aplicativo Files.

A tela oferece Baixar exemplo de CSV. Esse exemplo sintético utiliza
as colunas id,date,amount,description e valores no formato 25.90 ou
-25.90 (saída), com datas YYYY-MM-DD. CSV deve ser UTF-8 e usar
identificadores externos estáveis.

OFX 1.x (SGML) e OFX 2.x (XML) são aceitos com um único BANKTRANLIST.
São reconhecidas codificações UTF-8, Windows-1252 e ISO-8859-1 conforme
o cabeçalho. Valores com mais de duas casas decimais só são aceitos
quando todas as casas extras são zero; não há arredondamento bancário.
Cada STMTTRN precisa conter FITID, DTPOSTED, TRNAMT e MEMO ou NAME.
O cabeçalho CURDEF, quando presente, deve indicar a moeda da gestão.
A data é a data bancária exportada, sem ajuste automático de fuso.
Arquivos com extensões proprietárias, múltiplas listas, DTD ou entidades
externas são recusados. Limites: 256 KiB e 500 movimentos por arquivo.

A importação cria somente evidências pendentes A conferir. Na mesma
lista, use Conferir para vincular a movimentação financeira já
registrada. Não há contabilização automática, transferência entre
bancos nem criação de duplicatas. Reimportações idênticas são ignoradas;
se um mesmo identificador indicar outro valor, data ou descrição,
todo o lote é recusado atomicamente.

O endpoint POST /api/spaces/{spaceId}/imports preserva o contrato
CSV com bankAccountId e csv. Para OFX aceita bankAccountId, format
igual a ofx, e a chave ofx com o conteúdo original. A operação exige
autenticação e acesso de operador à gestão e ao módulo bancário.

## Campos personalizados

Gerenciar > Campos personalizados lista definições agrupadas por
local de uso. Novo campo abre um diálogo que primeiro pergunta onde
o campo será utilizado:

- Empresa ou organização.
- Clientes e fornecedores (pessoas e estabelecimentos na gestão pessoal).
- Produtos e serviços.
- Faturas de venda.
- Faturas de compra.
- Movimentações já realizadas.

O usuário informa nome e tipo; um identificador técnico estável pode
ser gerado a partir do nome ou definido na seção avançada. É possível
configurar texto de ajuda, opções, largura, posição e ativação. Há até
30 definições por entidade, em cada gestão independente.

Os valores são preenchidos nos próprios formulários das entidades,
inclusive ao cadastrar uma fatura ou uma movimentação já realizada.
Uma conta prevista ainda não é um lançamento contábil e, por isso,
não usa os campos da entidade Movimentações realizadas. Campos internos
podem ser preenchidos pelos gestores nos mesmos formulários; não
exigem planilhas ou importação especial.

## Visibilidade e autorização

Mostrar no formulário é independente de Disponível na API comum.
O primeiro controla os formulários operacionais de não gestores.
O segundo controla o acesso a definições e valores para não gestores
autenticados. Para manter a privacidade, ao ocultar um campo na interface
a opção da API é desativada por padrão; pode ser explicitamente ativada
depois. Integrações antigas que omitam apiVisible recebem o mesmo
comportamento anterior: apiVisible acompanha visible na criação.

Gestores autenticados podem ler e editar inclusive campos internos,
restritos à API comum, dentro da própria gestão. A API administrativa
continua sendo uma API: a configuração não criptografa ou torna
os valores secretos. Campos não disponíveis para os operadores
não podem ser obrigatórios ao salvar, pois impediriam seu uso.

Desativar um campo mantém os valores históricos; não altera
automaticamente o comportamento contábil ou fiscal.

## Migração e compatibilidade

A migration Version000600Date20261122235500 cria a coluna
lf_custom_fields.api_visible com padrão verdadeiro. Na migração,
campos que anteriormente eram ocultos recebem api_visible falso
para preservar a restrição anterior.

Os endpoints de definição aceitam apiVisible opcional em POST/PUT.
A criação de fatura também recebe customFields, um mapa de ID de
definição para valor tipado, e devolve custom_fields nas leituras.

Referência conceitual: Field Studio for Akaunting, da LibreCode,
sob AGPL-3.0-or-later. A implementação Nextcloud não declara
paridade integral com os 14 tipos de campo ou com toda a API de
histórico, buscas e concorrência do módulo Akaunting.

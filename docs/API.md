<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Contratos JSON preliminares v0.8

Todos os caminhos abaixo usam `/index.php/apps/librefinance/api`. Identidade é fornecida pela autenticação Nextcloud. IDs de espaço pertencem aos dados da aplicação; não são grupos Nextcloud nem IDs da empresa fiscal.

## Criar espaço
`POST /spaces`: `{"name":"Tesouraria","kind":"collective","currency":"BRL"}`
Resposta 201: `{"id":1,"name":"Tesouraria","kind":"collective","currency":"BRL","role":"owner"}`

Tipos: personal, collective, organization. Moeda: somente BRL com duas casas decimais nesta versão. Multimoedas e regras de precisão por moeda permanecem futuros.

## Compartilhar
`POST /spaces/1/members`: `{"uid":"user2","role":"operator"}`
`POST /spaces/1/groups`: `{"gid":"cooperados","role":"viewer"}`
Resposta: `{"ok":true}`.

`DELETE /spaces/1/members/{uid}` e `DELETE /spaces/1/groups/{gid}` revogam imediatamente a concessão correspondente. Não é permitido revogar a propriedade do espaço.

Papéis: viewer (consulta), auditor (consulta), operator (lançamentos), manager (lançamentos, contas, acessos), owner (total). Acesso a outro espaço resulta em 404; violação de papel em espaço acessível resulta em 403. Owner não é transferível nesta versão.

## Contas
`POST /spaces/1/accounts`: `{"code":"1010","name":"Checking account","class":"asset","isBank":true}`
Resposta 201: conta criada com saldo inicial zero; moeda herdada do espaço.

Classes: asset, liability, equity, revenue, expense. Código é único por espaço.

## Lançamento
`POST /spaces/1/journals`:
```json
{
  "debitId": 1, "creditId": 3, "amountMinor": 2599,
  "date": "2026-11-20", "memo": "Receita de teste",
  "externalKey": "api:synthetic-example-001"
}
```
Resposta 201: `{"id":1,"replayed":false}`. A segunda submissão com a mesma chave e o mesmo conteúdo retorna o mesmo ID com `replayed:true`. Mesma chave com dados diferentes retorna 409. O valor sempre usa unidades menores inteiras, sem ponto flutuante.

O débito registra `+amountMinor`, o crédito registra `-amountMinor`; a soma é zero. A API é deliberadamente restrita a um par de contas neste marco. Lançamentos multi-linha exigirão contrato versionado.

`GET /spaces/1/journals?beforeId=100` devolve até 50 registros, com `nextBeforeId` para paginação autorizada.
`POST /spaces/1/journals/10/reverse` cria um lançamento oposto vinculado ao original
no mesmo espaço e preserva o histórico. A primeira chamada retorna HTTP 200 com
o ID do estorno e `replayed: false`. Uma nova chamada autorizada retorna o mesmo
ID com `replayed: true` quando já existe um estorno vinculado. Uma chave de
idempotência igual à chave histórica de estorno pode pertencer a um lançamento
comum. Nesse caso, o estorno e a repetição do lançamento comum retornam seus
respectivos IDs. As restrições de acesso e de vínculos com módulos também se
aplicam às repetições do estorno. Se a disputa pela gravação persistir, a API
pode retornar HTTP 409 com `{"error":"concurrent_change"}`. O cliente pode
repetir a mesma chamada de estorno.

## Contatos de clientes e fornecedores

`GET /spaces/1/contacts?afterId=0` lista até 50 por página e retorna `nextAfterId` para continuação. Leitura exige vínculo ao espaço; criação/edição exige manager ou owner.

`POST /spaces/1/contacts`: `{"name":"Cliente sintético","kind":"customer","email":"user@example.invalid","taxId":"00.000.000/0000-00"}`
Resposta 201: objeto de contato com id, nome, tipo, email, tax_id e ativo.
`PUT /spaces/1/contacts/{contactId}`: exige nome, kind e admite email, taxId e active.
Kind é customer, vendor ou both. Tax ID é um identificador cadastral, não uma verificação fiscal de CPF/CNPJ. IDs de outro espaço retornam 404.

## Catálogo de produtos e serviços

`GET /spaces/1/items?afterId=0` lista de forma paginada.
`POST /spaces/1/items`:
`{"sku":"SERV-001","name":"Serviço sintético","kind":"service","unitPriceMinor":25000,"description":"Descrição"}`
Resposta 201: objeto de item com id, sku, name, kind, unit_price_minor, currency e active.
`PUT /spaces/1/items/{itemId}`: atualização com sku, name, kind, unitPriceMinor, description e active.

Preços são inteiros em centavos (BRL) no domínio atual, inclusive zero. Estoque, tabelas de preços, unidades de medida e descontos ainda não estão presentes. Os módulos opcionais de fatura comercial e regras genéricas de imposto estão disponíveis, mas não equivalem a controle fiscal certificado ou emissão de nota.

## Extratos bancários
`GET /spaces/{spaceId}/transactions?bankAccountId=1&status=pending&from=2026-11-01&through=2026-11-30` consulta movimentações importadas no espaço autorizado. As datas de lançamento do extrato (`booking_date`) são inclusivas e independentes: omita `from` ou `through` para deixar o respectivo limite aberto. Use datas ISO gregorianas (`YYYY-MM-DD`); datas inválidas ou intervalo invertido retornam 422.
Os filtros de conta, situação e data são aplicados antes da paginação por ID. A resposta mantém `transactions` (até 50 registros) e `nextAfterId`; use os mesmos filtros nas páginas seguintes. Movimentações pendentes são evidências, não lançamentos no razão. Esta consulta não calcula totais de extrato.

## Movimentações financeiras
`GET /spaces/{spaceId}/activity` aceita `from` e `through` como limites inclusivos opcionais em datas ISO gregorianas. A data filtrada é a data efetiva do lançamento, o vencimento do compromisso ou a data do extrato, conforme a origem de cada movimentação. Os limites são combinados com os filtros existentes antes da paginação; mantenha os mesmos filtros ao utilizar `nextCursor`. O filtro não altera os valores nem transforma compromissos ou evidências em lançamentos realizados.

## Previsão de recorrência
`GET /spaces/{spaceId}/obligations/{obligationId}/recurrence-preview?count=2&stepMonths=1&endOfMonth=clamp` prevê os próximos vencimentos de uma obrigação autônoma aberta e não paga. Os parâmetros são obrigatórios: count de 1 a 24, stepMonths de 1 a 12 e endOfMonth igual a clamp (ajustar para o último dia do mês) ou reject (rejeitar o dia indisponível). A previsão usa as datas originais de emissão e vencimento como âncoras, não cria títulos, não realiza lançamentos e não altera a obrigação consultada. Repetir a consulta com os mesmos dados retorna a mesma previsão. Entradas inválidas e modelos inelegíveis retornam 422; usuários sem acesso recebem 404.

`GET /spaces/{spaceId}/invoices` aceita `issueFrom` e `issueThrough` como limites inclusivos opcionais em datas ISO gregorianas (`YYYY-MM-DD`). O filtro restringe a data de emissão (`issue_on`) antes da paginação e é combinado com kind, status e cursor. Datas inválidas ou intervalo invertido retornam o erro `invalid_invoice_period` (422).

## Erros
`{"error":"permission_denied"}` 403; `{"error":"space_not_found"}` 404; `{"error":"idempotency_conflict"}` 409; entrada inválida 422.
Falhas internas não devem revelar SQL, caminhos do host nem configurações.

## Fluxo de caixa realizado por conta
`GET /spaces/{spaceId}/cash-flow?from=2026-11-01&through=2026-11-30` mantém a agregação de todas as contas bancárias e de caixa. Informe `bankAccountId` para restringir os saldos e os movimentos mensais à conta bancária autorizada, inclusive inativa para consulta histórica. O resultado informa `bankAccountId` (0 para todas). Uma transferência interna é neutra na visão consolidada; na visão de uma conta, sua respectiva entrada ou saída compõe o saldo. Não são considerados compromissos futuros nem extratos pendentes.

## Contratos não implementados
Permissões granulares adicionais, estoque, contabilidade de múltiplas moedas, notas fiscais efetivamente emitidas, sincronização bancária online, migração integral do Akaunting, fechamento fiscal e demonstrações contábeis formais continuam fora desta versão. A importação pertence ao core; a NFS-e brasileira permanece um aplicativo opcional independente. A interface IA usa `OCP\\TaskProcessing` com prévia de dados e consentimento; o transporte MCP oferece quatro ferramentas de leitura, sem ferramentas de pagamento/contabilização. Ver [OpenAPI](OPENAPI.md), [MCP/IA](MCP-AI.md) e [testes](TESTING.md).


## Core normalized import contract

The manager-only core API accepts normalized JSON to preview and confirm
catalog, historical bank statement and paired-transfer imports. Source
identity coverage is read-only, including account references and audit
snapshots. Apply requires the exact returned preview hash and explicit
confirmation. Existing mapped records remain idempotent, source conflicts
are rejected, and bank movements are not posted to the ledger automatically.

Akaunting API client, remote connection setup, credentials, direct
extraction and full migration are not part of this PR. Source mapping
and historical reconciliation will be implemented separately.

## Immutable exchange-rate evidence (contract v1)

GET /spaces/{spaceId}/exchange-rates?afterId=0 returns the supported currency
metadata version, its deliberately bounded currency/precision map, up to
50 authorized rate snapshots in ID order and a nextAfterId cursor.

POST /spaces/{spaceId}/exchange-rates records a quote using sourceCurrency,
targetCurrency, rate (a positive exact decimal string), effectiveOn (a real
calendar date), sourceType (manual or imported) and sourceRef (a bounded
non-secret provenance identifier). Rate means target major units per source
major unit. The server resolves and stores both precisions. A successful
recording returns HTTP 200 with rate and replayed; a canonical equivalent
quote in the same book returns the original record. Corrections append
new snapshots; there is no update/delete endpoint or automatic rate choice.

Read requires currency.rates.read; recording requires currency.rates.manage
(manager or owner). Financial membership and SaaS restrictions remain
authoritative. Unrelated books do not share rates. Source references are
not credentials or URLs. No historical balance is converted, no journal is
created, no bank/FX provider is contacted. Ledger activation and mixed
currency posting remain separate deliverables.

### Native exchange-rate history

Financial members can open Exchange rates under Manage / Finance and tax.
Managers and owners can append rate evidence using the native Nextcloud
dialog. The supported currency list is obtained from the scoped API; the
form accepts a decimal string with a single decimal point or comma and
sends a canonical decimal-point string, never a floating-point number.
The read-only table displays the stored exact rate, date, currency pair,
provenance and actor. A correction adds another immutable record. The
screen does not change the ledger currency, choose a rate for a posting or
convert balances. Workspace changes discard in-flight UI results.

GET /spaces/{spaceId}/exchange-rates/{rateId}/preview?sourceMinor=1000
is an authenticated read-only conversion using one explicitly selected,
immutable snapshot within the current book. The amount is a canonical
signed minor-unit integer (including zero); decimal/exponent forms and
out-of-range input are rejected. Conversion uses the stored source and
target precisions, never the current currency metadata or locale. The
result contains the original snapshot, sourceMinor, targetMinor,
rounding=half_away_from_zero and postingCreated=false. A missing or
foreign snapshot returns 404, including when the caller may access both
books. No rate is selected automatically and no accounting state changes.

## Proposed versioned monetary contract

The [functional/original currency design](SCHEMA.md#proposed-functional-and-original-currency-ledger-contract-v2)
records the future v2 ledger boundary. It does not change these implemented
endpoints or activate foreign currencies. Existing BRL v1 monetary fields
remain exact integer minor units with their current semantics. A future
explicitly negotiated version must expose original and functional amount,
currency and stored precision separately; quote identity, provenance,
effective date, and adjustment/rounding policy are part of the immutable
financial fact. A functional-only adjustment is distinguished from an
original-unit movement. Never add unlike original units or present an
unsupported mixed-currency journal as a two-leg BRL entry. Preserve
authorization, idempotency and error semantics; unsupported v2 operations
through v1 must fail explicitly. Versioned OpenAPI changes, migration and
full writer/reader qualification are separate implementation work.

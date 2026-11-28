<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Contratos JSON preliminares v0.8

Todos os caminhos abaixo usam `/index.php/apps/librefinance/api`. Identidade é fornecida pela autenticação Nextcloud. IDs de espaço pertencem aos dados da aplicação; não são grupos Nextcloud nem IDs da empresa fiscal.

## Criar espaço
`POST /spaces`: `{"name":"Tesouraria","kind":"collective","currency":"BRL"}`
Resposta 201: `{"id":1,"name":"Tesouraria","kind":"collective","currency":"BRL","role":"owner"}`

Tipos: personal, collective, organization. A criação continua restrita a BRL com duas casas decimais; não há ativação de novas moedas.

As listas `GET /spaces`, `GET /spaces/archived`,
`GET /saas/v1/environments/{environmentId}/workspaces` e
`GET /spaces/{spaceId}/accounts` incluem, em cada registro autorizado,
`currency` (código armazenado), `currencyPrecision` (escala inteira ou
`null`) e `currencyMetadataVersion` (versão do registro de moedas).
Por exemplo, BRL retorna precisão 2, JPY retorna 0 e KWD retorna 3 no
registro versão 1. A precisão é metadado de leitura do registro atual,
não uma autorização de escrituração em moeda estrangeira nem um snapshot
histórico. Códigos históricos não reconhecidos permanecem inalterados e
retornam precisão `null`; consumidores não devem presumir duas casas.
Os valores monetários, as regras de acesso e os contratos de escrita
permanecem inalterados.

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

### Prévia e confirmação da importação de extrato

`POST /spaces/{spaceId}/imports/preview` recebe o mesmo arquivo e conta de
destino do contrato de importação existente:

```json
{"bankAccountId":1,"format":"csv","csv":"id,date,amount,description\nmov-001,2026-11-20,-25.90,Pagamento sintético\n"}
```

Para OFX, informe `format: "ofx"` e `ofx` com o conteúdo, sem `csv`.
O limite continua sendo 256 KiB e 500 movimentações; a prévia aplica o
mesmo parser estrito e a mesma autorização de importação, incluindo a
conta bancária ativa e as políticas vigentes. A moeda do OFX, quando
informada, deve corresponder à da conta. O contrato de importação
continua usando duas casas decimais.

O retorno 200 (`contractVersion: 1`) informa a conta, formato, moeda,
`currencyPrecision`, `counts` de `new`, `replayed` e `conflicting`,
e `rows` com a posição lógica, identificador externo normalizado, data,
valor inteiro em unidades mínimas, descrição e classificação. Uma linha
repetida tem o mesmo identificador na mesma conta, data, valor e descrição.
Conflitos não são sobrescritos. Se o parser rejeitar o arquivo inteiro,
`counts` será nulo, sem linhas parciais, e `fileError` terá
`classification` (malformed/unsupported), `code` e `row` quando
identificável (posição lógica dos registros, não linha física do arquivo).
Não há gravação de extrato, auditoria, arquivo ou lançamento nesta etapa;
`postingCreated` é sempre falso.

Quando `ready` for verdadeiro, a resposta inclui `previewHash`.
Confirme com `POST /spaces/{spaceId}/imports` reenviando o mesmo
payload e esse hash. Ele é vinculado à identidade autenticada, gestão,
conta, formato, conteúdo recebido, dados normalizados, moeda, políticas
efetivas e evidência existente pertinente. Não é uma credencial ou
reserva permanente. A confirmação reserva a conta na transação, revalida
a autorização e o parser, consulta o estado corrente e rejeita uma
prévia alterada com 409 (`bank_import_preview_changed`); revogação de
acesso ou conta indisponível mantém 403/404. Em caso de erro ou resposta
incerta, faça nova prévia. Nunca confirme automaticamente. A criação
registra somente evidência pendente e sua auditoria, não dinheiro
contabilizado. A resposta 201 mantém `imported`, `skipped` e `currency`.

O endpoint de importação continua compatível com clientes antigos que
não enviam `previewHash`; nesses casos aplica as validações e
idempotência existentes sem exigir etapa anterior. A interface nativa
exige confirmação após a prévia. Alterar arquivo, conta, formato ou
gestão invalida a prévia visível.


## Movimentações financeiras
`GET /spaces/{spaceId}/activity` aceita `from` e `through` como limites inclusivos opcionais em datas ISO gregorianas. A data filtrada é a data efetiva do lançamento, o vencimento do compromisso ou a data do extrato, conforme a origem de cada movimentação. Os limites são combinados com os filtros existentes antes da paginação; mantenha os mesmos filtros ao utilizar `nextCursor`. O filtro não altera os valores nem transforma compromissos ou evidências em lançamentos realizados.

## Previsão de recorrência
`GET /spaces/{spaceId}/obligations/{obligationId}/recurrence-preview?count=2&stepMonths=1&endOfMonth=clamp` prevê os próximos vencimentos de uma obrigação autônoma aberta e não paga. Os parâmetros são obrigatórios: count de 1 a 24, stepMonths de 1 a 12 e endOfMonth igual a clamp (ajustar para o último dia do mês) ou reject (rejeitar o dia indisponível). A previsão usa as datas originais de emissão e vencimento como âncoras, não cria títulos, não realiza lançamentos e não altera a obrigação consultada. Repetir a consulta com os mesmos dados retorna a mesma previsão. Entradas inválidas e modelos inelegíveis retornam 422; usuários sem acesso recebem 404.

`GET /spaces/{spaceId}/invoices` aceita `issueFrom` e `issueThrough` como limites inclusivos opcionais em datas ISO gregorianas (`YYYY-MM-DD`). O filtro restringe a data de emissão (`issue_on`) antes da paginação e é combinado com kind, status e cursor. Datas inválidas ou intervalo invertido retornam o erro `invalid_invoice_period` (422).

#### Commercial duplicate candidates (read-only)

`GET /spaces/{spaceId}/invoice-duplicate-candidates` accepts either the
existing document's positive `invoiceId` alone (plus the optional `afterId`
cursor), or explicit evidence: `kind` (invoice/bill), `contactId` (active,
workspace-scoped party), optional `reference`, `issueOn` (canonical
Gregorian date) and `totalMinor` (canonical positive decimal integer string
at the current workspace currency precision). Existing-document mode derives
all evidence from the authorized stored document and excludes that document
from the candidates. Evidence overrides in this mode are invalid.

The lookup always matches the same workspace, kind, contact and currency.
A candidate must have the same normalized reference OR the same exact total
and an issue date within three calendar days inclusive. The returned
`matchReasons` enumerate the matched evidence; each row is only a
`possible_duplicate`, not proof of identical origin, fraud or invalidity.
The three-day window is an explicit heuristic; amount alone or date alone
is insufficient. Unknown/invalid contact IDs and inaccessible documents
are not accepted. `incomplete_evidence` is distinct from `no_candidate`.
`candidates` pages are ordered by ascending ID, up to 50 per page, with
`nextAfterId` for the next page using the same evidence. The absence of a
candidate is not certification that a document is unique.

`knownDocumentId` identifies only the already stored native document in
detail mode. The current importer does not persist commercial document
origin mappings, so `originIdentityStatus` is explicitly `unavailable`.
Neither a reference nor a possible match asserts a source-system identity.
No invoice, obligation, settlement, journal, or balance is written or
deleted. Existing invoice number uniqueness rules remain unchanged.

In the native create dialog, Check possible duplicates opens a read-only
review using the selected party, document number, issue date and optional
explicit source-document total (net of applicable withholdings and inclusive
of applicable taxes). The evidence amount is not a draft line or a stored
invoice total, and entering it is optional. In document details the review
derives the stored evidence on the server and allows opening an authorized
candidate through the usual detail action. Editing evidence invalidates
previous results. Neither absence of matches nor dismissing the review
changes the existing save/confirm/settlement behavior.



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

For dated selection, provide all three parameters together:
`sourceCurrency=JPY&targetCurrency=KWD&asOf=2026-11-24`.
Codes are trimmed and normalized to uppercase, then checked against the
bounded registry; the date is a canonical Gregorian date. Optionally add
`sourceType=manual` or `sourceType=imported`. Selection returns every
eligible snapshot for that exact directed pair with effectiveOn <= asOf,
without choosing the newest, inverting a rate or resolving ambiguity.
Filtering precedes ID-ordered pagination; use the same parameters with
each nextAfterId. The response includes the normalized selection (sourceType
is null for all sources); an authorized empty result is HTTP 200 with
rates=[], not an authorization failure. Partial/invalid selection is 422.
Omitting all selection parameters retains the existing historical list.
A caller without workspace capability receives the existing denial.

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
The read-only table displays the stored exact rate, quote ID, date, currency
pair, both stored precisions, provenance and actor. To find dated evidence,
select the source and target currencies, accounting date and optionally the
source type, then choose Find rates. The list contains all matching immutable
candidates, in pages of 50, without automatically choosing a rate. Select
Preview conversion on a particular row and enter a signed amount in that
snapshot's source currency (including zero, with a decimal point or comma
and no grouping). The server verifies that the explicit ID still qualifies
for the applied selection, calculates the exact target minor units using
stored precision, and the interface displays the result at target precision.
Changing the filters invalidates preview actions until they are reapplied;
changing the amount invalidates its previous result. An empty selection
is distinct from a failed or unauthorized request; permission loss clears
visible evidence. No financial records are modified. A correction adds another immutable record. The
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
For an explicit dated selection, pass the same sourceCurrency,
targetCurrency, asOf and optional sourceType query parameters to preview.
The selected ID must satisfy the same predicate; a future, wrong-pair or
wrong-source snapshot returns the same 404 as a missing/foreign ID.
Without these parameters the backward-compatible historical preview is
unchanged. The selection result is not a posting authorization.

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

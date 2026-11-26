<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Modelo de dados e nomes de tabelas — LibreFinance

## Esquema físico consolidado do núcleo

`Version000100Date20261121190000` permanece intacta, criando as 16
tabelas originais do livro financeiro. A nova migration aditiva
`Version000200Date20261122120000` incorpora os quatro domínios:
`lfb_*` (4 tabelas), `lfob_*` (3), `lfi_*` (6) e
`lfr_saved_reports` (1). Além delas, cria
`lf_invoice_obligation_links` e acrescenta a coluna opcional
`lfob_settlements.journal_id` mais o índice único correspondente.

São **34 tabelas no core**, incluindo `lfm_records`, `lfm_audit` e `lfm_liquid_origins`.
Somente a NFS-e mantém migrações e dados próprios. Os nomes físicos anteriores são preservados para
instalações de desenvolvimento sem remoção/descarte de linhas.

Tabelas no banco do Nextcloud, com prefixo da instalação (por exemplo `oc_`); não exigem banco independente.

| Tabela lógica | Chaves / propriedades importantes | Invariante |
| --- | --- | --- |
| lf_spaces | id, kind, currency, owner_uid, archived | Unidade de isolamento financeiro |
| lf_members | space_id, uid, role | Único por espaço/usuário; somente papéis financeiros explícitos no SaaS |
| lf_group_grants | space_id, group_gid, role | Único por espaço/grupo, grupo real do Nextcloud |
| lf_environment_spaces | environment_id, space_id, review_state, grants_revision | Vínculo SaaS com ID único por livro; revisão monótona para CAS de concessões internas, default 0 |
| lf_saas_company_workspaces | space_id, company_id, linked_by, linked_at | Uma identidade jurídica por livro SaaS e um livro por identidade; criação atômica opcional com a gestão, sem separar cadastros históricos |
| lf_accounts | space_id, code, class, currency, is_bank | Código único por espaço |
| lf_journals | space_id, external_key, request_hash, effective_on, reversed_journal_id, category_id | Chave de idempotência única por espaço, referência opcional à categoria hierárquica persistida |
| lf_postings | journal_id, account_id, space_id, amount_minor, currency | Lançamentos por partidas dobradas |
| lf_audit | space_id, actor_uid, action, entity, occurred_at | Rastro de alteração por espaço |
| lf_contacts | space_id, kind, name, email, tax_id, active | Cliente/fornecedor por espaço; dados fiscais são apenas cadastrais |
| lf_items | space_id, sku, kind, name, unit_price_minor, currency, active | Código de produto/serviço único dentro do espaço; preço em centavos |
| lf_module_flags | space_id, module_id, enabled | Estado de cada extensão por espaço; espaço/módulo único no banco |
| lf_categories | space_id, code, kind, parent_id, account_id | Categoria imutável com vínculo de herança de conta contábil, validado no PHP |
| lf_journal_claims | space_id, journal_id, module_id, reference | Reclamações duráveis de lançamento por módulo; bloqueiam estorno silencioso |

O isolamento **não** depende de um filtro frontend. Serviço PHP valida ator/empresa antes de acessar entidades. A versão inicial só admite duas pernas de lançamento e uma moeda por espaço; saldo é derivado da soma das postagens em cada conta e nunca editado diretamente. Fechamento de competência e restrições de calendário ainda não estão implementados.

## Domínios integrados e integridade

As tabelas bancárias, de contas a pagar/receber, faturamento e relatórios
são agora **internas ao núcleo**, não dependências de aplicativos externos.
O vínculo 1:1 de fatura/obrigação é imposto por índices únicos com
`space_id`. Um pagamento de fatura exige lançamento bancário já
registrado, reserva transacional da conta e reivindicação exclusiva do
lançamento. A liquidação e a posição de pagamento são atualizadas na mesma
transação. Não é permitida a baixa operacional direta de uma obrigação
originada de fatura.

`lfm_records`, `lfm_audit` e `lfm_liquid_origins`
agora pertencem ao core, preservando nomes e índices físicos.
`lfn_drafts` continua na NFS-e opcional. As entidades abaixo permanecem propostas futuras,
**não** tabelas assumidamente existentes nesta versão.

- Identidade legal: `lf_legal_entities`, `lf_fiscal_profiles`, `lf_membership_history`.
- Parceiros e catálogo: `lf_contacts`, `lf_contact_roles`, `lf_items`, `lf_item_prices`.
- Documentos comerciais: `lf_documents`, `lf_document_lines`, `lf_document_events`, `lf_document_discounts`, `lf_document_taxes`.
- Recebimentos e contas a pagar: `lf_settlements`, `lf_allocations`, `lf_payment_methods`.
- Bancos: `lf_bank_connections`, `lf_bank_feed_items`, `lf_import_batches`, `lf_reconciliations`, `lf_bank_rules`.
- Fiscal: `lf_tax_codes`, `lf_tax_rules`, `lf_tax_versions`, `lf_tax_evidence`. NFS-e em aplicativo próprio.
- Planejamento: `lf_budget_plans`, `lf_budget_lines`, `lf_period_closings`, `lf_recurring_rules`. Exchange-rate evidence (`lf_fx_rates`) already exists; see below.
- Arquivos e fluxo de trabalho: `lf_file_links`, `lf_approvals`, `lf_notifications`, `lf_outbox`.
- Extensões: `lf_extension_settings`, `lf_metadata_definitions`, `lf_metadata_values`.

Não misturar entidades jurídicas, organizações, espaços, grupos e identidades bancárias. Não permitir colunas de id soltas sem escopo. Uma conta bancária pode aparecer em várias visões, mas o registro contábil nunca pertence a dois espaços independentes.

## Estratégia de evolução

### Consolidação pré-publicação

A revisão atual substitui as migrations incrementais experimentais por **um
ponto inicial de instalação em cada aplicativo**, com as colunas, índices,
nullability e valores-padrão finais no momento da criação. As classes de
migration anteriores permanecem recuperáveis pelo histórico Git, mas não são
executadas em novas instalações. O número da nova baseline é superior às
versões anteriores, para permitir que instalações de **desenvolvimento**
detectem a mudança; as operações guardadas de alteração permanecem no
arquivo consolidado como compatibilidade de atualização, sem apagar dados.

A decisão é restrita ao período anterior à publicação de releases: depois de
distribuída uma versão que usuários possam instalar com dados persistentes,
migrations existentes tornam-se **imutáveis**, e novas alterações devem
receber migrations adicionais. Não é seguro redefinir o histórico de bancos
externos à cooperativa com base apenas na ausência de publicação na App Store.

Compare o esquema físico final e as constraints com SQLite, PostgreSQL e
MariaDB e valide instalação nova **e** atualização de uma instalação
anterior com dados sintéticos. Não restaurar um banco de desenvolvimento
antigo apagando registros para que ele passe na CI. Nunca importar dumps
proprietários nem copiar o desenho de bancos de software concorrente.

### Exchange-rate evidence

The additive lf_fx_rates table stores one immutable quote per workspace
and canonical fingerprint. Both currency codes, explicit minor-unit
precisions, metadata version, exact decimal rate string, effective date,
non-secret provenance, recording actor and time are retained. A unique
(space_id, fingerprint) index serializes equivalent concurrent recordings;
(space_id, id) supports scoped cursor reads. The rate and corresponding
audit event commit together. No existing currency or posting column is
rewritten by this migration.

### Proposed functional and original currency ledger contract (v2)

This is the implementation boundary for international accounting, **not an
activated ledger capability**. The existing v1 book remains BRL-only. The
current implementation uses `lf_spaces.currency`, `lf_accounts.currency`
and signed bigint `lf_postings.amount_minor` with one currency per posting;
`LedgerService::createJournal()` writes equal debit/credit legs and
`reverse()` copies their exact negatives. The installed `lf_fx_rates`
metadata is independent of postings. The current schema has no functional
and original pair of posting amounts, no rate reference on a posting and
no currency precision on an account or commercial document.

#### Representation and invariants

- A financial workspace has one immutable functional currency and its
  explicit precision/version, fixed at creation. Never change a book's
  unit in place to accommodate a foreign account or a jurisdiction change.
  Existing books are BRL, scale 2; changing that unit needs a separately
  designed migration to a new book, not an edit to historical amounts.
- Each account has an explicit original currency and precision fixed before
  its first financial use. Each document, price, settlement, imported
  transaction, statement checkpoint and allocation carries its original
  currency/precision; document lines use their document's declared unit
  unless a later version explicitly supports otherwise. Historical facts
  retain the stored unit even if currency metadata changes. A bank account's
  original currency is not necessarily the book's functional currency.
- Monetary facts are signed integers in minor units, not decimal floats.
  Bound each amount to `[-9000000000000, 9000000000000]` as in
  `ExactConversion` and the existing journal writer; apply stricter positive
  or nonzero constraints where the domain requires them. Scale is 0..3
  from the supported versioned `CurrencyMetadata` registry (BRL 2, JPY 0,
  KWD 3). Unknown currency/precision, out-of-range values and noncanonical
  integer strings (including exponent, leading zero and negative zero)
  are denied. No rounding of invalid original-unit input. Signed values
  use debit-positive and credit-negative in the ledger; source documents
  and bank evidence retain their own explicit sign conventions.
- A posting has its functional signed minor amount, functional code/scale,
  and, where it represents an original-unit movement, original signed
  minor amount, original code/scale. The account unit and posting original
  unit must agree. All legs of one journal use the same functional unit;
  their signed functional amounts sum to exactly zero. No requirement to
  add unlike original units. A realized/unrealized difference or a pure
  valuation adjustment is explicitly functional-only, with a reason and
  related evidence, not an invented foreign amount or a fake bank movement.
  If conversion rounds a nonzero original amount to zero functional units,
  retain the evidence; do not post an invented minor unit. Support for such
  original-only posted legs requires an explicit v2 decision and tests.
- A derived account balance has separate original and functional amounts.
  Group original totals by currency and stored precision, never sum unlike
  units; functional trial balance and ledger totals use booked functional
  values. Convert only for an explicitly identified measurement, never
  implicitly for report display. Use checked integer sums or exact decimal
  integer arithmetic for large aggregates; reject an unrepresentable API
  total rather than silently truncate, overflow, or use binary floats.

#### Rate evidence, rounding and carrying amounts

A quote is an immutable, workspace-scoped `lf_fx_rates` ID with source and
functional target currency, both stored precisions, metadata version,
positive decimal rate, effective date, source type/reference, recorded actor
and time. A rate is target major units per source major unit. The existing
`RateQuote` accepts up to 12 integral and 12 fractional digits (25 characters
including the decimal point), no sign, exponent, locale separator or leading
zero; trailing fractional zeros are canonicalized. Snapshot the selected
quote reference, interpretation and policy version with each relevant
financial fact. An explicit same-unit movement is identity conversion,
with equal stored scales and no artificial FX quote.

The proposed v2 conversion uses `ExactConversion` as its arithmetic
primitive. For an original signed minor amount A with scale p, target
scale q and exact rate r, compute the exact rational `A * r * 10^(q-p)`
and round once to the nearest functional minor unit, ties away from zero.
This is already the tested arithmetic behavior; it is symmetric for a
negative amount, deterministic across runtimes and preserves the integer
contract. Intermediate products may exceed machine integers, so retain
bounded string/integer arithmetic. Never round the rate or convert via a
floating-point intermediate. Store the actual rounded functional amount
and original amount; a later metadata/rate change cannot rewrite either.

For initial recognition, convert the authoritative amount, then allocate
its stored functional total among required lines; do not independently
round every line and hope the journal balances. For a partial settlement
of one already-recognized open item, allocate the historical carrying
value by original units: the cumulative functional allocation is the
exact rational `round_half_away(totalFunctional * cumulativeOriginal /
 totalOriginal)` minus the functional amount previously allocated.
Compute using exact integer arithmetic (not a rounded derived FX rate),
reject over-allocation, and make the final allocation consume the exact
remaining original and functional amounts. Persist allocations and their
basis atomically; retries must recover the same decision. Compare the
allocated carrying amount with the actual settlement's explicitly
converted amount; separately post the realized difference to configured
accounts. Rounding residuals that are not economic FX differences must
be explicit, bounded and attributed to the allocation or approved rounding
account; never hide a discrepancy in a bank account or tax line.

An authorized actor must explicitly select one applicable quote ID for the
requested pair and accounting date. Return all eligible candidates when
several exist; do not automatically choose a source, newest record, inverse
rate or triangulation. Validate the exact pair, workspace, stored scales
and `effective_on <= accountingDate` before the transaction, and again
within the authoritative write boundary. Missing quote, wrong pair, future
quote, ambiguous selection without an explicit ID, missing permissions or
inconsistent precision denies the posting without writes. An authorized
exception may select an alternative *eligible* immutable quote with a
recorded reason, actor and policy version; it cannot bypass these bounds.
An actual new historical rate must be recorded with provenance and real
as-of date separately from its recording time, never silently backdated or
used to alter prior facts. Automatic FX feeds and rate defaults are not part
of this contract.

Historical replay reads stored evidence, original and booked amounts,
allocations and policy version; it does not reconvert using today's
registry or rate. The idempotency fingerprint includes the monetary unit,
original and booked values, selected quote identity, accounting date,
allocation basis, accounts and policy version. A retry with a different
fingerprint is a conflict. Reversal negates the exact stored functional
and original movements, retaining their rate references and links. It must
not look up a new quote or reverse an already reversed fact a second time.
Revaluations are separate dated, audited adjustments to carrying value,
with unchanged original balance and an explicit unrealized gain/loss
account; no revaluation silently changes the original journal.

#### Synthetic accounting examples (contract fixtures)

All amounts below are **minor units**. Quotes are deliberately synthetic,
not market prices. For the following book, functional currency = BRL (2);
JPY has scale 0 and KWD has scale 3. Named accounts are conceptual and must
be explicitly configured, authorized and appropriate for the posting;
none is automatically created or inferred from a label. The examples
assume an open period, no tax/discount, explicitly authorized rate and
no other adjustments. Debit is positive, credit negative.

| Case and original evidence | Booked functional legs (BRL minor) | Check |
| --- | --- | --- |
| Same-unit receipt BRL 12345 | Bank +12345; income -12345 | 0; no quote |
| Foreign receipt JPY 5000 at BRL/JPY 0.0345 | JPY bank +17250; income -17250 | 0; 5000 JPY retained, BRL 172.50 |
| Foreign receivable JPY 10000 at 0.0345 | Receivable +34500; income -34500 | 0; historical carrying BRL 345.00 |
| Partial collection of JPY 4000, actual BRL 14000 at 0.035 | BRL bank +14000; receivable -13800; realized gain -200 | 0; receivable retains JPY 6000 and BRL 20700 minor carrying amount |
| Internal transfer of KWD 12345 from an account carrying those units at 15.80 BRL/KWD, actual gross BRL 19752 at 16.00, explicit bank fee BRL 252 | BRL bank +19500; fee expense +252; KWD bank -19505; realized gain -247 | 0; KWD 12.345 removed, no inferred fee from net alone |
| Period-end valuation of the remaining JPY 6000 receivable at 0.036 | Receivable valuation +900; unrealized gain -900 | 0; 21600 less 20700, original JPY unchanged |
| Reversal of the valuation | Receivable valuation -900; unrealized gain +900 | 0; exact inverse, no new quote |
| Reversal of the partial collection, where permitted by the linked settlement lifecycle | BRL bank -14000; receivable +13800; realized gain +200 | 0; restore JPY 4000 and booked carrying 13800, no new conversion |

The computations are exact: `5000 * 0.0345 * 100 = 17250`,
`10000 * 0.0345 * 100 = 34500`, `34500 * 4000 / 10000 = 13800`,
`4000 * 0.035 * 100 = 14000`, `6000 * 0.036 * 100 = 21600`.
For the KWD example, `12345 * 15.80 * 100 / 1000 = 19505.1`,
rounded to 19505; at 16.00 the gross is 19752. Net = 19752 - 252 = 19500,
and realized difference = 19752 - 19505 = 247. The transfer is not two
unrelated payments and does not invent an opening balance. A foreign-unit
funding lot's carrying amount must come from actual stored evidence.

The same conversion primitive supports other **functional** scales: BRL
12345 minor at JPY/BRL 10 yields 1234.5 JPY, rounded to 1235 JPY minor;
BRL 123 minor at KWD/BRL 0.0605 yields 74.415 KWD minor, rounded to 74.
KWD 1 minor at BRL/KWD 5 gives 0.5 BRL minor, rounded to +1; -1 KWD
minor gives -1. These arithmetic values are mirrored in
`ExactConversionTest`, not new posting or reporting behavior. The
multi-leg sums and remaining balances are independent integer fixtures
for the dependent implementation and integration tests.

The examples do **not** select a general foreign-account cost-flow method.
Before activation, agree on lot identification versus weighted-average
carrying allocation, recognition date for each document type, treatment
and reversal/reclassification timing of revaluation at settlement/next
period, configurable realized/unrealized/rounding accounts and permissions,
zero-functional original legs, statutory/reporting treatment by jurisdiction,
and closed-period correction rules. The example reverses an independent
valuation layer explicitly; a later settlement must not recognize the
same unrealized amount again. No unsupported policy may be inferred from
this illustration or applied by a default fallback.

#### Migration, API compatibility and downstream handoff

The existing two-leg BRL path must remain compatible. Future additive
migrations must leave every historical `amount_minor`, `currency`, primary
ID, external/idempotency key, reference, reversal link, audit and stored
request hash unchanged. Add versioned functional/original facts and
explicit scales/metadata, with nullable new fields for records not yet
qualified; legacy BRL postings have an exact identity interpretation with
scale 2 and no quote. Backfill in bounded, restartable batches only after
validating the actual legacy currency and relationship; fail/report
unexpected values rather than assume a conversion. Never delete or rewrite
posted history, alter an executed migration, fabricate foreign source
facts or use current FX data to fill a gap. Fresh install and upgrade of
synthetic historical data must match on SQLite, PostgreSQL and MariaDB;
compare IDs, row counts, amounts, per-account/trial-balance totals,
references and repeated upgrades, including interrupted batches. Use
portable OCP schema/query operations and transactional consistency.

API v1 fields such as `amountMinor`, `amount_minor`, `balance_minor` and
`currency` retain their established BRL semantics and validation. Do not
return a mixed-currency amount in a legacy field. A versioned opt-in v2
contract must expose explicit original/functional amount, currency and
precision, quote ID/effective date/provenance and adjustment/rounding
semantics; see [API compatibility](API.md#proposed-versioned-monetary-contract).
Legacy readers/writers must either receive unchanged compatible BRL facts
or an explicit unsupported-contract response for a v2-only operation;
no silent truncation of extra legs, reinterpretation or float conversion.
Keep all existing workspace and module capabilities, server-side account
and quote authorization, idempotency, audit and journal claims. No cross-book
netting. Introduce v2 routes or negotiated contracts and update generated
OpenAPI only when implemented, not in this design-only change.

The following is the reviewed dependency surface, not a claim that these
paths already support v2. The downstream implementation must recheck the
current tree, including third-party optional module contracts:

| Surface | Existing writer/reader path | Required qualification |
| --- | --- | --- |
| Workspace and account | `SpaceService::create`, `SaasWorkspaceService::create`, `LedgerService::createAccount`, `updateAccount`, `AccountReferenceGuard`, `FinanceApi::currency`/`requireBankAccount` | Immutable functional/original units, capabilities, explicit account scale, workspace creation and identity migration; no currency edit |
| Ledger | `JournalController`, `LedgerService::createJournal`, `post`, `reverse`, `journals`, `accounts`, `JournalClaimService` | Versioned multi-line storage; exact balanced sums, replay, immutable reversal and references; no alternate unvalidated posting path |
| Bank source/transfer | `BankingService::import`/`match`/CSV minor parser, `OfxStatementParser`, `BankStatementEntry`, `BankImportApi::inspect`/`appendPending`, `BankTransferApi::inspect`/`appendPending` | Bank source original precision, scope, checkpoints, explicit actual settlement/transfer legs and fees; pending evidence remains non-posted |
| Documents, items, tax | `CatalogService::createItem`/`updateItem`, `InvoiceService::create`/`issue`/`allocate`, `TaxCalculator`, `TaxService` | Snapshot units and totals, tax/discount allocation, immutable original and carrying amounts; country-specific precision and optional NFS-e module must not be assumed supported |
| Obligations and payments | `ObligationService::create`/`settle`/`settleWithJournal`/`linkIssuedInvoice`/`recordInvoiceAllocation`, `PaymentWorkflowService::forInvoice`/`forObligation`/`linkExistingObligation`/`pay` | Original versus carrying and settlement amount; preserve linked atomic journal claim, allocation limits, revision and concurrency; realized differences and explicit fee evidence |
| Migration and reconciliation | `ImportService`, `MovementImportService`, `TransferImportService`, `MovementReconciliationService`, `InventoryParityService`, `Import/Interchange/*`, `FinanceApi` import methods | Keep original source scope and IDs; compare like currency/scale and functional controls, staged preview/confirmation, no inferred foreign amounts; follow [neutral mapping](MIGRATION-MAPPING-V1.md) |
| Reports and navigation | `FinanceApi::profitLoss`/`cashFlow`/`trialBalance`, `SavedReportService`, `ActivityFeedService`, `LedgerService::accounts`/`journals`, `src/reportCsv.js` | Aggregate booked functional legs independently of pagination, group original totals by unit, preserve reversal links and saved report basis/export completeness |
| Native UI | `useFinanceWorkspace`, `financeWorklist`, `CashPaymentDialog`, `AccountsView`, `BankingView`, `ContactsView`, `DashboardView`, `CashflowView`, `InvoicesView`, `MovementsView`, `ObligationsView`, `TransactionsView`, `NfseView`, `SpacesView`, SaaS workspace creation/admin | Replace fixed BRL/two-decimal assumptions with server-provided explicit scale and exact input formatting; no implicit conversion or client-side authorization; verify stale results, HTTP, translations and real browser journeys |

Handoff order: this contract first; then versioned journal persistence and
BRL compatibility, independently qualified banking and commercial writers,
then source migration and report/CSV control totals, followed by UI and
optional-module qualification. Before enabling any foreign-currency book
or account, all reachable writer/parser/report paths for that capability
must be verified (or explicitly denied until qualified). Keep both existing
workspace creation BRL gates and legacy two-leg writer gate until approved
policy decisions, portable migration/upgrade, concurrency and auth tests,
PHP/native Nextcloud integration and browser/report controls pass on the
actual integrated revision. Arithmetic unit tests alone do not qualify a
financial ledger. This contract creates no new route, schema, journal or
currency activation.

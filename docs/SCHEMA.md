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
- Planejamento: `lf_budget_plans`, `lf_budget_lines`, `lf_period_closings`, `lf_recurring_rules`, `lf_fx_rates`.
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

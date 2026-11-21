<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Modelo de dados e nomes de tabelas — LibreFinance

## Esquema físico do núcleo (migrações 000001–000006)

Tabelas no banco do Nextcloud, com prefixo da instalação (por exemplo `oc_`); não exigem banco independente.

| Tabela lógica | Chaves / propriedades importantes | Invariante |
| --- | --- | --- |
| lf_spaces | id, kind, currency, owner_uid, archived | Unidade de isolamento financeiro |
| lf_members | space_id, uid, role | Único por espaço/usuário |
| lf_group_grants | space_id, group_gid, role | Único por espaço/grupo, grupo real do Nextcloud |
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

## Evolução de domínios — não confundir proposta com migração instalada

As extensões independentes já criadas possuem tabelas próprias: `librefinance_obligations` mantém `lfob_obligations`, `lfob_settlements` e `lfob_audit`; `librefinance_banking` mantém `lfb_transactions` e `lfb_audit`; `librefinance_invoicing` mantém `lfi_tax_rules`, `lfi_tax_series`, `lfi_invoices`, `lfi_invoice_lines`, `lfi_allocations` e `lfi_audit`; `librefinance_nfse` mantém `lfn_drafts`; `librefinance_import` mantém `lfm_records` (mapeamento imutável de IDs por sistema/empresa/origem) e `lfm_audit` (contagem e hash dos lotes, sem dados brutos). `librefinance_reports` apenas consulta a API do núcleo. As outras nomenclaturas abaixo são **propostas**, não tabelas presentes no esquema físico. Conferir `docs/MODULES.md`.

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

Migrações Nextcloud aditivas e versionadas. Não editar migração publicada; preservar dados, índices e rollback documentado. Instalação limpa e os fluxos sintéticos iniciais foram testados em PostgreSQL, SQLite e MariaDB no Nextcloud Server master. Ainda são necessários testes de upgrade, compatibilidade das migrações históricas, carga e concorrência ampliada antes de declarar compatibilidade para produção. Nunca importar dumps proprietários nem copiar o desenho de banco de código licenciado como fonte da implementação.

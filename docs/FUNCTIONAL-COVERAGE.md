<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Cobertura funcional: requisitos para uma plataforma financeira completa

**Fonte de requisitos:** capacidades usuais de gestão financeira e documentação pública de produtos do setor, incluindo fluxos funcionais conhecidos de gestão financeira, faturamento e contabilidade. **Não é uma reimplementação do código, UI, banco ou algoritmos de nenhum produto comercial.**

Legenda: [I] implementado neste código (requer homologação), [P] planejado, [E] provedor externo/fiscal sujeito a contrato.

## Núcleo e cadastros
- [I] Espaços pessoais, coletivos e organizacionais com isolamento por identidade
- [I] Compartilhamento individual e por grupo Nextcloud
- [I] Papéis iniciais e escopo de consultas
- [I] Plano de contas básico e conta caixa
- [I] Lançamentos em partidas dobradas, saldos derivados, estorno, histórico paginado, categoria hierárquica opcional e referência durável que bloqueia estorno de lançamentos conciliados/vinculados
- [I parcial] Cadastro jurídico de empresas por espaço, criação/edição/desativação, identidade tributária, inscrições estadual/municipal, auditabilidade e isolamento por usuário/grupo; sem validação externa do CNPJ e ainda sem ligação com filiais e outros módulos
- [P] Gestão de filiais, pessoas físicas e relação entre empresas, cooperados e espaços
- [I] Contatos, clientes e fornecedores básicos, com controle de acesso e cadastro de identificação (sem validação fiscal)
- [P] Locais, endereços, cobrança e relações complexas com prestadores
- [I] Catálogo básico de produtos e serviços com SKU e preço unitário em centavos
- [P] Unidades, tabelas de preço, estoque, variantes e políticas comerciais
- [I parcial] Categorias hierárquicas próprias, código e conta contábil herdada da ancestral, persistidas no lançamento; sem centros de custo, projetos, etiquetas e campos customizados
- [P] Centros de custo, projetos, etiquetas, classificações múltiplas e campos customizados
- [P] Multimoedas, precisão por moeda, câmbio, reavaliação, saldos iniciais
- [P] Competências, aprovações, ajustes, fechamento contábil, conciliações e trilhas completas
- [P] Perfis de permissão finos, convites, revogação de acessos e transferência de propriedade

## Comercial e pagamentos
- [I parcial] `librefinance_invoicing`: faturas comerciais de venda/compra, linhas, status e vínculo a lançamentos já registrados; sem orçamentos, ordens ou descontos
- [P] Orçamentos/propostas, ordens, emissão eletrônica de documentos comerciais, descontos e políticas de cobrança
- [I parcial] Impostos configuráveis por código, país/região, data de vigência, fonte documental e versão imutável; cálculos genéricos VAT inclusivo/exclusivo e retenção com snapshot por linha; trava de concorrência por código
- [P] Homologação por jurisdição, múltiplos impostos por linha, composições, benefícios, exceções, regimes, competência fiscal e validade oficial
- [I parcial, extensão opcional] `librefinance_obligations`: títulos com vencimento e baixas operacionais parciais/integras, cancelamento e trilha; não são pagamentos bancários nem escrituração contábil
- [P] Emissão de documentos fiscais/comerciais, aprovação, envio, estornos contábeis/fiscais e integração do título com o livro contábil
- [P] Faturas recorrentes, cobranças, alertas e notificações
- [P] Portal de cliente com escopo e autenticação próprios
- [P] Métodos de pagamento e provedores de recebimento por módulo
- [P] Anexos PDF/XML em Files e assinatura por LibreSign quando autorizado

## Bancos e previsões
- [P] Cadastro detalhado de instituições, agência, conta, chave de identificação e credenciais segregadas
- [I parcial, extensão opcional] `librefinance_banking`: importação CSV UTF-8, histórico bancário importado como extrato pendente, identidade externa estável, transferências históricas pareadas pendentes de correspondência e deduplicação transacional; sem conexão automática com bancos nem certificação de conciliação
- [P] Importação OFX, QIF e CAMT, normalização multibancos, recuperação de extrato e conciliação assistida
- [I parcial, extensão opcional] Transações CSV pendentes e conciliadas manualmente contra partida contábil existente de mesma conta e valor
- [P] Classificação, regras, rateios e conciliação automática assistida
- [I parcial] Transferências entre contas como pares históricos pendentes, sem lançar nem duplicar extratos; [P] vincular ambas as pontas, conciliar transferências, cartões, saldos conferidos e projeções
- [E] Integração Sicoob, outros bancos, Open Finance e consentimento/revogação
- [P] Identificação de falhas de conexão, reautenticação, observabilidade e idempotência

## Relatórios, automação e ecossistema
- [I parcial, extensão opcional] `librefinance_reports`: demonstrativo simples de resultado por data e balancete de verificação paginado; sem certificação contábil
- [I parcial] Fluxo de caixa **realizado** por competência de lançamento, mensal, abertura e fechamento; transferências entre contas bancárias cancelam na variação líquida. Não substitui DFC formal
- [P] Fluxo de caixa projetado, DRE formal, balanço patrimonial, razão completo e relatórios tributários
- [P] Dashboards, widgets, filtros por competência, centro de custo, entidade e escopo
- [P] Exportação CSV, PDF e formatos contábeis abertos
- [I parcial] Contrato para aplicações complementares instaladas no próprio Nextcloud, com registro por evento OCP, catálogo e ativação por espaço; seis extensões independentes construídas
- [P] Eventos de domínio versionados, jobs, webhooks e contratos públicos de escrita financeira para extensões
- [I parcial] Contrato OpenAPI 3.1 para 66 operações em sete apps, geração a partir das rotas PHP, tipagem de requisição e validação estrutural; seis respostas críticas tipadas e demais ainda genéricas
- [I parcial] MCP stateless de leitura com cinco ferramentas e autorização nativa por espaço; sem suporte completo a outras versões, notificações ou OAuth remoto
- [P] Integração nativa com notificações, Activity, Files, Calendar, Mail, LibreSign
- [I parcial] Nextcloud TaskProcessing para explicar **totais agregados** somente com consentimento, sem autoridade para escriturar; sem teste com modelo real
- [P] Assistência por IA para classificação, conciliação assistida e explicação, sem autoridade financeira

## Cooperativas brasileiras
- [P] Controle de cooperados e direitos de acesso, sem misturar identidade e participação econômica
- [P] Produção, apontamentos, rateios, sobras, descontos, aprovações, históricos e demonstrativos
- [I parcial, extensão opcional] `librefinance_nfse`: rascunho preparatório com identificação do prestador, município, competência, serviço e valor; vínculo opcional da empresa juridicamente cadastrada com confirmação de CNPJ; sem emissão nem validade fiscal
- [E] Emissão e consulta de NFS-e Nacional, DPS, DANFSe, ADN e fechamento fiscal com `nfse-php`, certificado e homologação
- [P] Códigos de serviço, regras municipais e transição tributária versionadas
- [I parcial, nativo no núcleo] `librefinance_import`: importação transacional de empresa jurídica, contatos, categorias, contas financeiras, movimentações bancárias pendentes e transferências pareadas; prévia não mutante, identidades estáveis por empresa, prevenção de duplicidade, comparação de saldos diagnóstica e auditoria de inventário por recurso (`inventory-preview`) sem declarar paridade por coincidência de contagens
- [Pendente, fora deste PR] Migração direta do Akaunting: conexão, credenciais, leitura paginada da API, mapeamento completo e conciliação financeira, em trabalho separado
- [P] Adaptador autenticado e auditável para API de origem; migração real de documentos, impostos, arquivos fiscais, histórico de empregados/campos, partidas dobradas, conciliação das pontas das transferências e validação de integridade contábil/fiscal por data de corte

## Critério para paridade futura

Não marcar função como implementada por existência de tabela, rota, botão ou teste isolado. Exigir operação real ponta a ponta, controle de acesso negativo, migração/backup, documentação, testes e revisão financeira/fiscal. A paridade com recursos pagos ou opcionais de outro produto não está garantida; definir escopo por resultados observáveis, não por número de módulos do fornecedor.

<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Extensões instaláveis LibreFinance — contrato de desenvolvimento v1

## Escolha arquitetural

Cada módulo é **um aplicativo Nextcloud independente**, com seu próprio `appinfo/info.xml`, namespace PHP (`OCA\<Module>`), rotas, migrations próprias, frontend Vue e controle de qualidade. Não existe carregamento de código PHP fornecido por usuários, importação dinâmica de arquivos PHP ou infraestrutura de plugins paralela ao Nextcloud. Um administrador instala/habilita módulos pelo gerenciador nativo (ou `occ app:enable`); gestores habilitam/desabilitam o módulo **por espaço financeiro** na aba "Módulos" do LibreFinance. A instalação no servidor **não concede acesso automático** a espaços financeiros.

O Nextcloud Server `master` inspecionado (commit de referência `7a89690a1ca17aae3113da13070487c191eb1f4b`) não disponibiliza um atributo declarativo genérico de dependência entre apps dentro de `<dependencies>`. Portanto, cada extensão deve ser instalada *depois* do núcleo e verificar o contrato em tempo de inicialização/operação; não inventar uma dependência XML não suportada. Não é necessário alterar o core do Nextcloud.

## Ponto de registro

O núcleo emite `OCA\LibreFinance\PublicAPI\RegisterModulesEvent` usando `OCP\EventDispatcher\IEventDispatcher::dispatchTyped()`. A extensão inscreve seu listener com `IRegistrationContext::registerEventListener`, mecanismo público OCP. Cada listener fornece um `ModuleDescriptor` validado, contendo:

- `appId`: nome Nextcloud começando por `librefinance_`;
- `name` e `description`: textos breves para a aba de módulos;
- `routeName`: nome da rota HTML pertencente ao próprio app (prefixo `appId.`), chamada com o parâmetro `spaceId`. Os seis apps atuais usam essa rota para preservar links antigos e redirecionar, após autorização, para a interface unificada. **Esse metadado não fornece carregamento automático de telas de terceiros**;
- `requiredRole`: viewer, auditor, operator ou manager (limite minimo obrigatorio tanto na descoberta quanto em `FinanceApi::requireModule`, independentemente do perfil pedido por cada endpoint).

Exemplo mínimo — **não cria o listener automaticamente**:

```php
// Em Application::register(IRegistrationContext $context):
$context->registerEventListener(
    \OCA\LibreFinance\PublicAPI\RegisterModulesEvent::class,
    \OCA\MeuModulo\Listener\RegisterListener::class,
);

// Em RegisterListener::handle(Event $event):
if ($event instanceof \OCA\LibreFinance\PublicAPI\RegisterModulesEvent) {
    $event->register(new \OCA\LibreFinance\PublicAPI\ModuleDescriptor(
        appId: 'librefinance_exemplo',
        name: 'Exemplo',
        description: 'Um recurso separado',
        routeName: 'librefinance_exemplo.page.index',
        requiredRole: 'viewer',
    ));
}
```

Apenas metadados de extensões **efetivamente habilitadas para o usuário no Nextcloud** entram na resposta do núcleo. Um módulo não pode fornecer URL arbitrária nem JS para a interface principal.

## API de gerenciamento do núcleo

- `GET /index.php/apps/librefinance/api/spaces/{spaceId}/modules` lista apenas módulos instalados, visíveis para aquele perfil e com o estado `enabled` do espaço. Requer membro do espaço; espaço não acessível retorna 404. Cada item inclui URL de rota gerada pelo servidor.
- `PUT /index.php/apps/librefinance/api/spaces/{spaceId}/modules/{moduleId}` com `{"enabled":false}` ou `{"enabled":true}` altera a disponibilização **somente daquele espaço**. Requer manager/owner. Não desinstala o app, não apaga suas tabelas e não executa código da extensão. A API usa controle CSRF/autenticação do Nextcloud.
- A extensão chama `OCA\LibreFinance\PublicAPI\FinanceApi::requireModule($spaceId, $appId, $role)` para **cada** endpoint e para a renderização da página. Nunca confiar apenas no estado do menu. Os erros de espaço sem acesso são 404; módulo desabilitado é 403. O usuário não ganha automaticamente acesso por ser administrador de outro espaço.

Exemplos de APIs **autorizadas no núcleo**, disponíveis para extensão:
`currency`, `requireActiveContact`, `requireBankAccount`, `journalContainsBankPosting`, `claimJournal`, `importContact`, `importCategory`, `importAccountCodes`, `importCategoryCodes`, `profitLoss` e `trialBalance`. O vínculo permanente `claimJournal` impede estornos silenciosos de lançamentos usados por módulos, mesmo quando o módulo é desativado. Não acesse tabelas internas `lf_*` diretamente no módulo, nem utilize `OC\` internos como contrato. A interface PHP existente ainda é **v1 experimental, não considerada API semântica estável para terceiros**. Antes de publicação, formalizar compatibilidade e política de depreciação.

## Apps locais que exercitam o contrato

| App instalável | Banco próprio | Escopo realmente implementado |
| --- | --- | --- |
| `librefinance_reports` | `lfr_saved_reports` | Resultado, balancete paginado e fluxo de caixa; modelos salvos com colunas e filtro de classe contábil no balancete, edição, arquivamento e exclusão segura da configuração |
| `librefinance_obligations` | `lfob_obligations`, `lfob_settlements`, `lfob_audit` | Títulos a pagar/receber, vencimento, baixas parciais, cancelamento e trilha operacional |
| `librefinance_banking` | `lfb_transactions`, `lfb_audit`, `lfb_transfers`, `lfb_transfer_audit` | Importação CSV/histórica idempotente, conciliação manual, pares de transferência pendentes e API de provedores `BankProviderInterface`, sem sincronização automática nem correspondência das duas pontas |
| `librefinance_invoicing` | `lfi_tax_rules`, `lfi_tax_series`, `lfi_invoices`, `lfi_invoice_lines`, `lfi_allocations`, `lfi_audit` | Faturas comerciais, regimes genéricos VAT/retention, vigência e jurisdição, snapshots de impostos e associação com lançamento já contabilizado |
| `librefinance_nfse` | `lfn_drafts` | Preparação de rascunhos de NFS-e Nacional, validação de formato/CNPJ e idempotência; emissão fiscal desabilitada |
| `librefinance_import` | `lfm_records`, `lfm_audit`, `lfm_liquid_origins` | Empresa, contatos, categorias, contas, extratos e transferências pendentes; origem imutável, prévia e confirmação, diagnóstico de saldos e inventário de cobertura somente leitura |

A extensão de obrigações **não** contabiliza automaticamente nem faz transferência de dinheiro. A extensão bancária **não** se conecta automaticamente a bancos; não pede credenciais nesta versão. `BankProviderInterface` permite acrescentar provedores por PR dentro do app bancário ou, futuramente, por outro app Nextcloud. O módulo tributário possui um único imposto por linha, não um motor fiscal internacional homologado. O aplicativo NFS-e registra apenas **rascunhos sem validade fiscal**, sem envio SEFIN/ADN. A existência de uma extensão não equivale a equiparação funcional com um ERP comercial.

## Testes de integração e banco

Ao instalar core e módulos no mesmo Nextcloud `master`, executar `tests/ci/smoke.sh`. O script utiliza `tests/ci/compose.yml` e, ao definir os seis diretórios de fonte, incorpora `tests/ci/compose-extensions.yml` e instala todas as extensões:

```sh
LF_NEXTCLOUD_SOURCE=/caminho/nextcloud-server-master \
LF_REPORTS_SOURCE=/caminho/librefinance_reports \
LF_OBLIGATIONS_SOURCE=/caminho/librefinance_obligations \
LF_BANKING_SOURCE=/caminho/librefinance_banking \
LF_INVOICING_SOURCE=/caminho/librefinance_invoicing \
LF_NFSE_SOURCE=/caminho/librefinance_nfse \
LF_IMPORT_SOURCE=/caminho/librefinance_import \
LF_TEST_DATABASE=pgsql \
bash tests/ci/smoke.sh
```

Pode-se trocar `pgsql` por `sqlite` ou `mariadb`. **Executar somente em laboratório isolado**, nunca contra uma instância real. O teste cria contas sintéticas, publica transações fictícias, exercita negações, desativação, revogação e operações concorrentes, e remove containers, volumes e credenciais temporárias.

## Limites de confiança e próximos contratos

O administrador da instância decide quais apps instalar; essa é a fronteira de confiança de código executável. O gestor financeiro decide, separadamente, em quais espaços os módulos podem operar. Toda extensão deve impor suas permissões em PHP, inclusive ao receber identificadores de objeto de outro espaço, após revogação e durante condições concorrentes. Preferir IDs imutáveis, colunas de isolamento e índices únicos no DB; manter audit log e migrações independentes. Para pagamento externo, notificação, agendamento, arquivos, assinatura e NFS-e, definir contratos explícitos de autorização/consentimento, replay, erros, dados pessoais e reversão, em vez de acoplar diretamente ao frontend.

**Não executar instalações de módulos por upload arbitrário a partir de um gestor financeiro**. Isso contornaria a governança e a assinatura de aplicativos Nextcloud.


## Limite da extensibilidade da interface

O contrato OCP registra metadados, permissoes e rotas de aplicativos externos, mas **na versao 0.12.0 a interface Vue nao e uma plataforma generica de telas de terceiros**. Em `src/App.vue`, `screens` e `nav` sao listas fechadas dos seis modulos entregues. Um app Nextcloud adicional pode registrar seu descriptor e expor APIs autorizadas, mas **nao aparece automaticamente como tela integrada** sem implementacao aprovada no frontend do nucleo.

Para permitir instalacao independente de novas interfaces no futuro, ainda sera necessario definir um contrato versionado de extensao visual, politicas de origem/execucao, contexto e permissoes, degradacao segura quando o app for desativado, compatibilidade entre releases e testes de isolamento de UI. Nao carregar scripts, HTML, iframes ou URLs arbitrarios de extensoes com base apenas no descriptor. **Estado: previsto, nao implementado**.

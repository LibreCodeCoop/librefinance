<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Integrações nativas com MCP e IA do Nextcloud

## MCP: acesso autenticado, inicialmente somente leitura

Endpoint: `POST /index.php/apps/librefinance/api/mcp`. Usa JSON-RPC 2.0
sobre HTTP com `MCP-Protocol-Version: 2025-06-18`, `OCS-APIREQUEST: true`,
`Accept: application/json, text/event-stream` e autenticação do Nextcloud
(senha de aplicativo para cliente remoto). Rejeita Origin de outra origem,
corpos com mais de 32 KiB, ferramentas desconhecidas e argumentos inesperados.

Ferramentas expostas:
- `librefinance_list_spaces`: somente espaços visíveis para a identidade
  Nextcloud do chamador.
- `librefinance_list_accounts`: saldos derivados, contas do espaço autorizado.
- `librefinance_list_journals`: escrituração paginada desse espaço.
- `librefinance_profit_loss`: depende do app `librefinance_reports` estar
  habilitado para o espaço.

Os resultados usam `structuredContent` e `content` no JSON-RPC. Acesso
negado não divulga dados de outros espaços. Não há tools de alteração de
lançamentos, emissão de notas, pagamento ou administração. Não há fallback
para autoridade de administrador, API própria com segredo de aplicação,
assinatura de prompts, nem permissões delegadas silenciosas.

**Escopo de interoperabilidade:** implementado `initialize`, `ping`,
`tools/list`, `tools/call` e notificação `initialized` para um subconjunto
do transporte HTTP sem estado. Não é substituto completo de um servidor MCP
de sessões e notificações com SSE; revisar versões posteriores do
protocolo, descoberta OAuth/Resource Metadata, limites de cliente, sessões
e compatibilidade com o Nextcloud Assistant antes de expor na Internet.
Não publicar a rota por um proxy que remova autenticação ou o cabeçalho
`MCP-Protocol-Version`.

## IA: Nextcloud TaskProcessing como serviço opcional

A integração usa exclusivamente as interfaces públicas
`OCP\TaskProcessing\IManager`, `Task` e `TaskTypes\TextToText`.
Não requer um contêiner de IA, serviço Python, conta externa ou pacote
fechado no runtime do LibreFinance.

`GET /api/spaces/{spaceId}/ai/preview?from=YYYY-MM-DD&through=YYYY-MM-DD` mostra os valores agregados que seriam enviados, **sem chamar provedor** e sem gerar tarefa. A prévia é restrita a gestores e permite conferir os números e a moeda antes da decisão. Ela devolve um `previewHash` autenticado por HMAC e vinculado à identidade Nextcloud, ao espaço e aos valores exibidos. O POST precisa devolver exatamente esse identificador; quando houver nova movimentação que altere o resultado, retorna HTTP 409 e exige nova conferência. O hash nunca é transmitido ao provedor de IA.

`GET /api/spaces/{spaceId}/ai/capabilities` verifica a disponibilidade
do provedor, desde que o módulo de relatórios esteja efetivamente habilitado. `POST /api/spaces/{spaceId}/ai/analyze` recebe:
```json
{"from":"2026-01-01","through":"2026-01-31","consent":true,"previewHash":"<hash de 64 caracteres retornado pela prévia>"}
```
Um gestor autorizado confirma explicitamente a transferência de dados
agregados. Apenas período, moeda, receita total, despesa total e resultado
líquido são enviados à tarefa, e não nomes, faturas, documentos, contatos,
identificadores bancários ou extratos. A IA **não posta transações** e sua
resposta é explicativa, não contábil/fiscalmente vinculante. O provedor
instalado pelo administrador do Nextcloud poderá processar dados fora da
instância, conforme sua política. O usuário deve conhecer esse risco antes
de consentir.

O endpoint de estado `GET /api/spaces/{spaceId}/ai/tasks/{taskId}` devolve
somente tarefas do mesmo usuário, aplicação e espaço, nunca resultados
globais. A cada consulta, verifica novamente a função financeira e o módulo
de relatórios, incluindo as políticas restritivas herdadas. A revogação
impede a próxima consulta pelo LibreFinance; não recupera dados já
transmitidos ou cancela automaticamente tarefas previamente consentidas no
Nextcloud. Sem provedor adequado, há erro explícito 503. Os testes em
ambiente sem modelo validam essa recusa e resultados sintéticos já
persistidos no armazenamento de tarefas, sem invocar provedor; **a execução de um modelo real,
custo, privacidade e qualidade das respostas ainda não foram homologados**.

Não permitir que conteúdo gerado por IA vire autorização para operações
financeiras futuras. Um eventual módulo de escrita do MCP deve ser outro
contrato, incluindo aprovação humana inequívoca e auditoria.

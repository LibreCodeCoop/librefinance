<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
# LibreFinance API e OpenAPI 3.1

A API nativa usa atributos `#[FrontpageRoute]` nos métodos dos controllers
PHP do núcleo e do aplicativo opcional NFS-e. Não existe arquivo
`appinfo/routes.php`: o controller define a rota. A especificação
[openapi.json](../openapi.json) é gerada a partir dos atributos reais e dos
contratos JSON declarados; o gerador falha diante de um formato não
reconhecido, em vez de omitir silenciosamente uma operação.

## Autenticação e autorização

- A API não é pública. Requer identidade Nextcloud, sessão autenticada com
  proteção CSRF própria do Nextcloud ou senha de aplicativo para clientes API
  (incluindo Behat/MCP). Nunca implemente autenticação paralela por empresa.
- Os parâmetros `spaceId`, `contactId`, `journalId`, etc. não concedem
  permissão. PHP verifica o papel no espaço e a entidade associada ao espaço
  antes de selecionar, criar ou atualizar dados.
- Um recurso de outro espaço pode retornar 404 para não permitir sua
  enumeração. Erros de estado/duplicação usam 409 e validações de negócio 422.
- Operações `POST`, `PUT` e `DELETE` não aceitam ignorar CSRF na
  interface. Não instale outros provedores de autenticação por conta própria.
- A documentação cobre as operações extraídas diretamente dos controllers na revisão
  atual; cada PR deve verificar contagem e diferença real de rotas.

### Gerar e validar

No diretório do núcleo, sem exigir aplicativos opcionais:

```sh
composer openapi
composer openapi:check
npm ci --include=dev
npm run test:openapi
```

O segundo comando falha quando uma mudança de rota torna o JSON obsoleto.
O arquivo gerado e versionado fica em `openapi.json` na raiz do
aplicativo, como esperado pelo OCS API Viewer. A instalação Nextcloud recebe
o arquivo pronto; não há geração no runtime nem escrita no diretório do app.
O CI gera/valida a especificação durante o desenvolvimento e rejeita
alterações de controllers sem atualização da especificação.
As extensões opcionais mantêm especificações próprias em seus repositórios.
Quando o código NFS-e está disponível, a opção `--check-extensions`
verifica os contratos agregados sem incorporá-los ao OpenAPI do núcleo.
O gerador também rejeita novas rotas mutáveis não declaradas na tabela de
contratos. O terceiro valida OpenAPI 3.1 via biblioteca Swagger Parser e
verifica segurança, parâmetros, consentimento IA e leitura MCP.

### Limites

- **Não é garantia de equivalência contábil ou fiscal.** A descrição de
  `invoice#issue` é de fatura comercial, não NFS-e autorizada.
- Seis operações críticas têm respostas tipadas (lançamento, resultado,
  fluxo de caixa, prévia/solicitação de IA e MCP). As demais ainda utilizam
  o objeto genérico `JsonObject`. Faltam tipagem e exemplos completos de
  cada saída, respostas OCS versionadas e a futura adoção do extrator
  oficial do Nextcloud, incluindo validação automática de respostas HTTP
  reais contra os respectivos esquemas.
- Não há garantia de estabilidade da API antes de versão 1.0, nem uma
  implementação de todos os endpoints do ERP de origem.
- O HTTP MCP é apenas o subconjunto stateless 2025-06-18 documentado
  separadamente; compatibilidade com outras versões/clientes não foi
  certificada.

A suíte HTTP de integração e Behat constitui evidência separada sobre
permissões e regras de negócio. Uma especificação válida isoladamente não
prova funcionamento do serviço.

<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Bibliotecas JavaScript distribuídas

O bundle de produção do LibreFinance é gerado com Vite a partir do código-fonte do projeto, Vue e bibliotecas públicas Nextcloud. Ele não contém qualquer código copiado de softwares financeiros de terceiros.

Dependências declaradas de produção e suas licenças informadas nos manifests locais:

| Pacote | Licença | Observação |
| --- | --- | --- |
| Vue 3 / bibliotecas @vue | MIT | Interface reativa |
| @nextcloud/axios | GPL-3.0-or-later | Cliente HTTP Nextcloud |
| @nextcloud/l10n 3.4.1 | GPL-3.0-or-later | Localização nativa de strings Nextcloud (dependência transitiva já existente) |
| @nextcloud/router | GPL-3.0-or-later | Geração de rotas no Nextcloud |
| @nextcloud/vue 9.14.1 | AGPL-3.0-or-later | Componentes oficiais de navegação, formulários, diálogos e acessibilidade |
| @mdi/js 7.4.47 | Apache-2.0 | Caminhos vetoriais de ícones Material Design via NcIconSvgWrapper |
| axios | MIT | Dependência transitiva do cliente HTTP |

Os textos integrais MIT, GPL, AGPL e Apache-2.0 são mantidos em LICENSES/. Os pacotes transitivos devem ser reavaliados a cada alteração do lock de dependências. A existência deste inventário não substitui uma auditoria independente de todas as licenças e avisos dos artefatos gerados.

See [Localization](LOCALIZATION.md) for the native translation workflow.

## Optional WebDAV dependency security maintenance

The optional Nextcloud Files dependency uses WebDAV 5.11.0. The npm override
scopes minimatch 11.0.1 to WebDAV because its upstream range still resolves
the vulnerable 9.x series. The matcher and its nonrecursive glob dependency
use BlueOak-1.0.0; brace-expansion and balanced-match use MIT. The optional
dependency is exercised under Node 24 (including actual WebDAV path and
filename filtering); the production bundles are checked under Node 22 and
24 and contain no changes from this upgrade. Remove or revise the override
when a compatible upstream WebDAV release includes a patched matcher.

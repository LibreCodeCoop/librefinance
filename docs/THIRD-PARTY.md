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
| @nextcloud/router | GPL-3.0-or-later | Geração de rotas no Nextcloud |
| @nextcloud/vue 9.14.1 | AGPL-3.0-or-later | Componentes oficiais de navegação, formulários, diálogos e acessibilidade |
| @mdi/js 7.4.47 | Apache-2.0 | Caminhos vetoriais de ícones Material Design via NcIconSvgWrapper |
| axios | MIT | Dependência transitiva do cliente HTTP |

Os textos integrais MIT, GPL, AGPL e Apache-2.0 são mantidos em LICENSES/. Os pacotes transitivos devem ser reavaliados a cada alteração do lock de dependências. A existência deste inventário não substitui uma auditoria independente de todas as licenças e avisos dos artefatos gerados.

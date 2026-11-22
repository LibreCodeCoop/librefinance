<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Estratégia de qualidade — Nextcloud Server master

## Ambientes e segurança

O sistema sob teste é o checkout **oficial da branch master** do
`nextcloud/server`, com PHP/Apache fornecidos pela imagem Docker e a árvore
real do servidor montada somente leitura. O código é integralmente PHP
(serviços e contratos OCP) e Vue 3 compilado antes do uso, sem Python ou
sidecars em produção.

NUNCA executar a suíte contra a instância real da cooperativa: o script de
laboratório cria usuários sintéticos, um banco descartável, senhas aleatórias
temporárias, várias empresas e movimentos contábeis de teste, e elimina
os volumes ao concluir. A NFS-e continua opcional e externa; o Import antigo pode ser montado
apenas em testes de atualização/compatibilidade. A instalação limpa deve
funcionar apenas com o núcleo, incluindo o motor de importação.

A suíte usa um bloqueio exclusivo não bloqueante (`flock`) em
`tests/ci/.runtime-config/.smoke.lock`: **não execute matrizes de banco simultaneamente
no mesmo checkout**, pois compartilham configuração mutável e a árvore do
Nextcloud. Uma segunda execução sai imediatamente com código 75, sem alterar
contêineres. Para paralelismo no CI, utilize checkouts independentes.
O Compose de laboratório limita CPU/memória dos serviços para não concorrer sem
limites com outros trabalhos da máquina. Em falhas, o script registra o estado
dos contêineres e sinais de OOM antes da limpeza; não oferece repetição
automática de requisições financeiras.

## API: PHPUnit/integração e Behat por shards

O laboratório executa suites nativas PHP HTTP separadas por regras e
interfaces dos aplicativos. Behat roda contra o mesmo Nextcloud e usa
somente contas sintéticas, sem simular controlador nem pular regras de
autorização.

```sh
cd tests/behat
composer install --no-interaction --prefer-dist
sh run-shards.sh 1/2
sh run-shards.sh 2/2
```

Os shards 1/2 e 2/2 são identificados explicitamente nas features
`@shard1` e `@shard2`, com contextos PHP reais. Como a credencial e
`LF_BASE_URL` são fornecidas **somente pelo laboratório**, essas chamadas
isoladas não podem ser executadas contra uma produção sem adaptar o
script. O fluxo recomendado é `bash tests/ci/smoke.sh` em instalações
descartáveis com o Nextcloud master e extensões instaladas.

## Playwright: Chromium com navegação nativa real

```sh
npm ci --include=dev
npx playwright install --with-deps chromium
LF_RUN_PLAYWRIGHT=1 bash tests/ci/smoke.sh
```

O script descobre a porta local publicada pelo Docker e configura o domínio
confiável **no laboratório**. Localmente, o padrão são dois shards sequenciais.
Na CI, **três jobs independentes** executam `--shard=1/3`,
`--shard=2/3` e `--shard=3/3`, cada qual com um banco PostgreSQL
descartável. O banco e a seleção do usuário não são compartilhados entre
shards; aumentar o número de workers em um único banco seria arriscado.
As suites verificam login real, criação de organização, navegação em apps
instalados, categorias, troca rápida de espaços, layout móvel, fluxo de caixa
realizado e consentimento para IA. Os casos marcados com `@extensions`
dependem dos módulos adicionais e só são excluídos quando o laboratório
é executado deliberadamente com `LF_E2E_CORE_ONLY=1`. Ao rodar
individualmente, a variável `LF_PLAYWRIGHT_SHARD=1/2` escolhe o shard.

Em Ubuntu 26.04, os instaladores de certas versões do Playwright podem
não oferecer binário compatível. Em ambiente de desenvolvimento, é possível usar o
Chromium **já instalado** na máquina por meio de
`LF_E2E_CHROMIUM_PATH`, sem compartilhar credenciais. Essa escolha é
somente para ambiente de desenvolvimento; o CI Ubuntu deve usar a
instalação padrão `npx playwright install --with-deps chromium`.

**Segurança:** a configuração desativa screenshots, gravações e traces
automáticos por padrão; relatórios JSON e diretório de testes ficam
ignorados no Git para não expor capturas ou dados fictícios em PRs.

## Contrato das rotas declaradas nos controllers

As 92 rotas do núcleo (incluindo importação) e as rotas legadas opcionais
(13 do antigo Import e três da NFS-e) são declaradas por
`#[FrontpageRoute]`, não em `appinfo/routes.php`. A suíte mantém um snapshot
dos contratos históricos em `tests/ci/routes-expected.json` e executa
`occ router:list <apps> --index --output=json` contra o **Nextcloud
instalado**, comparando nome, método HTTP e URL em
`tests/ci/check-route-contracts.py`. O teste falha em rotas ausentes,
renomeadas, duplicadas ou inesperadas. O OpenAPI também é produzido a partir
dos atributos e falha diante de uma rota mutável sem contrato declarado.

Para alterar deliberadamente um endpoint, atualize o snapshot e o OpenAPI
no mesmo PR, com teste HTTP de compatibilidade; não apenas mude a fixture
para esconder uma regressão.

## Migrations iniciais consolidadas

Esta revisão pré-publicação consolidou antes 23 migrations em sete baselines. Agora o core
preserva sua baseline anterior e acrescenta a migration de integração dos
quatro domínios. A migration adicional de importação possui três
tabelas de origem duráveis, sem excluir ou substituir dados do antigo app Import.
A NFS-e permanece separada. O smoke testa instalação limpa em SQLite,
PostgreSQL e MariaDB, com 92 rotas nativas do núcleo e 16 rotas legadas
externas somente quando os aplicativos anteriores estiverem instalados. A migração é apenas DDL, sem
conversão de valores, exclusão de dados ou acesso a bancos externos. Antes
de publicar a mudança, também é obrigatório testar um upgrade de banco
de desenvolvimento previamente instalado, verificando retenção de dados e
conservação de índices/constraints. Em releases públicas futuros, não
reescrever migrations já distribuídas.

## Contratos OpenAPI

```sh
php tests/openapi/generate.php --check
npm run test:openapi
```

O gerador recusa rotas mutáveis novas sem contrato e o parser verifica o
formato OpenAPI. Tipos genéricos de resposta serão refinados; não tratar
quantidade de rotas como medida de maturidade.

## Gates de entrega

- Instalação limpa e isolamento em SQLite, PostgreSQL e MariaDB
- Testes de acesso negativo para empresa, grupo, módulo e perfil
- Idempotência e condições de corrida para lançamentos, impostos e importações
- Behat com cenário executado em ambos os shards, sem skips disfarçados
- Playwright com browser real, teclado, telas móveis e troca rápida de estado
- Lint PHP 8.3/8.5, build e testes Vue, licenças REUSE e verificação de OpenAPI
- PHPUnit/PSALM ou PHPStan, acessibilidade completa, migrações de atualização,
  matriz de perfis de carga, auditoria e validação com especialista fiscal:
  **ainda pendentes**, a menos que relatórios comprovem sua execução

Sem login de servidor de produção, sem mudança de rulesets e sem merge
automático. Só aprovar um PR depois de rever as verificações de todos os
jobs necessários no mesmo SHA.

## CI integrada e workflows organizacionais

Os workflows comuns de lint PHP, validação de manifesto, build npm, REUSE e
sincronização são cópias gerenciadas do catálogo público
`LibreCodeCoop/.github/workflow-templates/`, rastreadas em
`.github/actions-lock.txt`. Não edite as cópias diretamente; use o mecanismo
de atualização por PR e patches locais quando necessários.

O workflow **específico** `.github/workflows/finance-integration.yml` fixa o
Nextcloud Server e os SHAs de **Import legado e NFS-e**. A matriz financeira
instala os três apps e mantém testes HTTP/PHP, autorização, concorrência e
Behat em SQLite, PostgreSQL e MariaDB, **sem instalar Node/Chromium**.
Em paralelo, a matriz Playwright distribui os mesmos testes de navegador
por três jobs PostgreSQL isolados; cada um instala Nextcloud e executa
seu próprio shard, sem depender dos dados dos testes PHP. Não se perde
nenhum cenário de navegador ou banco. O check obrigatório
`LibreFinance integrated CI` exige sucesso de **contratos, três bancos e
todos os shards de navegador**; resultados ausentes, cancelados, ignorados
ou com falha impedem o merge. Os workflows organizacionais de lint e
build continuam independentes. O workflow deve ser validado em execução remota no mesmo SHA
antes de servir como requisito de merge.

Atualize os SHAs dos módulos com revisão e CI antes de integrar mudanças
entre repositórios. O app permanece em desenvolvimento: testes no laboratório
não certificam conexão bancária, emissão fiscal ou migração de dados reais.

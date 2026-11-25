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
os volumes ao concluir. A NFS-e continua opcional e externa; o esquema histórico do antigo Import
é exercitado por fixtures descartáveis em testes de atualização. A instalação limpa deve
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

## Testes unitários PHP

Na raiz do repositório, execute `composer install` e `composer test:unit`.
O plugin `bamarni/composer-bin-plugin` instala as dependências de teste
isoladamente em `vendor-bin/phpunit/`, com seu próprio `composer.lock`
e sem misturá-las às dependências de produção.
A configuração `phpunit.xml` descobre as classes em `tests/php/Unit/`, sem
instalar o Nextcloud. O workflow `php-unit.yml` executa essa suíte na matriz
de versões PHP suportadas pelo aplicativo.

Os testes unitários espelham o caminho e o namespace de uma classe existente
em `lib/`. Por exemplo, `lib/Domain/Currency/ExactConversion.php` corresponde
a `tests/php/Unit/Domain/Currency/ExactConversionTest.php`, no namespace
`OCA\\LibreFinance\\Tests\\Unit\\Domain\\Currency`. Não crie classes de teste
genéricas ou sem uma classe de produção correspondente.

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
npm run build
npx playwright install --with-deps chromium
LF_RUN_PLAYWRIGHT=1 bash tests/ci/smoke.sh
```

Os bundles `js/` e `css/` não são versionados. A CI compila ambos antes dos testes de navegador; as suítes exclusivamente de API e migração não dependem de Node.js. O job de contratos também gera e verifica o pacote App Store sem assinatura, incluindo os avisos de licença e excluindo dependências de desenvolvimento.

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

### Matriz de autorização SaaS no navegador

Além dos testes de apresentação Vue que isolam o contrato visual com
respostas simuladas, `tests/e2e/saas-real-authority.spec.mjs` usa três
sessões Nextcloud independentes, cookies e token CSRF reais, dois ambientes
e três livros jurídicos independentes. O laboratório cria grupos exclusivos
para esse caso e fornece a credencial temporária da instância apenas ao
processo de teste. O cenário exige opt-in explícito, restringe a URL ao
loopback e restaura a configuração de governança em `finally`.

| Ator | Ambiente/livro | Operação real | Resultado exigido |
| --- | --- | --- | --- |
| Delegado B, sem papel financeiro | A/A1 | Consultar contas | 404, apenas código de erro |
| Administrador da instância, sem concessão | A/A1 | Consultar lançamentos | 404, apenas código de erro |
| Delegado A, sem papel financeiro | B/B1 | Consultar lançamentos | 404, apenas código de erro |
| B, operador | A/A1 | Lançar e consultar | 201/200; ação disponível na tela |
| B, visualizador | A/A2 | Lançar | 403; ação ausente inclusive em tela móvel/escura |
| B, operador de A1 | A/A1 com contas de A2 | Lançar com IDs forjados | 404, sem dados de A2 |
| B, visualizador | A/A2 | Elevar a própria função | 403, sem alteração |
| B, revogado de A1 e visualizador de A2 | A/A1 e A/A2 | Próxima consulta e URL direta | 404/200; A1 e seus lançamentos não aparecem |
| A, proprietário | A/A1 | Consultar após revogar B | 200; lançamento original preservado |

O teste compara os códigos HTTP e a apresentação dos registros na sessão
real, sem interceptar respostas. Os testes PHP de integração continuam
responsáveis pela cobertura mais ampla de todos os domínios instalados,
corridas de concessões, políticas restritivas, convites, exclusão de
grupo, arquivos, MCP, resultados assíncronos de IA e NFS-e de rascunho.
A matriz acima é o critério executável, não uma certificação de produção
ou de acessibilidade integral.

## Contrato das rotas declaradas nos controllers

As rotas do núcleo (incluindo importação) e as rotas do aplicativo
opcional NFS-e são declaradas por
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

## Migrations e atualização

O núcleo possui uma baseline de desenvolvimento seguida de migrations
incrementais; a NFS-e conserva suas próprias migrations. A suíte valida a
instalação limpa em SQLite, PostgreSQL e MariaDB, os contratos de rotas
e as tabelas de governança, importação e concessões. Os jobs históricos
de Import verificam a preservação de registros e mapeamentos no upgrade.
Os mesmos três jobs executam também um upgrade real da versão pré-SaaS
0.18.5 (commit de referência 192bfb0b) para a versão atual, com dois livros,
duas identidades jurídicas em um livro legado (uma inativa), lançamentos,
estorno, contas, concessões individuais e de grupo e trilha de auditoria.
O snapshot compara todas as linhas históricas desses domínios antes e
depois, exige que nenhum ambiente/vínculo SaaS seja inferido e verifica
pela API os saldos, isolamento, permissões e indicação administrativa
de revisão pendente. O teste não autoriza dividir ou reclassificar livros.

A contagem atual de migrations e rotas é obtida com
`python3 tests/ci/coverage-inventory.py`.

Não confundir a fixture histórica de Import com uma homologação de todos
os caminhos de upgrade. Novas migrations devem ser incrementais,
portáveis e preservar dados e identificadores. Qualquer consolidação
pré-release exige uma avaliação separada de equivalência de esquema,
instalação limpa e dos caminhos de atualização suportados. Em uma release
pública, não reescrever migrations já distribuídas.

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
- Paginação por cursor das gestões administrativas SaaS: 52 livros em A (50+2), sem limite silencioso, sem duplicações e sem vazamento do ambiente B; acesso revalidado antes de cada página, teste móvel Playwright para carregar mais e trocar ambiente
- Bootstrap SaaS de empresa jurídica: livro/contas/identidade/vínculo/auditoria na mesma transação; cadastro inválido não deixa livro órfão; replay preserva companyId, alteração da identidade com a mesma chave retorna 409; gestões sem empresa e POST de empresa legado continuam compatíveis
- Revisão otimista das concessões internas SaaS: duas alterações simultâneas com a mesma versão (uma aceita, outra HTTP 409), falta de precondição (HTTP 428), revogação seguida de regrant obsoleto, segregação entre livros e manutenção do modo legado; migration aditiva validada com rollback em SQLite, PostgreSQL e MariaDB
- NFS-e opcional: acesso HTTP real em ambientes A/B e livros A1/A2, vínculo de empresa, políticas restritivas de ambiente/livro, funções financeiras distintas, revogação e suspensão; somente rascunhos, sem emissão fiscal
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
Nextcloud Server e o SHA da **NFS-e**. A matriz financeira
instala o núcleo e a NFS-e; jobs separados verificam instalação apenas
do núcleo e preservação do esquema histórico de Importação. A matriz
integrada mantém testes HTTP/PHP, autorização, concorrência e
Behat em SQLite, PostgreSQL e MariaDB, **sem instalar Node/Chromium**.
Em paralelo, a matriz Playwright distribui os mesmos testes de navegador
por três jobs PostgreSQL isolados; cada um instala Nextcloud e executa
seu próprio shard, sem depender dos dados dos testes PHP. Não se perde
nenhum cenário de navegador ou banco. O check obrigatório
`LibreFinance integrated CI` exige sucesso de **contratos, três bancos,
todos os shards de navegador, instalação sem módulos e upgrade histórico**; resultados ausentes, cancelados, ignorados
ou com falha impedem o merge. Os workflows organizacionais de lint e
build continuam independentes. O workflow deve ser validado em execução remota no mesmo SHA
antes de servir como requisito de merge.

Atualize o SHA do aplicativo opcional com revisão e CI antes de integrar mudanças
entre repositórios. O app permanece em desenvolvimento: testes no laboratório
não certificam conexão bancária, emissão fiscal ou migração de dados reais.

### Medição de latência da CI

Para diagnosticar a demora de um PR sem confundir um job em andamento
com uma falha, use os metadados de um run específico:

```sh
gh run view RUN_ID -R LibreCodeCoop/librefinance \
  --json databaseId,attempt,headSha,status,conclusion,createdAt,startedAt,updatedAt,jobs \
  | python3 tests/ci/report-timing.py
PYTHONDONTWRITEBYTECODE=1 python3 tests/ci/test-report-timing.py
```

O relatório preserva SHA, tentativa, estado e conclusão, distingue
tempos ausentes de zero e mede o intervalo observado do run, o job mais
longo e a preparação anterior à primeira etapa de trabalho conhecida
(`setup_before_work_seconds`). Quando o último passo de trabalho conhecido
foi executado e seu intervalo está contido no do job, `post_work_tail_seconds`
mede do fim desse passo até a conclusão do job.

Em runs concluídos, `job_parallelism` mede do primeiro início à última
conclusão dos jobs observados (`job_span_seconds`) e soma suas durações
(`sum_job_runtime_seconds`). `busy_seconds` conta o tempo com pelo menos um
job ativo e `overlap_seconds` conta o tempo com dois ou mais. O pico
(`peak_parallel_jobs`) considera simultaneidade durante um intervalo de
tempo. A média (`average_parallel_jobs`) divide a soma das durações pelo
intervalo observado, incluindo eventuais lacunas. Esses valores descrevem
os jobs observados, sem estimar ocupação da capacidade total dos runners.

As contagens indicam quantos jobs foram medidos, ignorados (`skipped`) ou
ficaram sem medição por estado ou timestamps. `measured` cobre todos os
jobs fornecidos que não foram ignorados. `partial` cobre apenas os que
têm intervalos válidos. Runs em andamento (`run_incomplete`) e runs sem
intervalos válidos (`no_measurable_jobs`) retornam `null` nas métricas.
Um job com início e fim iguais é medido com duração zero e não aumenta o
pico. A média é `null` quando o intervalo total é zero.

O intervalo entre criação e início do run usa os timestamps do próprio
run. Os metadados disponíveis são insuficientes para medir a espera de cada
job ou reconstruir o caminho crítico do DAG. Em novas tentativas, o tempo
desde a criação original pode incluir tentativas anteriores. Compare a
tentativa e os tempos dos jobs. O script lê somente o JSON fornecido e
serve como diagnóstico inicial. A qualificação de release segue os checks
de integração e o processo do épico #70.

Baseline observado: [run 45451932591](https://github.com/LibreCodeCoop/librefinance/actions/runs/45451932591),
SHA `c8c7de5629930c3acb461fa1f82ba941adafdf13`, tentativa 1,
12 jobs concluídos com sucesso. O intervalo criação-atualização final
foi de 427 segundos; o atraso observado até o início foi de 0 segundos.

| Job | Duração | Preparação antes do trabalho | Etapa mais longa |
| --- | ---: | ---: | ---: |
| SQLite integrado | 419 s | 20 s | 396 s |
| MariaDB integrado | 402 s | 22 s | 377 s |
| PostgreSQL integrado | 350 s | 17 s | 329 s |
| SQLite sem extensões | 375 s | 14 s | 358 s |
| Chromium 1/3 | 279 s | 50 s | 225 s |
| Chromium 2/3 | 296 s | 41 s | 251 s |
| Chromium 3/3 | 255 s | 41 s | 210 s |
| Upgrade histórico (três bancos) | 83-100 s | 13-15 s | 39-47 s |
| Contratos | 33 s | não medida | 6 s |

Neste run, o job mais longo foi o laboratório integrado com SQLite.
O próximo experimento deve medir as suítes internas do smoke antes de
propor mudanças de cache ou paralelismo. Para avaliar uma melhora, compare
runs com revisões e cobertura equivalentes, mantendo todos os bancos,
checks e isolamento.

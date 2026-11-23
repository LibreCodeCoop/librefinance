<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Instance governance and delegated group administration

LibreFinance is one installed app with independently authorized financial
contexts. The naming is generic; financial contexts can belong to businesses,
households, organizations or collectives. A Nextcloud group is an access
boundary, not automatically a legal company.

## Default and managed modes

Default mode remains compatible with existing personal deployments:
authenticated users can create financial spaces and owners manage access.

Only an **instance administrator** may enable managed provisioning. When
enabled, ordinary members and group subadministrators cannot create contexts.
An instance administrator creates/binds each financial context to one
existing Nextcloud group. A group subadministrator (through Nextcloud's
public `OCP\Group\ISubAdmin` API) may then manage that context, including
its bank accounts, contacts, financial data and user permissions. They do
**not** gain permissions in other contexts or the global administration API.

Financial contexts with an assigned `governance_gid` require membership in
that group, a group-subadmin grant for that specific group, or a global admin.
Existing unbound contexts retain their data. When managed provisioning is
enabled, they become inaccessible to ordinary users until an instance admin
reviews and binds them to an approved group. Revoking Nextcloud
group membership immediately denies access at the server authorization layer.
An instance administrator remains a trusted infrastructure administrator and
can access all managed contexts.

Delegates cannot grant arbitrary Nextcloud groups, change the governed
group's group-role, or grant users outside the bound group. No automatic
mapping from company, group name, or CNPJ is performed.

## JSON API

All endpoints use the same Nextcloud session/CSRF and HTTP authentication
mechanisms as the other LibreFinance APIs. Neither static frontend visibility
nor a client-supplied context ID conveys authorization.

| Method | Route | Audience |
| --- | --- | --- |
| GET | `/apps/librefinance/api/governance/capabilities` | Authenticated user |
| GET | `/apps/librefinance/api/admin/governance` | Instance admin |
| PUT | `/apps/librefinance/api/admin/governance` | Instance admin |
| GET | `/apps/librefinance/api/admin/contexts` | Instance admin |
| GET | `/apps/librefinance/api/admin/groups/{gid}/delegates` | Instance admin |
| POST | `/apps/librefinance/api/admin/groups/{gid}/delegates` | Instance admin |
| DELETE | `/apps/librefinance/api/admin/groups/{gid}/delegates/{uid}` | Instance admin |
| GET | `/apps/librefinance/api/admin/groups?search=...&offset=...` | Instance admin |
| POST | `/apps/librefinance/api/admin/contexts` | Instance admin |
| PUT | `/apps/librefinance/api/admin/contexts/{spaceId}/group` | Instance admin |
| POST | `/apps/librefinance/api/admin/contexts/{spaceId}/archive` | Instance admin |
| POST | `/apps/librefinance/api/admin/contexts/{spaceId}/restore` | Instance admin |
| GET | `/apps/librefinance/api/spaces/{spaceId}/governance` | Authorized context user |
| GET | `/apps/librefinance/api/spaces/{spaceId}/members` | Context manager |
| POST | `/apps/librefinance/api/spaces/{spaceId}/members` | Context manager |
| DELETE | `/apps/librefinance/api/spaces/{spaceId}/members/{uid}` | Context manager |
| GET | `/apps/librefinance/api/spaces/{spaceId}/groups` | Context manager |
| POST | `/apps/librefinance/api/spaces/{spaceId}/groups` | Context manager, bounded |
| DELETE | `/apps/librefinance/api/spaces/{spaceId}/groups/{gid}` | Context manager, bounded |

`PUT /api/admin/governance`: `{"managed":true}`.

`POST /api/admin/contexts`:
`{"name":"Example Organization","kind":"organization","gid":"example-group","currency":"BRL"}`.

`PUT /api/admin/contexts/123/group`:
`{"gid":"example-group"}`.

All account, banking, invoice, contact and custom-field routes retain their
space-scoped APIs and existing role checks. These route contracts do not
constitute full external connector certification or formal accounting
consolidation. Admin API callers are protected against non-admin access even
when a user is a Nextcloud group subadministrator.

## Deployment and safety

The `lf_spaces.governance_gid` migration is nullable and does not delete,
move or automatically reinterpret financial data. Binding existing spaces
changes authorization, so admins must verify the intended group and its
members before binding. A delegated admin is not automatically an instance
admin. Test dynamic group membership, revoked subadmin status, cross-space
read/write, attachment access and optional modules before institutional
rollout. Only synthetic CSV/OFX fixtures belong in automated tests.

## Delegação pelo painel do LibreFinance

Em Administração do Nextcloud > LibreFinance, um administrador da instância
pode pesquisar grupos, selecionar um grupo e conceder ou revogar o papel de
gestor financeiro a um usuário que já pertença a esse grupo. A concessão é
limitada ao LibreFinance e não cria um subadministrador global do Nextcloud.
Subadministradores Nextcloud preexistentes continuam reconhecidos, mas a
revogação no LibreFinance não revoga seu cargo administrativo do Nextcloud.

A associação entre usuário e grupo é revalidada a cada acesso; remover o
usuário do grupo torna a delegação inefetiva imediatamente. Os endpoints de
concessão são exclusivos do administrador da instância, mesmo quando o
requisitante já é gestor de outro perfil financeiro.

## Gerenciamento por grupo no LibreFinance SaaS (v1)

Os novos ambientes SaaS têm identidade própria, independente de espaços
financeiros e do nome do grupo Nextcloud. A administração da instância pode
consultar o estado por grupo com GET /api/saas/v1/admin/groups?search=&offset=
(retorno paginado groups, nextOffset), e habilitar/suspender o grupo
através de PUT /api/saas/v1/admin/groups/{gid}/enablement com
{ "enabled": true|false }. A tela administrativa do aplicativo usa as mesmas
rotas com componentes Vue do Nextcloud.

Os estados são disabled, enabled_pending_admin, active e suspended.
A recuperação de um ambiente suspenso exige a instância autenticada,
motivo obrigatório e a operação POST
/api/saas/v1/admin/groups/{gid}/recover com reasonCode tipado:
delegation_reviewed, group_admin_restored,
security_incident_resolved ou membership_corrected.
O evento environment.recovered registra autor, ambiente e motivo em
lf_environment_audit. O ambiente permanece com a mesma identidade e retorna
para enabled_pending_admin, nunca diretamente para active.
A ativação posterior cabe a um administrador qualificado do grupo, com nova
verificação de delegação e sem concessão de qualquer papel financeiro.
Administradores de grupos só podem ativar o ambiente previamente habilitado
se forem subadministradores atuais no Nextcloud ou delegados válidos.
Nenhum desses papéis concede acesso automático a extratos, lançamentos,
empresas ou outros dados financeiros. Os contextos gerenciados legados
continuam distintos e ainda exigem revisão antes de migração ao modelo SaaS.

## Administrar gestões independentes por ambiente SaaS

Depois que a instância habilita o grupo e um subadministrador Nextcloud ou
delegado autorizado ativa o ambiente, o responsável abre **Gestões financeiras**
e encontra **Ambientes LibreFinance**. Se administra dois ou mais grupos, deve
selecionar explicitamente **um ambiente administrativo**. A aplicação não
combina permissões, gestões nem informações financeiras entre esses grupos.

O responsável pode listar as gestões do ambiente selecionado e solicitar a
criação de um novo livro contábil independente. Para criar:

1. Selecione o ambiente ativo de sua competência.
2. Informe o nome da gestão e o **UID Nextcloud de outro participante daquele
   grupo**, que será o proprietário financeiro. A interface não concede ao
   administrador do grupo direitos contábeis.
3. Escolha o tipo (organização, coletivo ou pessoal) e confira ambiente,
   proprietário e separação de dados na confirmação.
4. Confirme a criação. A aplicação chama
   `POST /api/saas/v1/environments/{environmentId}/workspaces` com
   `name`, `ownerUid`, `kind`, `currency`, `color` e `idempotencyKey`.
   A mesma chave é reutilizada se a resposta HTTP falhar ou for perdida e
   a intenção original for reenviada; alterações no pedido usam uma nova chave.

O backend exige autorização de administração do ambiente ativo, participação
atual do proprietário no grupo e exclusão da autopromoção administrativa.
Também impõe as restrições efetivas de políticas, como
`workspaces.maxCount`, e as unicidades de persistência. A associação do
ambiente não concede automaticamente leitura do livro nem privilégios a todo
o grupo. Para atribuir papéis financeiros individuais, o **proprietário**
usa o controle de acessos da própria gestão financeira.

`GET /api/saas/v1/environments/{environmentId}/workspaces` retorna apenas
metadados das gestões disponíveis ao administrador daquele ambiente:
`id`, `name`, `ownerUid` e `kind`. Não retorna lançamentos, extratos,
saldos ou conteúdos de outras organizações. Se o acesso administrativo for
revogado ou o ambiente suspenso, a próxima solicitação protegida é negada,
e a interface descarta dados carregados daquele contexto.

**Diferença importante:** uma empresa cadastrada em uma mesma gestão
compartilha seu livro com as demais empresas daquela gestão. Para ter caixa,
saldos, lançamentos e papéis separados por empresa, crie uma **nova gestão
independente**; o proprietário cadastra depois a identidade legal em Dados
da organização. A criação de gestão não configura banco externo, emissão
fiscal certificada nem compartilhamento externo.

A administração por ambiente exige uma instalação Nextcloud com o LibreFinance
habilitado, grupo ativo, subadministrador ou delegado autorizado, e usuários
existentes no grupo. Os erros por permissão, ambiente inválido, proprietário
inapto, limite de gestões ou conflito de idempotência são tratados pelo
backend; mensagens na interface não substituem a autorização PHP.

## Policies por gestão financeira (workspace SaaS)

Além das policies de instância e ambiente, o **proprietário ou gestor
financeiro** de uma gestão ativa pode aplicar restrições adicionais somente
ao seu próprio livro contábil. A administração de grupos Nextcloud não
concede implicitamente esse papel financeiro.

A API autenticada (com sessão Nextcloud ou app password) é:

- `GET /api/saas/v1/workspaces/{spaceId}/policies/effective`: lista
  os valores efetivos de uma gestão em que o ator é membro financeiro.
- `GET /api/saas/v1/workspaces/{spaceId}/policies/{policyKey}`: consulta
  valor, origem, bloqueio, limites herdados, revisão e capacidade de edição.
- `PUT /api/saas/v1/workspaces/{spaceId}/policies/{policyKey}`: configura
  uma restrição adicional com `valueJson` (JSON serializado em string) e
  `expectedRevision` (inteiro). Exige a capability `workspace.configure`.
- `DELETE /api/saas/v1/workspaces/{spaceId}/policies/{policyKey}?expectedRevision=N`:
  remove o valor local, restaura a herança e grava uma nova revisão e auditoria.

O backend revalida na **requisição atual** a identidade, a associação
confirmada do livro ao ambiente, o grupo Nextcloud ativo, a participação do
usuário e o papel financeiro no livro. IDs forjados, livros suspensos ou
permissões revogadas não expõem policies. O valor de workspace **nunca**
pode reabilitar algo proibido pelo ambiente ou pela instância; uma tentativa
de ampliação é negada sem alterar o valor ou a revisão persistida.

Policies não substituem ACLs: por exemplo, habilitar `imports.enabled`
não autoriza importar sem as permissões financeiras apropriadas. Policies
`workspaces.maxCount` e `environments.enabled` não aceitam escrita no
escopo workspace. A leitura informa os escopos permitidos pelo registro
tipado para evitar menus de edição sem autoridade. Cada gravação e limpeza
usa compare-and-set por revisão e gera trilha de auditoria imutável.

O recurso aplica-se apenas a workspaces SaaS vinculados e confirmados.
Instalações pessoais legadas continuam com as APIs existentes; não são
reinterpretadas como novos ambientes ou migradas automaticamente.

### Editor visual das policies SaaS

A interface reutiliza o componente nativo de policies em três contextos,
sempre com resolução independente no servidor:

- **Configurações do administrador Nextcloud → LibreFinance**: políticas da
  instância, com origem e revisão; a administração global não concede acesso
  aos livros contábeis.
- **Gestões financeiras → Ambientes LibreFinance**: subadministrador ou delegado
  seleciona explicitamente o ambiente ativo e pode restringir suas políticas,
  sem ampliar os limites definidos pela instância.
- **Gestões financeiras → Políticas da gestão atual**: proprietário ou gestor
  financeiro pode restringir políticas do seu livro SaaS, sem modificar os
  demais livros. Gestões legadas sem vínculo SaaS não são convertidas.

O editor apresenta valor efetivo, origem, revisão, bloqueio ancestral,
capacidade de edição e as ações **Salvar restrição** e **Herdar valor**.
Tipos suportados na UI: booleano, teto numérico inteiro e conjunto permitido.
O cliente evita formatos inválidos, mas a aplicação **nunca confia** nos
valores recebidos: os tipos, escopos, permissões, herança, auditoria e a
revisão otimista continuam sendo validados em cada requisição PHP.

Em uma revisão conflitante (HTTP 409), os valores atuais são recarregados
e o usuário precisa revisá-los antes de salvar novamente. Revogação,
suspensão e troca de ambiente descartam respostas desatualizadas no cliente.

### Participantes internos e convites externos SaaS

Conceder um papel financeiro interno pela API
`POST /api/spaces/{spaceId}/members` requer que o destinatário seja,
**no momento da concessão**, participante do mesmo grupo Nextcloud
associado e confirmado ao livro SaaS. Essa verificação usa o vínculo
autoritativo `lf_environment_spaces`/`lf_environment_groups`, não o
campo legado `governance_gid`, que pode estar vazio. Uma conta da
instância ou de outro grupo não recebe uma associação financeira
persistida apenas por existir no Nextcloud.

A participação no grupo, sozinha, também **não concede acesso**: o
proprietário deve atribuir explicitamente um papel contábil. A permissão
é reavaliada nas requisições seguintes, com o estado do ambiente. A
revogação de associação interna continua permitida como operação de
limpeza, inclusive se o participante já tiver saído do grupo.

Convites **externos** ainda exigem um fluxo próprio com política
`externalSharing.enabled`, aprovação, expiração, escopo, auditoria e
revogação efetiva. A API de membros internos não substitui esse fluxo e
não deve ser usada para criar concessões antecipadas ou dormentes.

### Auditoria administrativa de ambientes SaaS

O administrador da instância pode consultar o histórico dos ambientes sem
adquirir acesso aos lançamentos, saldos ou documentos financeiros.
O endpoint autenticado é
`GET /api/saas/v1/admin/groups/{gid}/audit?beforeId=N`.
O `gid` é resolvido para a identidade **durável** do ambiente armazenado,
inclusive quando o grupo Nextcloud original foi excluído.

Cada resposta contém `environmentId`, `events` e `nextBeforeId`.
Os eventos incluem ID, ator, ação, tipo/identificador do assunto e data Unix.
A paginação usa somente IDs anteriores à página atual, até 30 eventos por
requisição; isso evita duplicações por novos registros simultâneos. A resposta
não contém dados financeiros, e qualquer não administrador recebe HTTP 403.

Na tela de administração Nextcloud → LibreFinance, o botão
**Auditoria** abre a lista paginada de ações do ambiente; a consulta não
modifica políticas, membros nem o estado do grupo. Recuperações auditadas,
suspensões e exclusões continuam preservadas no banco. O histórico é
administrativo, diferente da auditoria contábil de cada gestão.

### Policies de apresentação individuais

A hierarquia de políticas passa a incluir o nível **Usuário**, restrito à
conta autenticada e ao seu livro SaaS ativo. Essa camada **não concede
acesso financeiro**: para consultar ou alterar a própria preferência é
necessário ter um papel financeiro vigente no workspace. Nenhum endpoint
aceita `uid` nem `environmentId` fornecido pelo frontend para editar
a configuração de outra pessoa.

A primeira definição aplicável é `dashboard.kpis.enabled`, booleana,
com padrão `true` e scopes explícitos `instance`, `environment`,
`workspace`, `user`. Quando definida como `false`, oculta somente os
cartões de indicadores resumidos do painel. Isso é **preferência visual**:
não protege valores financeiros, não desativa APIs nem esconde relatórios;
essas ações continuam exigindo ACLs e policies operacionais próprias.
Instâncias podem restringir ambientes, ambientes restringem livros e
gestores financeiros restringem todos os usuários do mesmo livro. Um
usuário nunca consegue reabilitar uma restrição herdada.

API autenticada por sessão Nextcloud:
- `GET /api/saas/v1/workspaces/{spaceId}/policies/me/effective` lista somente
  as definições com scope `user`, sem expor configurações de terceiros.
- `GET /api/saas/v1/workspaces/{spaceId}/policies/me/{policyKey}` retorna
  valor efetivo, origem, bloqueio ancestral, revisão e edição permitida.
- `PUT /api/saas/v1/workspaces/{spaceId}/policies/me/{policyKey}` recebe
  `valueJson` e `expectedRevision`; é rejeitado se tentar ampliar o
  parent, alterar chave não compatível ou usar revisão antiga.
- `DELETE /api/saas/v1/workspaces/{spaceId}/policies/me/{policyKey}`
  recebe `expectedRevision` e volta a herdar a configuração superior.

Cada alteração possui auditoria append-only no esquema existente
`lf_saas_policy_audit`, scope `user` e comparação atômica de revisão.
Somente o membro financeiro interno do grupo pode **editar**; convites
externos de leitura podem consultar o valor efetivo, mas não alterar
preferências pela API de convidados. Ambientes excluídos, suspensos ou
sem vínculo SaaS confirmado não possuem um escopo de usuário ativo.

Na interface, o participante encontra **Preferências individuais do
painel** em Gestões financeiras. Ao retornar à Visão geral, as políticas
são recarregadas para o livro selecionado; em caso de erro de leitura
na gestão SaaS, o painel não revela cartões de indicadores ocultos.

### Concessões financeiras externas (SaaS)

Um convite externo não é participação no grupo Nextcloud e não cria linhas em
`lf_members`. Destinatários devem ter uma **conta Nextcloud existente**;
convites por e-mail, URL pública e contas anônimas não são oferecidos.
Somente o proprietário financeiro atual do livro SaaS pode convidar. A policy
`externalSharing.enabled` precisa permitir o compartilhamento em **todos**
os níveis (instância, ambiente e workspace). O valor padrão é **false**.

O backend permite os papéis externos `viewer` ou `auditor`, exclusivamente
de leitura: não há papel externo de lançamento, aprovação, gestão ou
administração do ambiente. O convidado, que deve estar **fora do grupo
Nextcloud do ambiente**, precisa aceitar explicitamente o convite usando a
própria sessão autenticada. A validade deve estar entre 5 minutos e 30 dias.
O aceite não concede permissões em outros livros ou grupos.

- `GET /api/saas/v1/invitations?afterId=N`: caixa pessoal do destinatário,
  limitada a 50 convites ativos por página; nunca mostra outros destinatários.
- `GET /api/saas/v1/workspaces/{spaceId}/invitations?afterId=N`: proprietário
  lista destinatários, papéis, revisões, estado e expiração do livro.
- `POST /api/saas/v1/workspaces/{spaceId}/invitations`: proprietário envia
  `recipientUid`, `role`, `expiresAt` (Unix segundos) e
  `expectedRevision` (0 na criação). Conflitos impedem convites duplicados.
- `POST /api/saas/v1/workspaces/{spaceId}/invitations/{id}/accept`: somente
  destinatário, com `expectedRevision` atual, aceita convite pendente.
- `DELETE /api/saas/v1/workspaces/{spaceId}/invitations/{id}?expectedRevision=N`:
  somente proprietário revoga o acesso em tempo de requisição.
- `GET /api/saas/v1/workspaces/{spaceId}/invitations/history?afterId=N`:
  proprietário consulta eventos append-only, até 50 por página.

A conta externa vê apenas os livros especificamente aceitos. A autorização
é reconstruída **no servidor em cada requisição** e revogada quando o
convite for revogado, expirar, a policy de qualquer ancestral bloquear, o
grupo ou ambiente for suspenso ou o livro for arquivado. Não se confia no
campo `spaceId` da URL para concessão implícita. Uma nova concessão após
revogação precisa de uma revisão atual e **novo aceite**. Uma conta que
passar a integrar o grupo Nextcloud deve obter um papel financeiro interno
separado; o convite externo não se converte em participação interna.

Limites desta primeira entrega: não há envio automático de e-mail, convite
por endereço que ainda não pertença ao Nextcloud, acesso de escrita externo
nem edição visual destes convites. O fluxo de API é auditado e intencionalmente
restritivo. Administradores da instância não ganham leitura financeira pelo
fato de serem administradores: só um convite dirigido à própria conta,
aceito e não expirado, pode conceder leitura a um livro particular.

### Interface de convites financeiros

Em **Gestões financeiras**, o proprietário de um livro SaaS vê
**Compartilhamento financeiro externo**. Informe o identificador de uma
conta Nextcloud existente, selecione **Leitura** ou **Auditoria** e configure
validade entre 1 e 30 dias. O destinatário encontrará o convite em
**Convites financeiros recebidos** e deverá selecionar **Aceitar convite**.
Até o aceite, nenhuma permissão existe. Conceder leitura não habilita lançamentos
financeiros nem acesso aos outros livros do mesmo ambiente.

O proprietário pode revogar ou convidar novamente após revogação/expiração.
A interface respeita a revisão otimista e atualiza os valores após erro 409;
o servidor continua revalidando a permissão em cada operação. Os dados
contábeis não são enviados no convite, e as alterações são auditadas.

### Consulta de auditoria das policies SaaS

Cada alteração de policy é registrada em `lf_saas_policy_audit`, incluindo
identidade do ator, escopo, chave, valor anterior, valor novo, revisão e data.
A consulta de histórico é **exclusivamente em leitura**, sem acesso a saldos
ou lançamentos. Ela não concede, restaura ou amplia permissões financeiras.

A API tem quatro rotas autenticadas, todas com cursor opcional
`beforeId` para eventos anteriores ao último evento recebido:

- `GET /api/saas/v1/admin/policies/audit`: apenas administrador Nextcloud,
  somente as alterações das policies da instância.
- `GET /api/saas/v1/environments/{environmentId}/policies/audit`:
  administrador delegado/subadministrador **atual** desse ambiente ativo.
- `GET /api/saas/v1/workspaces/{spaceId}/policies/audit`: apenas
  proprietário/gestor financeiro desse livro contábil ativo e confirmado.
- `GET /api/saas/v1/workspaces/{spaceId}/policies/me/audit`: participante
  financeiro autenticado, **somente suas alterações pessoais** naquele
  livro; não recebe identificador de outro usuário na requisição.

Cada página contém até 30 eventos, em ordem de ID decrescente, e
`nextBeforeId` para continuação sem deslocamentos por novos registros.
O filtro de escopo é aplicado pelo banco **antes** da paginação, nunca
após buscar uma tabela inteira. Valores JSON e nomes de ator são renderizados
como texto na interface, sem executar ou interpretar HTML.

Nas telas existentes de policies do LibreFinance, o botão
**Ver histórico de policies** apresenta os eventos do escopo selecionado
e permite carregar os anteriores. Ao trocar de livro ou ambiente, as
respostas pendentes são descartadas para não misturar históricos.
O histórico administrativo é diferente da auditoria financeira de cada
livro, que segue suas próprias regras de preservação.

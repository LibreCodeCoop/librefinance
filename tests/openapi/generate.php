<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/**
 * Explicit, reviewable OpenAPI documentation compiled from every actual
 * Nextcloud controller route attribute. A write route without a request
 * contract breaks this generator. --check detects stale committed JSON.
 *
 * Limit: response object schemas are structural at this development stage.
 * Use the real HTTP/Behat tests for response and denial contract evidence.
 */
// Generate core OpenAPI from core controllers without optional apps.
$core = (string)(getenv('LF_OPENAPI_CORE') ?: dirname(__DIR__, 2));
$project = dirname($core);
$includeExtensions = in_array('--check-extensions', $argv, true);
$apps = $includeExtensions ? ['librefinance', 'librefinance_nfse'] : ['librefinance'];
$info = simplexml_load_file($core . '/appinfo/info.xml');
if ($info === false || (string)$info->id !== 'librefinance'
    || trim((string)$info->version) === '') {
    throw new RuntimeException('Invalid LibreFinance application manifest');
}
$appVersion = (string)$info->version;
$appRoots = [
    'librefinance' => $core,
    'librefinance_nfse' => (string)(getenv('LF_OPENAPI_NFSE') ?: $project . '/librefinance_nfse'),
];

/**
 * Format: field:type! (required), ? optional; types s/i/b/date/array/object.
 * Each spec describes the controller JSON contract, NOT Akaunting's database.
 */
$writeForms = [
     'librefinance:space#create' => 'name:s!,kind:s!,currency:s!,color:s',
     'librefinance:space#rename' => 'name:s!,color:s',
    'librefinance:saasPolicy#write' => 'valueJson:s!,expectedRevision:i!',
    'librefinance:saasPolicy#writeEnvironment' => 'valueJson:s!,expectedRevision:i!',
    'librefinance:saasPolicy#writeWorkspace' => 'valueJson:s!,expectedRevision:i!',
    'librefinance:saasPolicy#writeUser' => 'valueJson:s!,expectedRevision:i!',
    'librefinance:saasExternalInvitation#create' => 'recipientUid:s!,role:s!,expiresAt:i!,expectedRevision:i',
    'librefinance:saasExternalInvitation#accept' => 'expectedRevision:i!',
    'librefinance:environment#enable' => 'enabled:b!',
    'librefinance:environment#recoverGroup' => 'reasonCode:s!',
    'librefinance:saasWorkspace#create' => 'name:s!,ownerUid:s!,idempotencyKey:s!,kind:s,currency:s,color:s,legalName:s,companyCountry:s,companyTaxId:s',
    'librefinance:governance#configure' => 'managed:b!',
    'librefinance:governance#provision' => 'name:s!,kind:s!,gid:s!,currency:s',
    'librefinance:governance#bind' => 'gid:s!',
    'librefinance:governance#grantDelegate' => 'uid:s!',
    'librefinance:space#share' => 'uid:s!,role:s!,expectedRevision:i',
    'librefinance:space#shareGroup' => 'gid:s!,role:s!',
    'librefinance:account#create' => 'code:s!,name:s!,class:s!,isBank:b,bankDetails:object',
    'librefinance:companyLogo#upload' => 'imageBase64:s!',
    'librefinance:bankAccountLogo#upload' => 'imageBase64:s!',
    'librefinance:rateHistory#record' => 'sourceCurrency:s!,targetCurrency:s!,rate:s!,effectiveOn:date!,sourceType:s!,sourceRef:s!',
    'librefinance:paymentMethod#create' => 'name:s!,kind:s!,bankAccountId:i',
    'librefinance:paymentMethod#update' => 'name:s!,bankAccountId:i,active:b',
    'librefinance:account#update' => 'name:s!,active:b,bankDetails:object',
    'librefinance:category#create' => 'code:s!,name:s!,kind:s!,parentId:i,accountId:i,color:s',
    'librefinance:category#update' => 'name:s!,color:s!,active:b',
     'librefinance:company#create' => 'legalName:s!,country:s!,taxId:s,tradeName:s,municipalRegistration:s,stateRegistration:s,customFields:object,profile:object',
     'librefinance:company#update' => 'legalName:s!,country:s!,taxId:s,tradeName:s,municipalRegistration:s,stateRegistration:s,active:b,customFields:object,profile:object',
    'librefinance:customField#create' => 'key:s!,label:s!,type:s!,required:b,options:array,visible:b,apiVisible:b,help:s,sortOrder:i,columnSpan:i',
    'librefinance:customField#createForEntity' => 'key:s!,label:s!,type:s!,required:b,options:array,visible:b,apiVisible:b,help:s,sortOrder:i,columnSpan:i',
    'librefinance:customField#updateForEntity' => 'label:s!,required:b,active:b,visible:b,apiVisible:b,help:s,sortOrder:i,columnSpan:i',
    'librefinance:customField#update' => 'label:s!,required:b,active:b,visible:b,apiVisible:b,help:s,sortOrder:i,columnSpan:i',
    'librefinance:savedReport#create' => 'name:s!,kind:s!,from:date,through:date!,columns:array,accountClass:s',
    'librefinance:savedReport#update' => 'name:s!,kind:s!,from:date,through:date!,columns:array,accountClass:s',
    'librefinance:contact#create' => 'name:s!,kind:s!,email:s,taxId:s,customFields:object',
    'librefinance:contact#update' => 'name:s,kind:s,email:s,taxId:s,active:b,customFields:object',
    'librefinance:item#create' => 'sku:s!,name:s!,kind:s!,unitPriceMinor:i!,description:s,customFields:object',
    'librefinance:item#update' => 'name:s,kind:s,sku:s,unitPriceMinor:i,description:s,active:b,customFields:object',
    'librefinance:dashboardLayout#save' => 'widgets:array!',
    'librefinance:module#toggle' => 'enabled:b!',
    'librefinance:journal#create' =>
        'debitId:i!,creditId:i!,amountMinor:i!,date:date!,memo:s!,externalKey:s!,categoryId:i,customFields:object,notesMarkdown:s,paymentMethodId:i',
    'librefinance:ai#analyze' => 'from:date!,through:date!,consent:b!,previewHash:s!',
    'librefinance:mcp#handle' => 'jsonrpc:s!,id:sid,method:s!,params:object',
    'librefinance:obligation#create' =>
        'kind:s!,description:s!,amountMinor:i!,issuedOn:date!,dueOn:date!,reference:s,contactId:i',
    'librefinance:obligation#settle' =>
        'amountMinor:i!,paidOn:date!,operationKey:s!,note:s',
    'librefinance:banking#import' => 'bankAccountId:i!,format:s,csv:s,ofx:s',
    'librefinance:banking#match' => 'journalId:i!',
    'librefinance:tax#create' =>
        'code:s!,label:s!,country:s!,region:s,kind:s!,rateBps:i!,inclusive:b!,validFrom:date!,validThrough:date,sourceRef:s!',
    'librefinance:invoice#create' =>
        'number:s!,kind:s!,contactId:i!,issueOn:date!,dueOn:date!,country:s!,region:s,lines:array!,customFields:object',
    'librefinance:paymentWorkflow#invoice' =>
        'bankAccountId:i!,counterAccountId:i!,amountMinor:i!,paidOn:date!,operationKey:s!',
    'librefinance:paymentWorkflow#obligation' =>
        'bankAccountId:i!,counterAccountId:i!,amountMinor:i!,paidOn:date!,operationKey:s!',
    'librefinance:paymentWorkflow#existing' =>
        'bankAccountId:i!,journalId:i!,amountMinor:i!,paidOn:date!,operationKey:s!',
    'librefinance:invoice#allocate' =>
        'journalId:i!,bankAccountId:i!,amountMinor:i!',
    'librefinance_nfse:draft#create' =>
        'operationKey:s!,issuerCnpj:s!,municipalityCode:s!,competence:date!,serviceCode:s!,description:s!,amountMinor:i!,companyId:i',
    'librefinance:import#preview' => 'exportJson:s!',
    'librefinance:import#apply' => 'exportJson:s!,previewHash:s!,confirmed:b!',
    'librefinance:movement#preview' => 'exportJson:s!',
    'librefinance:movement#apply' => 'exportJson:s!,previewHash:s!,confirmed:b!',
    'librefinance:transfer#preview' => 'exportJson:s!',
    'librefinance:transfer#apply' => 'exportJson:s!,previewHash:s!,confirmed:b!',
    'librefinance:inventory#preview' => 'inventoryJson:s!',
];
$noBody = [
    'librefinance:environment#provision',
    'librefinance:governance#archive',
    'librefinance:governance#restore',
    'librefinance:space#archive',
    'librefinance:space#restore',
    'librefinance:dashboardLayout#reset',
    'librefinance:account#delete',
    'librefinance:companyLogo#delete',
    'librefinance:bankAccountLogo#delete',
    'librefinance:paymentMethod#delete',
    'librefinance:savedReport#deletePermanently',
    'librefinance:journal#reverse',
    'librefinance:obligation#cancel',
    'librefinance:invoice#issue',
];
$query = [
    'librefinance:saasWorkspace#index' => 'afterId:i',
    'librefinance:space#unshare' => 'expectedRevision:i',
    'librefinance:saasPolicy#clear' => 'expectedRevision:i!',
    'librefinance:saasPolicy#clearEnvironment' => 'expectedRevision:i!',
    'librefinance:saasPolicy#clearWorkspace' => 'expectedRevision:i!',
    'librefinance:saasPolicy#clearUser' => 'expectedRevision:i!',
    'librefinance:saasExternalInvitation#revoke' => 'expectedRevision:i!',
    'librefinance:saasExternalInvitation#index' => 'afterId:i',
    'librefinance:saasExternalInvitation#inbox' => 'afterId:i',
    'librefinance:saasExternalInvitation#history' => 'afterId:i',
    'librefinance:saasPolicyAudit#instance' => 'beforeId:i',
    'librefinance:saasPolicyAudit#environment' => 'beforeId:i',
    'librefinance:saasPolicyAudit#workspace' => 'beforeId:i',
    'librefinance:saasPolicyAudit#user' => 'beforeId:i',
    'librefinance:governance#groups' => 'search:s,offset:i',
    'librefinance:environment#adminGroups' => 'search:s,offset:i',
    'librefinance:environment#audit' => 'beforeId:i',
    'librefinance:import#coverage' => 'sourceCompanyId:s!,sourceSystem:s',
    'librefinance:import#accounts' => 'sourceCompanyId:s!,sourceSystem:s,afterId:i',
    'librefinance:movement#coverage' => 'sourceCompanyId:s!,sourceSystem:s',
    'librefinance:movement#reconciliation' => 'sourceCompanyId:s!,sourceSystem:s,afterId:i',
    'librefinance:transfer#coverage' => 'sourceCompanyId:s!,sourceSystem:s',
    'librefinance:banking#transfers' => 'afterId:i',
    'librefinance:search#index' => 'query:s!,type:s',
    'librefinance:journal#index' => 'beforeId:i',
    'librefinance:activity#index' => 'cursor:s,direction:s,state:s,accountId:i,search:s,from:date,through:date',
    'librefinance:ai#preview' => 'from:date!,through:date!',
    'librefinance:category#index' => 'afterId:i',
    'librefinance:company#index' => 'afterId:i',
    'librefinance:paymentMethod#index' => 'afterId:i',
    'librefinance:rateHistory#index' => 'afterId:i,sourceCurrency:s,targetCurrency:s,asOf:date,sourceType:s',
    'librefinance:rateHistory#preview' => 'sourceMinor:s!,sourceCurrency:s,targetCurrency:s,asOf:date,sourceType:s',
    'librefinance:contact#index' => 'afterId:i',
    'librefinance:item#index' => 'afterId:i',
    'librefinance:report#profitLoss' => 'from:date!,through:date!',
    'librefinance:report#trialBalance' => 'through:date!,afterId:i,accountClass:s',
    'librefinance:report#cashFlow' => 'from:date!,through:date!,bankAccountId:i',
    'librefinance:savedReport#index' => 'afterId:i',
    'librefinance:obligation#index' => 'afterId:i,status:s,kind:s,dueRange:s,search:s,sortBy:s,sortDirection:s,referenceDate:s',
    'librefinance:obligation#recurrencePreview' => 'count:i!,stepMonths:i!,endOfMonth:s!',
    'librefinance:obligation#settlements' => 'afterId:i',
    'librefinance:banking#index' => 'bankAccountId:i,status:s,afterId:i,from:date,through:date',
    'librefinance:tax#index' => 'afterId:i',
    'librefinance:invoice#index' => 'afterId:i,kind:s,status:s,issueFrom:date,issueThrough:date',
    'librefinance_nfse:draft#index' => 'afterId:i',
];
$descriptions = [
    'librefinance:rateHistory#index' => 'Read-only scoped rate history. For dated selection supply sourceCurrency, targetCurrency and asOf together; optional sourceType. Returns all eligible immutable candidates in ID cursor order, never chooses a rate.',
    'librefinance:rateHistory#preview' => 'Read-only conversion using one explicit workspace rate snapshot and stored precisions; optional sourceCurrency, targetCurrency, asOf and sourceType apply the same eligibility predicate as history. A nonqualifying snapshot returns 404; no posting.',
    'librefinance:environment#index' => 'Authenticated group member may discover eligible environments only, without finance data.',
    'librefinance:environment#enable' => 'Instance admin only. Enable or suspend an individual Nextcloud group without granting ledger rights.',
    'librefinance:environment#adminGroups' => 'Instance admin only. Paginated Nextcloud groups with SaaS readiness, without financial metadata.',
    'librefinance:environment#audit' => 'Instance admin only. Cursor-paginated, append-only SaaS lifecycle audit by stored group binding; no ledger records.',
    'librefinance:environment#recoverGroup' => 'Instance admin only. Restore suspended environment to pending after audited enum recovery reason; no ledger grants.',
    'librefinance:environment#provision' => 'Qualified current group admin or delegate only. Idempotently activate an existing enabled SaaS environment.',
    'librefinance:saasWorkspace#index' => 'Qualified environment manager only. List selected tenant workspace administrative metadata, not financial journals.',
    'librefinance:saasWorkspace#create' => 'Qualified current group manager. Atomically create an independent ledger with an explicit financial owner, idempotency and restrictive quota. Optional legalName, companyCountry and companyTaxId create one linked legal identity in the SAME transaction; no automatic fiscal registration.',
    'librefinance:saasPolicy#index' => 'Instance admin only. List typed policy definitions and effective instance values; never an access grant.',
    'librefinance:saasPolicy#read' => 'Instance admin only. Read source and revision of one typed policy.',
    'librefinance:saasPolicy#write' => 'Instance admin only. Compare-and-set an instance policy using canonical JSON string, typed backend validation and audit.',
    'librefinance:saasPolicy#clear' => 'Instance admin only. Revision-guarded clear restores registry inheritance and records audit.',
    'librefinance:saasPolicy#effective' => 'Qualified environment manager only. Typed effective policies, inherited sources, blocking ancestor and revisions.',
    'librefinance:saasPolicy#readEnvironment' => 'Qualified environment manager only. One effective policy scoped to current tenant, no financial grants.',
    'librefinance:saasPolicy#writeEnvironment' => 'Qualified environment manager. Compare-and-set a child policy only within parent restrictions; audited.',
    'librefinance:saasPolicy#clearEnvironment' => 'Qualified environment manager. Revision-guarded clear inherits parent restrictions.',
    'librefinance:saasPolicy#effectiveWorkspace' => 'Current financial member only. List effective restrictions for this exact independent ledger.',
    'librefinance:saasPolicy#readWorkspace' => 'Current financial member only. Read restrictive workspace policy and authoritative source.',
    'librefinance:saasPolicy#writeWorkspace' => 'Financial workspace manager or owner only. Revision-guarded child restriction; environment administrators have no implied ledger role.',
    'librefinance:saasPolicy#clearWorkspace' => 'Financial workspace manager or owner only. Clear own restriction and inherit environment/instance policy.',
    'librefinance:saasPolicy#effectiveUser' => 'Current financially authorized actor only. Discover effective self-user presentation policies for one confirmed workspace.',
    'librefinance:saasPolicy#readUser' => 'Current actor only. Read source, inherited values and revision of a user presentation policy, no arbitrary target UID.',
    'librefinance:saasPolicy#writeUser' => 'Current internal financial actor only. Revise a self-user presentation restriction without loosening workspace or environment parents.',
    'librefinance:saasPolicy#clearUser' => 'Current internal financial actor only. Compare-and-set self-user policy clearing with append-only audit.',
    'librefinance:saasExternalInvitation#create' => 'Financial owner only. Invite an existing out-of-group Nextcloud account for an expiring, read-only financial grant when restrictive policy permits.',
    'librefinance:saasExternalInvitation#accept' => 'Invitation recipient only. Explicit revision-guarded consent before access; no tenant group membership.',
    'librefinance:saasExternalInvitation#revoke' => 'Financial owner only. Immediate revision-guarded revocation; no deletion of audit history.',
    'librefinance:saasExternalInvitation#index' => 'Financial owner only. Paginated external-grant status and expiry for one ledger.',
    'librefinance:saasExternalInvitation#inbox' => 'Authenticated invitee only. Pending and accepted invitations to that exact Nextcloud UID.',
    'librefinance:saasExternalInvitation#history' => 'Financial owner only. Cursor-paginated immutable external-grant audit.',
    'librefinance:saasPolicyAudit#instance' => 'Instance administrator only. Cursor-paginated history for global policies; no financial ledger access.',
    'librefinance:saasPolicyAudit#environment' => 'Current qualified environment manager only. Immutable history of one active SaaS environment.',
    'librefinance:saasPolicyAudit#workspace' => 'Financial owner or manager only. Cursor-paginated policy history of an independent ledger.',
    'librefinance:saasPolicyAudit#user' => 'Current financial actor only. History of its own user policy in one permitted workspace.',
    'librefinance:governance#show' => 'Instance-admin only. Read current global provisioning mode.',
    'librefinance:governance#configure' => 'Instance-admin only. Enable or disable managed provisioning.',
    'librefinance:governance#contexts' => 'Instance-admin only. List scoped finance contexts and binding status.',
    'librefinance:governance#provision' => 'Instance-admin only. Atomically provision a new finance context governed by an existing Nextcloud group.',
    'librefinance:governance#bind' => 'Instance-admin only. Bind a pre-existing finance context to a Nextcloud group; no ledger mutation.',
    'librefinance:governance#capabilities' => 'Authenticated user. Read whether context creation is permitted.',
    'librefinance:mcp#handle' => 'MCP read-only JSON-RPC; clients must use an authenticated Nextcloud app password and the negotiated 2025-06-18 protocol.',
    'librefinance:ai#analyze' => 'TaskProcessing: only aggregate totals, read-only, requires human consent; provider may be external.',
    'librefinance:invoice#issue' => 'Internal commercial issue status only. This is NOT a fiscal authorization.',
    'librefinance:space#rename' => 'Only the owner may rename an active finance space.',
    'librefinance:space#archive' => 'Owner-only reversible archival. Preserves all accounting records and suspends other access.',
    'librefinance:space#restore' => 'Owner-only restore of an archived finance space and its historical records.',
    'librefinance:space#archived' => 'Only workspaces owned by the authenticated caller that are archived.',
    'librefinance:paymentWorkflow#invoice' => 'One atomic, idempotent cash journal and allocation to a confirmed invoice, with explicit cash-basis revenue/expense classification. No remote bank transfer.',
    'librefinance:paymentWorkflow#obligation' => 'One atomic, idempotent cash journal and settlement to a standalone obligation. The posted journal is claimed and cannot be silently reversed.',
    'librefinance:paymentWorkflow#existing' => 'Claims and links a pre-existing posted bank journal to a standalone obligation, without creating a second cash journal. Effective dates must match.',
    'librefinance_nfse:draft#create' => 'Staging only. Does not issue DPS, NFS-e, DANFSe or authorized XML.',
    'librefinance:import#apply' => 'Explicitly confirmed normalized JSON only; no direct source API access or automatic ledger posting.',
    'librefinance:movement#apply' => 'Explicitly approved historical bank movement import. Creates pending bank statements only, never ledger postings or transfers.',
    'librefinance:transfer#apply' => 'Stages linked source bank transfers without duplicating statement lines or ledger postings. Source transfer legs remain unmatched.',
    'librefinance:inventory#preview' => 'Read-only coverage audit against an externally supplied untrusted source inventory. Count parity is never proof of financial or fiscal functional parity.',
    'librefinance:banking#import' => 'CSV import only; does not initiate bank API synchronization.',
];

/** @return array{properties:array<string,mixed>,required:list<string>} */
$parse = static function (string $schema): array {
    $properties = [];
    $required = [];
    foreach (array_filter(explode(',', $schema)) as $part) {
        [$name, $kind] = explode(':', trim($part), 2);
        $mandatory = str_ends_with($kind, '!');
        $kind = rtrim($kind, '!');
        $value = match ($kind) {
            's' => ['type' => 'string'],
            'i' => ['type' => 'integer'],
            'sid' => ['type' => ['string', 'integer']],
            'b' => ['type' => 'boolean'],
            'date' => ['type' => 'string', 'format' => 'date'],
            'array' => ['type' => 'array', 'items' => ['type' => 'object']],
            'object' => ['type' => 'object', 'additionalProperties' => true],
            default => throw new RuntimeException('Unknown schema type ' . $kind),
        };
        if (in_array($name, ['spaceId', 'accountId', 'journalId', 'amountMinor',
            'beforeId', 'afterId', 'taskId'], true)) {
            $value['minimum'] = $name === 'amountMinor' ? 0 : 1;
        }
        if ($name === 'previewHash') {
            $value['pattern'] = '^[0-9a-f]{64}$';
            $value['minLength'] = 64;
            $value['maxLength'] = 64;
        }
        if ($name === 'consent' || $name === 'confirmed') {
            $value['const'] = true;
        }
        $properties[$name] = $value;
        if ($mandatory) {
            $required[] = $name;
        }
    }
    return compact('properties', 'required');
};
$spec = [
    'openapi' => '3.1.0',
    'info' => [
        'title' => 'LibreFinance — native Nextcloud application APIs',
        'version' => $appVersion,
        'description' => 'Original AGPL application contracts. Structural response schemas are provisional and require full OCS/OpenAPI extractor adoption. '
            . 'Every route enforces Nextcloud identity and per-space roles. MCP is read-only; AI is consented.',
    ],
    'servers' => [['url' => '/index.php/apps']],
    'security' => [['NextcloudAppPassword' => []]],
    'tags' => [],
    'paths' => [],
    'components' => [
        'securitySchemes' => [
            'NextcloudAppPassword' => [
                'type' => 'http', 'scheme' => 'basic',
                'description' => 'Nextcloud app password or authenticated session with Nextcloud CSRF protection.',
            ],
        ],
        'schemas' => [
            'Error' => ['type' => 'object',
                'properties' => ['error' => ['type' => 'string']],
                'required' => ['error']],
            'JsonObject' => ['type' => 'object', 'additionalProperties' => true],
            'ProfitLoss' => [
                'type' => 'object',
                'properties' => [
                    'from' => ['type' => 'string', 'format' => 'date'],
                    'through' => ['type' => 'string', 'format' => 'date'],
                    'revenueMinor' => ['type' => 'integer'],
                    'expenseMinor' => ['type' => 'integer'],
                    'resultMinor' => ['type' => 'integer'],
                ],
                'required' => ['from', 'through', 'revenueMinor',
                    'expenseMinor', 'resultMinor'],
            ],
            'CashFlow' => [
                'type' => 'object',
                'properties' => [
                    'from' => ['type' => 'string', 'format' => 'date'],
                    'through' => ['type' => 'string', 'format' => 'date'],
                    'currency' => ['type' => 'string', 'pattern' => '^[A-Z]{3}$'],
                    'bankAccountId' => ['type' => 'integer', 'minimum' => 0],
                    'openingMinor' => ['type' => 'integer'],
                    'netMinor' => ['type' => 'integer'],
                    'closingMinor' => ['type' => 'integer'],
                    'months' => ['type' => 'array', 'items' => [
                        'type' => 'object',
                        'properties' => [
                            'month' => ['type' => 'string', 'pattern' => '^[0-9]{4}-[0-9]{2}$'],
                            'netMinor' => ['type' => 'integer'],
                        ],
                        'required' => ['month', 'netMinor'],
                    ]],
                    'basis' => ['type' => 'string', 'const' => 'realized_bank_postings'],
                    'isForecast' => ['type' => 'boolean', 'const' => false],
                ],
                'required' => ['from', 'through', 'currency', 'bankAccountId', 'openingMinor',
                    'months', 'netMinor', 'closingMinor', 'basis', 'isForecast'],
            ],
            'AiPreview' => [
                'type' => 'object',
                'properties' => [
                    'periodStart' => ['type' => 'string', 'format' => 'date'],
                    'periodEnd' => ['type' => 'string', 'format' => 'date'],
                    'currency' => ['type' => 'string', 'pattern' => '^[A-Z]{3}$'],
                    'revenueMinor' => ['type' => 'integer'],
                    'expenseMinor' => ['type' => 'integer'],
                    'resultMinor' => ['type' => 'integer'],
                    'previewHash' => ['type' => 'string',
                        'pattern' => '^[0-9a-f]{64}$', 'minLength' => 64, 'maxLength' => 64],
                ],
                'required' => ['periodStart', 'periodEnd', 'currency',
                    'revenueMinor', 'expenseMinor', 'resultMinor', 'previewHash'],
            ],
            'AiTaskQueued' => [
                'type' => 'object',
                'properties' => [
                    'taskId' => ['type' => 'integer', 'minimum' => 1],
                    'status' => ['type' => 'string', 'const' => 'scheduled'],
                    'scope' => ['type' => 'string', 'const' => 'aggregated_totals_only'],
                ],
                'required' => ['taskId', 'status', 'scope'],
            ],
            'JournalPosted' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'minimum' => 1],
                    'replayed' => ['type' => 'boolean'],
                ],
                'required' => ['id', 'replayed'],
            ],
            'McpJsonRpc' => [
                'type' => 'object',
                'properties' => [
                    'jsonrpc' => ['type' => 'string', 'const' => '2.0'],
                    'id' => ['type' => ['string', 'integer', 'null']],
                    'result' => ['type' => ['object', 'null']],
                    'error' => ['type' => ['object', 'null']],
                ],
                'required' => ['jsonrpc', 'id'],
            ],
        ],
    ],
];
$responseSchemas = [
    'librefinance:journal#create' => 'JournalPosted',
    'librefinance:ai#preview' => 'AiPreview',
    'librefinance:ai#analyze' => 'AiTaskQueued',
    'librefinance:mcp#handle' => 'McpJsonRpc',
    'librefinance:report#profitLoss' => 'ProfitLoss',
    'librefinance:report#cashFlow' => 'CashFlow',
];
/**
 * Read the source of truth from controller attributes without bootstrapping a
 * Nextcloud server during standalone OpenAPI generation. This is restricted
 * to explicit FrontpageRoute declarations; other attribute syntaxes must be
 * implemented here and tested before they can be used to add API operations.
 *
 * @return list<array{name:string,url:string,verb:string}>
 */
function routesFromControllers(string $app, string $project, string $appRoot): array {
    $dir = $appRoot . '/lib/Controller';
    if (!is_dir($dir)) {
        throw new RuntimeException('Missing source app checkout ' . $app);
    }
    $files = glob($dir . '/*Controller.php');
    if ($files === false || $files === []) {
        throw new RuntimeException('Missing Nextcloud controllers for ' . $app);
    }
    sort($files, SORT_STRING);
    $routes = [];
    foreach ($files as $file) {
        $source = file_get_contents($file);
        if ($source === false) {
            throw new RuntimeException('Unreadable controller ' . $file);
        }
        $controller = lcfirst(substr(basename($file), 0, -strlen('Controller.php')));
        preg_match_all(
            "/#\\[FrontpageRoute\\(verb: *'([A-Z]+)', *url: *'([^']+)'\\)\\]\\s*public\\s+function\\s+([A-Za-z_][A-Za-z0-9_]*)\\s*\\(/",
            $source,
            $matches,
            PREG_SET_ORDER,
        );
        if (count($matches) !== substr_count($source, '#[FrontpageRoute(')) {
            throw new RuntimeException('Unrecognized controller route attribute in ' . $file);
        }
        foreach ($matches as $match) {
            $routes[] = [
                'name' => $controller . '#' . $match[3],
                'url' => $match[2],
                'verb' => $match[1],
            ];
        }
    }
    return $routes;
}

$operationCount = 0;
foreach ($apps as $app) {
    $spec['tags'][] = ['name' => $app, 'description' => 'Nextcloud optional app / native module'];
    foreach (routesFromControllers($app, $project, $appRoots[$app]) as $route) {
        $url = $route['url'] ?? '';
        if (!str_starts_with($url, '/api/')) {
            continue; // HTML app pages are not JSON APIs.
        }
        $name = $route['name'];
        $key = $app . ':' . $name;
        $method = strtolower($route['verb']);
        $path = '/' . $app . $url;
        $parameters = [];
        preg_match_all('/\\{([a-zA-Z][a-zA-Z0-9]*)\\}/', $url, $matches);
        foreach ($matches[1] as $variable) {
            $isText = in_array($variable, ['uid', 'gid', 'moduleId', 'sourceId', 'environmentId'], true);
            $parameters[] = [
                'name' => $variable, 'in' => 'path', 'required' => true,
                'schema' => $isText ? ['type' => 'string']
                    : ['type' => 'integer', 'minimum' => 1],
            ];
        }
        foreach ($parse($query[$key] ?? '')['properties'] as $variable => $type) {
            $parameters[] = [
                'name' => $variable, 'in' => 'query',
                'required' => in_array($variable, $parse($query[$key])['required'], true),
                'schema' => $type,
            ];
        }
        $body = $writeForms[$key] ?? null;
        if (in_array($method, ['post', 'put', 'patch'], true)
            && $body === null && !in_array($key, $noBody, true)) {
            throw new RuntimeException('Undocumented mutating route ' . $key);
        }
        $resultCode = match (true) {
            $key === 'librefinance:ai#analyze' => '202',
            $key === 'librefinance:mcp#handle' => '200',
            in_array($key, [
                'librefinance:import#preview',
                'librefinance:import#apply',
                'librefinance:movement#preview',
                'librefinance:movement#apply',
                'librefinance:transfer#preview',
                'librefinance:transfer#apply',
                'librefinance:inventory#preview',
                'librefinance:banking#match',
                'librefinance:module#toggle',
            ], true) => '200',
            $method === 'post' && $body !== null => '201',
            default => '200',
        };
        $operation = [
            'operationId' => preg_replace('/[^A-Za-z0-9_]/', '_', $app . '_' . $name),
            'tags' => [$app],
            'summary' => str_replace(['#', '-'], ' ', $name),
            'description' => $descriptions[$key] ??
                'Nextcloud-authenticated and company-scoped; permissions enforced server-side.',
            'parameters' => $parameters,
            'security' => [['NextcloudAppPassword' => []]],
            'responses' => [
                $resultCode => ['description' => 'Successful operation (response schema remains provisional)',
                    'content' => ['application/json' =>
                        ['schema' => ['$ref' => '#/components/schemas/' .
                            ($responseSchemas[$key] ?? 'JsonObject')]]]],
                '403' => ['description' => 'Authenticated user cannot perform this operation'],
                '404' => ['description' => 'Company not visible or resource unavailable'],
                '409' => ['description' => 'State or idempotency conflict'],
                '422' => ['description' => 'Invalid business inputs'],
            ],
        ];
        if ($body !== null) {
            $fields = $parse($body);
            $operation['requestBody'] = [
                'required' => true,
                'content' => ['application/json' => [
                    'schema' => ['type' => 'object',
                        'properties' => $fields['properties'],
                        'required' => $fields['required'],
                        'additionalProperties' => false],
                ]],
            ];
        }
        if (in_array($key, ['librefinance:space#share', 'librefinance:space#unshare'], true)) {
            $operation['responses']['428'] = [
                'description' => 'A SaaS internal grant mutation requires the current grant revision',
            ];
        }
        if ($key === 'librefinance:mcp#handle') {
            $operation['parameters'][] = [
                'name' => 'MCP-Protocol-Version', 'in' => 'header',
                'required' => true, 'schema' => [
                    'type' => 'string', 'const' => '2025-06-18'],
            ];
            $operation['parameters'][] = [
                'name' => 'OCS-APIREQUEST', 'in' => 'header',
                'required' => true, 'schema' => ['type' => 'string', 'const' => 'true'],
            ];
            $operation['x-read-only-tools'] = true;
        }
        if ($key === 'librefinance:ai#analyze') {
            $operation['responses']['503'] = [
                'description' => 'No Nextcloud TaskProcessing provider configured',
            ];
        }
        if (isset($spec['paths'][$path][$method])) {
            throw new RuntimeException('Duplicate method + route ' . $method . $path);
        }
        $spec['paths'][$path][$method] = $operation;
        ++$operationCount;
    }
}
ksort($spec['paths']);
foreach ($spec['paths'] as &$entry) {
    ksort($entry);
}
unset($entry);
$json = json_encode($spec, JSON_THROW_ON_ERROR
    | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
$output = $core . '/openapi.json';
if ($includeExtensions) {
    // Verify optional contracts separately, without shipping their paths in core.
    if ($operationCount < 2 || !array_filter(array_keys($spec['paths']),
        static fn(string $path): bool => str_starts_with($path, '/librefinance_nfse/api/'))) {
        throw new RuntimeException('No optional NFS-e API routes were extracted');
    }
    echo 'Validated ' . $operationCount . ' combined core and extension route contracts' . PHP_EOL;
} elseif (in_array('--stdout', $argv, true)) {
    echo $json;
} elseif (in_array('--check', $argv, true)) {
    if (!is_file($output) || file_get_contents($output) !== $json) {
        fwrite(STDERR, "OpenAPI route/contract drift: regenerate openapi.json\n");
        exit(1);
    }
    echo 'OpenAPI matches ' . $operationCount . ' actual Nextcloud core API routes' . PHP_EOL;
} else {
    if (file_put_contents($output, $json) === false) {
        throw new RuntimeException('Cannot write application-root openapi.json');
    }
    echo 'Wrote app-root OpenAPI schema for ' . $operationCount . ' API operations' . PHP_EOL;
}

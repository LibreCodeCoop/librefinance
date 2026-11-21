<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/**
 * Explicit, reviewable OpenAPI documentation compiled from every actual
 * Nextcloud route declaration. A new write route without a declared request
 * contract breaks this generator. --check detects stale committed JSON.
 *
 * Limit: response object schemas are structural at this development stage.
 * Use the real HTTP/Behat tests for response and denial contract evidence.
 */
$project = dirname(__DIR__, 3);
$core = $project . '/librefinance';
$apps = [
    'librefinance', 'librefinance_reports', 'librefinance_obligations',
    'librefinance_banking', 'librefinance_invoicing',
    'librefinance_nfse', 'librefinance_import',
];

/**
 * Format: field:type! (required), ? optional; types s/i/b/date/array/object.
 * Each spec describes the controller JSON contract, NOT Akaunting's database.
 */
$writeForms = [
    'librefinance:space#create' => 'name:s!,kind:s!,currency:s!',
    'librefinance:space#share' => 'uid:s!,role:s!',
    'librefinance:space#shareGroup' => 'gid:s!,role:s!',
    'librefinance:account#create' => 'code:s!,name:s!,class:s!,isBank:b',
    'librefinance:companyLogo#upload' => 'imageBase64:s!',
    'librefinance:paymentMethod#create' => 'name:s!,kind:s!,bankAccountId:i',
    'librefinance:paymentMethod#update' => 'name:s!,bankAccountId:i,active:b',
    'librefinance:account#update' => 'name:s!,active:b',
    'librefinance:category#create' => 'code:s!,name:s!,kind:s!,parentId:i,accountId:i,color:s',
    'librefinance:category#update' => 'name:s!,color:s!,active:b',
    'librefinance:company#create' => 'legalName:s!,country:s!,taxId:s,tradeName:s,municipalRegistration:s,stateRegistration:s,customFields:object',
    'librefinance:company#update' => 'legalName:s!,country:s!,taxId:s,tradeName:s,municipalRegistration:s,stateRegistration:s,active:b,customFields:object',
    'librefinance:customField#create' => 'key:s!,label:s!,type:s!,required:b,options:array,visible:b,help:s,sortOrder:i,columnSpan:i',
    'librefinance:customField#createForEntity' => 'key:s!,label:s!,type:s!,required:b,options:array,visible:b,help:s,sortOrder:i,columnSpan:i',
    'librefinance:customField#updateForEntity' => 'label:s!,required:b,active:b,visible:b,help:s,sortOrder:i,columnSpan:i',
    'librefinance:customField#update' => 'label:s!,required:b,active:b,visible:b,help:s,sortOrder:i,columnSpan:i',
    'librefinance_reports:savedReport#create' => 'name:s!,kind:s!,from:date,through:date!,columns:array,accountClass:s',
    'librefinance_reports:savedReport#update' => 'name:s!,kind:s!,from:date,through:date!,columns:array,accountClass:s',
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
    'librefinance_obligations:obligation#create' =>
        'kind:s!,description:s!,amountMinor:i!,issuedOn:date!,dueOn:date!,reference:s,contactId:i',
    'librefinance_obligations:obligation#settle' =>
        'amountMinor:i!,paidOn:date!,operationKey:s!,note:s',
    'librefinance_banking:banking#import' => 'bankAccountId:i!,csv:s!',
    'librefinance_banking:banking#match' => 'journalId:i!',
    'librefinance_invoicing:tax#create' =>
        'code:s!,label:s!,country:s!,region:s,kind:s!,rateBps:i!,inclusive:b!,validFrom:date!,validThrough:date,sourceRef:s!',
    'librefinance_invoicing:invoice#create' =>
        'number:s!,kind:s!,contactId:i!,issueOn:date!,dueOn:date!,country:s,region:s,lines:array!',
    'librefinance_invoicing:invoice#allocate' =>
        'journalId:i!,bankAccountId:i!,amountMinor:i!',
    'librefinance_nfse:draft#create' =>
        'operationKey:s!,issuerCnpj:s!,municipalityCode:s!,competence:date!,serviceCode:s!,description:s!,amountMinor:i!,companyId:i',
    'librefinance_import:import#preview' => 'exportJson:s!',
    'librefinance_import:import#apply' =>
        'exportJson:s!,previewHash:s!,confirmed:b!',
    'librefinance_import:movement#preview' => 'exportJson:s!',
    'librefinance_import:movement#apply' =>
        'exportJson:s!,previewHash:s!,confirmed:b!',
    'librefinance_import:transfer#preview' => 'exportJson:s!',
    'librefinance_import:transfer#apply' =>
        'exportJson:s!,previewHash:s!,confirmed:b!',
    'librefinance_import:inventory#preview' => 'inventoryJson:s!',
];
$noBody = [
    'librefinance:dashboardLayout#reset',
    'librefinance:account#delete',
    'librefinance:companyLogo#delete',
    'librefinance:paymentMethod#delete',
    'librefinance_reports:savedReport#deletePermanently',
    'librefinance:journal#reverse',
    'librefinance_obligations:obligation#cancel',
    'librefinance_invoicing:invoice#issue',
];
$query = [
    'librefinance_import:import#coverage' => 'sourceCompanyId:s!,sourceSystem:s',
    'librefinance_import:import#accounts' => 'sourceCompanyId:s!,sourceSystem:s,afterId:i',
    'librefinance_import:movement#coverage' => 'sourceCompanyId:s!,sourceSystem:s',
    'librefinance_import:movement#reconciliation' => 'sourceCompanyId:s!,sourceSystem:s,afterId:i',
    'librefinance_import:transfer#coverage' => 'sourceCompanyId:s!,sourceSystem:s',
    'librefinance_banking:banking#transfers' => 'afterId:i',
    'librefinance:search#index' => 'query:s!,type:s',
    'librefinance:journal#index' => 'beforeId:i',
    'librefinance:ai#preview' => 'from:date!,through:date!',
    'librefinance:category#index' => 'afterId:i',
    'librefinance:company#index' => 'afterId:i',
    'librefinance:paymentMethod#index' => 'afterId:i',
    'librefinance:contact#index' => 'afterId:i',
    'librefinance:item#index' => 'afterId:i',
    'librefinance_reports:report#profitLoss' => 'from:date!,through:date!',
    'librefinance_reports:report#trialBalance' => 'through:date!,afterId:i,accountClass:s',
    'librefinance_reports:report#cashFlow' => 'from:date!,through:date!',
    'librefinance_reports:savedReport#index' => 'afterId:i',
    'librefinance_obligations:obligation#index' => 'afterId:i',
    'librefinance_obligations:obligation#settlements' => 'afterId:i',
    'librefinance_banking:banking#index' => 'afterId:i',
    'librefinance_invoicing:tax#index' => 'afterId:i',
    'librefinance_invoicing:invoice#index' => 'afterId:i',
    'librefinance_nfse:draft#index' => 'afterId:i',
];
$descriptions = [
    'librefinance:mcp#handle' => 'MCP read-only JSON-RPC; clients must use an authenticated Nextcloud app password and the negotiated 2025-06-18 protocol.',
    'librefinance:ai#analyze' => 'TaskProcessing: only aggregate totals, read-only, requires human consent; provider may be external.',
    'librefinance_invoicing:invoice#issue' => 'Internal commercial issue status only. This is NOT a fiscal authorization.',
    'librefinance_nfse:draft#create' => 'Staging only. Does not issue DPS, NFS-e, DANFSe or authorized XML.',
    'librefinance_import:import#apply' => 'Approved normalized JSON only; no Akaunting password, screen scraping or direct data extraction.',
    'librefinance_import:movement#apply' => 'Explicitly approved historical bank movement import. Creates pending bank statements only, never ledger postings or transfers.',
    'librefinance_import:transfer#apply' => 'Stages linked source bank transfers without duplicating statement lines or ledger postings. Source transfer legs remain unmatched.',
    'librefinance_import:inventory#preview' => 'Read-only coverage audit against an externally supplied untrusted source inventory. Count parity is never proof of financial or fiscal functional parity.',
    'librefinance_banking:banking#import' => 'CSV import only; does not initiate bank API synchronization.',
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
        'version' => '0.16.0-dev',
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
                'required' => ['from', 'through', 'currency', 'openingMinor',
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
    'librefinance_reports:report#profitLoss' => 'ProfitLoss',
    'librefinance_reports:report#cashFlow' => 'CashFlow',
];
$operationCount = 0;
foreach ($apps as $app) {
    $file = $project . '/' . $app . '/appinfo/routes.php';
    if (!is_file($file)) {
        throw new RuntimeException('Missing source app checkout ' . $app);
    }
    $definition = require $file;
    $spec['tags'][] = ['name' => $app, 'description' => 'Nextcloud optional app / native module'];
    foreach ($definition['routes'] ?? [] as $route) {
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
            $isText = in_array($variable, ['uid', 'gid', 'moduleId'], true);
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
                'librefinance_import:import#preview',
                'librefinance_import:import#apply',
                'librefinance_banking:banking#match',
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
$output = $core . '/docs/openapi.json';
if (in_array('--stdout', $argv, true)) {
    // Read-only Nextcloud test containers export schema for a local write by the caller.
    echo $json;
} elseif (in_array('--check', $argv, true)) {
    if (!is_file($output) || file_get_contents($output) !== $json) {
        fwrite(STDERR, "OpenAPI route/contract drift: regenerate docs/openapi.json\\n");
        exit(1);
    }
    echo 'OpenAPI matches ' . $operationCount . ' actual Nextcloud API routes' . PHP_EOL;
} else {
    file_put_contents($output, $json);
    echo 'Wrote OpenAPI schema for ' . $operationCount . ' actual API operations' . PHP_EOL;
}

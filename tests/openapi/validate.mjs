// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import Parser from '@apidevtools/swagger-parser'

const spec = await Parser.validate('./docs/openapi.json')
const operations = []
for (const [path, methods] of Object.entries(spec.paths)) {
  for (const [method, operation] of Object.entries(methods)) {
    if (!['get', 'post', 'put', 'patch', 'delete'].includes(method)) continue
    if (!operation.operationId || !operation.responses || !operation.security?.length) {
      throw new Error('OpenAPI operation lacks required security/response data: ' + method + ' ' + path)
    }
    const pathNames = [...path.matchAll(/\{([^}]+)\}/g)].map(match => match[1])
    for (const name of pathNames) {
      if (!operation.parameters?.some(param => param.name === name && param.in === 'path'
        && param.required === true)) {
        throw new Error('OpenAPI path variable is undocumented: ' + method + ' ' + path)
      }
    }
    operations.push(operation.operationId)
  }
}
if (new Set(operations).size !== operations.length || operations.length < 49) {
  throw new Error('Duplicate or missing OpenAPI operation IDs')
}
const mcp = spec.paths['/librefinance/api/mcp']?.post
if (!mcp?.['x-read-only-tools']
  || !mcp.parameters.some(parameter => parameter.name === 'MCP-Protocol-Version')) {
  throw new Error('MCP read-only contract or protocol header missing')
}
const ai = spec.paths['/librefinance/api/spaces/{spaceId}/ai/analyze']?.post
if (!ai?.requestBody?.content?.['application/json']?.schema?.properties?.consent?.const) {
  throw new Error('Explicit consent is missing from Nextcloud AI API schema')
}
const schemaChecks = [
  ['/librefinance/api/spaces/{spaceId}/journals', 'post', 'JournalPosted'],
  ['/librefinance/api/spaces/{spaceId}/ai/preview', 'get', 'AiPreview'],
  ['/librefinance/api/spaces/{spaceId}/ai/analyze', 'post', 'AiTaskQueued'],
  ['/librefinance/api/mcp', 'post', 'McpJsonRpc'],
  ['/librefinance_reports/api/spaces/{spaceId}/profit-loss', 'get', 'ProfitLoss'],
  ['/librefinance_reports/api/spaces/{spaceId}/cash-flow', 'get', 'CashFlow'],
]
for (const [path, method, name] of schemaChecks) {
  const op = spec.paths[path]?.[method]
  // The OpenAPI Parser dereferences $ref during validate(); compare the
  // expanded structural contract rather than expecting a retained $ref.
  const expected = spec.components.schemas[name]
  const response = Object.values(op?.responses || {}).find(item => {
    const actual = item?.content?.['application/json']?.schema
    return actual && expected?.required?.length
      && expected.required.every(field => actual.required?.includes(field)
        && actual.properties?.[field] !== undefined)
  })
  if (!response) {
    throw new Error('Missing typed output contract for ' + method + ' ' + path)
  }
}
const previewHash = ai?.requestBody?.content?.['application/json']?.schema
  ?.properties?.previewHash
if (previewHash?.minLength !== 64 || previewHash?.maxLength !== 64
    || !ai.requestBody.content['application/json'].schema.required.includes('previewHash')) {
  throw new Error('AI analysis requires a cryptographically bound 64-hex preview identifier')
}
const mcpIdTypes = mcp?.requestBody?.content?.['application/json']?.schema
  ?.properties?.id?.type
if (!Array.isArray(mcpIdTypes) || !mcpIdTypes.includes('string')
    || !mcpIdTypes.includes('integer')) {
  throw new Error('MCP protocol requires both JSON-RPC string and integer IDs')
}
console.log('OpenAPI 3.1 validated:', operations.length, 'operations across',
  Object.keys(spec.paths).length, 'paths,', schemaChecks.length, 'typed output contracts')

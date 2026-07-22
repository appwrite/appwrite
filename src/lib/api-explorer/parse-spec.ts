import {
  compareServices,
  getServiceLabel,
  isConsoleOnlyDatabaseApiService,
} from './services'
import type {
  ApiExplorerMethod,
  ApiExplorerService,
  ApiSpecPlatform,
  AppwriteOpenApiExtension,
  OpenApiOperation,
  OpenApiParameter,
  OpenApiRequestBody,
  OpenApiSchema,
  OpenApiSpec,
  ParsedApiSpec,
} from './types'

/** Matches website references specs.ts path iteration order. */
const REFERENCE_HTTP_METHODS = [
  'get',
  'post',
  'put',
  'patch',
  'delete',
] as const

type ParsedOperationContext = {
  path: string
  httpMethod: string
  operation: OpenApiOperation
  service: string
}

function formatAuthLabel(
  xAppwrite?: AppwriteOpenApiExtension,
  security?: OpenApiOperation['security'],
): string {
  const auth = xAppwrite?.auth
  if (auth && Object.keys(auth).length > 0) {
    return Object.keys(auth).join(', ')
  }
  if (security?.length) {
    const keys = new Set<string>()
    for (const entry of security) {
      for (const key of Object.keys(entry)) keys.add(key)
    }
    if (keys.size > 0) return Array.from(keys).join(', ')
  }
  return 'Project'
}

function isPlatformSupported(
  xAppwrite: AppwriteOpenApiExtension | undefined,
  platform: ApiSpecPlatform,
): boolean {
  const platforms = xAppwrite?.platforms
  if (!platforms?.length) return true
  return platforms.includes(platform)
}

function resolveSchema(
  schema: OpenApiSchema | undefined,
  components?: OpenApiSpec['components'],
): OpenApiSchema | undefined {
  if (!schema?.$ref || !components?.schemas) return schema
  const name = schema.$ref.replace('#/components/schemas/', '')
  return components.schemas[name] ?? schema
}

function filterRequestBodyProperties(
  requestBody: OpenApiRequestBody | undefined,
  allowedParameters: string[],
  components?: OpenApiSpec['components'],
): OpenApiRequestBody | undefined {
  if (!requestBody?.content) return requestBody

  const jsonContent = requestBody.content['application/json']
  if (!jsonContent?.schema) return requestBody

  const schema = resolveSchema(jsonContent.schema, components)
  if (!schema?.properties) return requestBody

  const filteredProperties: Record<string, OpenApiSchema> = {}
  for (const [propertyName, propertySchema] of Object.entries(schema.properties)) {
    if (allowedParameters.includes(propertyName)) {
      filteredProperties[propertyName] = propertySchema
    }
  }

  return {
    ...requestBody,
    content: {
      ...requestBody.content,
      'application/json': {
        ...jsonContent,
        schema: {
          ...schema,
          properties: filteredProperties,
          required: schema.required?.filter((prop) =>
            allowedParameters.includes(prop),
          ),
        },
      },
    },
  }
}

function hasAdditionalMethods(
  operation: OpenApiOperation | undefined,
  service: string,
): operation is OpenApiOperation & { 'x-appwrite': AppwriteOpenApiExtension } {
  const xAppwrite = operation?.['x-appwrite']
  return Boolean(
    operation?.tags?.includes(service) &&
      xAppwrite &&
      Array.isArray(xAppwrite.methods) &&
      xAppwrite.methods.length > 0,
  )
}

function* processAdditionalMethods(
  operation: OpenApiOperation,
  httpMethod: string,
  path: string,
  service: string,
  components?: OpenApiSpec['components'],
): Generator<ParsedOperationContext> {
  const xAppwrite = operation['x-appwrite'] as AppwriteOpenApiExtension
  for (const additionalMethod of xAppwrite.methods ?? []) {
    if (additionalMethod.public === false) continue

    const responseCode = additionalMethod.responses?.[0]?.code
    const responseModel = additionalMethod.responses?.[0]?.model

    yield {
      path,
      httpMethod,
      service,
      operation: {
        ...operation,
        summary:
          additionalMethod.desc && additionalMethod.desc.length > 0
            ? additionalMethod.desc
            : operation.summary,
        description: additionalMethod.description ?? operation.description,
        requestBody: filterRequestBodyProperties(
          operation.requestBody,
          additionalMethod.parameters ?? [],
          components,
        ),
        'x-appwrite': {
          ...xAppwrite,
          method: additionalMethod.name,
          demo: additionalMethod.demo ?? xAppwrite.demo,
          public: additionalMethod.public ?? true,
          weight: additionalMethod.weight ?? xAppwrite.weight,
        },
        responses:
          responseCode !== undefined
            ? {
                ...operation.responses,
                [String(responseCode)]:
                  responseCode === 204
                    ? { description: 'No Content' }
                    : {
                        content: {
                          'application/json': {
                            schema: responseModel
                              ? { $ref: responseModel }
                              : undefined,
                          },
                        },
                      },
              }
            : operation.responses,
      },
    }
  }
}

function* iterateOperations(
  spec: OpenApiSpec,
  platform: ApiSpecPlatform,
): Generator<ParsedOperationContext> {
  for (const [path, pathItem] of Object.entries(spec.paths ?? {})) {
    if (!pathItem) continue

    // Phase 1: base operations (skip routes that expose x-appwrite.methods).
    for (const httpMethod of REFERENCE_HTTP_METHODS) {
      const operation = pathItem[httpMethod]
      if (!operation) continue

      const xAppwrite = operation['x-appwrite'] as
        | AppwriteOpenApiExtension
        | undefined
      if (!isPlatformSupported(xAppwrite, platform)) continue
      if (xAppwrite?.public === false) continue

      const service = operation.tags?.[0]
      if (!service) continue
      if (hasAdditionalMethods(operation, service)) continue

      yield { path, httpMethod, operation, service }
    }

    // Phase 2: additional SDK methods from x-appwrite.methods.
    for (const httpMethod of REFERENCE_HTTP_METHODS) {
      const operation = pathItem[httpMethod]
      if (!operation) continue

      const xAppwrite = operation['x-appwrite'] as
        | AppwriteOpenApiExtension
        | undefined
      if (!isPlatformSupported(xAppwrite, platform)) continue
      if (xAppwrite?.public === false) continue

      const service = operation.tags?.[0]
      if (!service) continue
      if (!hasAdditionalMethods(operation, service)) continue

      yield* processAdditionalMethods(
        operation,
        httpMethod,
        path,
        service,
        spec.components,
      )
    }
  }
}

function getPrimaryContentType(
  operation: OpenApiOperation,
): string | undefined {
  const content = operation.requestBody?.content
  if (!content) return undefined
  if (content['application/json']) return 'application/json'
  if (content['multipart/form-data']) return 'multipart/form-data'
  return Object.keys(content)[0]
}

function normalizeScope(scope: string | string[] | undefined): string | undefined {
  if (scope === undefined) return undefined
  if (Array.isArray(scope)) {
    const values = scope.filter(Boolean).map(String)
    return values.length > 0 ? values.join(', ') : undefined
  }
  return String(scope)
}

function parseOperation(
  context: ParsedOperationContext,
  components?: OpenApiSpec['components'],
): ApiExplorerMethod {
  const { path, httpMethod, operation, service } = context
  const xAppwrite = operation['x-appwrite'] as AppwriteOpenApiExtension
  const operationId =
    operation.operationId ??
    `${httpMethod}${path.replace(/[^a-zA-Z0-9]/g, '')}`
  const contentType = getPrimaryContentType(operation)

  const parameters = (operation.parameters ?? []).map((param) => {
    if (param.schema?.$ref && components?.schemas) {
      return {
        ...param,
        schema: resolveSchema(param.schema, components),
      }
    }
    return param
  })

  let requestBody = operation.requestBody
  if (requestBody?.content) {
    const nextContent: NonNullable<typeof requestBody.content> = {}
    for (const [key, value] of Object.entries(requestBody.content)) {
      nextContent[key] = {
        ...value,
        schema: resolveSchema(value.schema, components),
      }
    }
    requestBody = { ...requestBody, content: nextContent }
  }

  const isDeprecated =
    Boolean(operation.deprecated) || Boolean(xAppwrite?.deprecated)

  return {
    id: xAppwrite.method,
    operationId,
    path,
    httpMethod: httpMethod.toLowerCase(),
    summary: operation.summary ?? xAppwrite.method,
    description: operation.description,
    deprecated: isDeprecated,
    scope: normalizeScope(xAppwrite.scope),
    service,
    resourceGroup: xAppwrite.group || undefined,
    weight: xAppwrite.weight ?? 0,
    tags: operation.tags ?? [service],
    parameters,
    requestBody,
    contentType,
    security: operation.security,
    xAppwrite,
    authLabel: formatAuthLabel(xAppwrite, operation.security),
  }
}

function getOperationOrder(summary: string): number {
  const title = summary.toLowerCase()
  if (title.startsWith('create')) return 1
  if (title.startsWith('read') || title.startsWith('get') || title.startsWith('list')) {
    return 2
  }
  if (title.startsWith('update')) return 3
  if (title.startsWith('upsert')) return 4
  if (title.startsWith('delete')) return 5
  if (title.startsWith('increment')) return 6
  if (title.startsWith('decrement')) return 7
  return 8
}

/** Matches website references specs.ts weight lookup on the source OpenAPI path. */
function getOperationWeightFromSpec(
  spec: OpenApiSpec,
  path: string,
  httpMethod: string,
): number {
  const operation = spec.paths?.[path]?.[httpMethod.toLowerCase()] as
    | OpenApiOperation
    | undefined
  return operation?.['x-appwrite']?.weight ?? 0
}

/** Matches website references specs.ts: global method order before grouping. */
function sortMethodsByWeight(
  methods: ApiExplorerMethod[],
  spec: OpenApiSpec,
): ApiExplorerMethod[] {
  return [...methods].sort((a, b) => {
    const aWeight = getOperationWeightFromSpec(spec, a.path, a.httpMethod)
    const bWeight = getOperationWeightFromSpec(spec, b.path, b.httpMethod)
    return aWeight - bWeight
  })
}

/** Matches website references +page.svelte sortMethods: order within a group. */
function sortMethodsByOperationOrder(
  methods: ApiExplorerMethod[],
): ApiExplorerMethod[] {
  return [...methods].sort((a, b) => {
    const orderA = getOperationOrder(a.summary)
    const orderB = getOperationOrder(b.summary)
    if (orderA !== orderB) return orderA - orderB
    return a.summary.localeCompare(b.summary, undefined, { sensitivity: 'base' })
  })
}

function buildTagDescriptionMap(spec: OpenApiSpec): Map<string, string> {
  const descriptions = new Map<string, string>()
  for (const tag of spec.tags ?? []) {
    const name = tag.name?.trim()
    const description = tag.description?.trim()
    if (!name || !description) continue
    descriptions.set(name.toLowerCase(), description)
  }
  return descriptions
}

function getServiceDescription(
  serviceId: string,
  tagDescriptions: Map<string, string>,
): string | undefined {
  return tagDescriptions.get(serviceId.toLowerCase())
}

export function parseOpenApiSpec(
  spec: OpenApiSpec,
  platform: ApiSpecPlatform,
): ParsedApiSpec {
  const serviceMap = new Map<string, ApiExplorerMethod[]>()
  const tagDescriptions = buildTagDescriptionMap(spec)

  for (const context of iterateOperations(spec, platform)) {
    const parsed = parseOperation(context, spec.components)
    const existing = serviceMap.get(parsed.service) ?? []
    existing.push(parsed)
    serviceMap.set(parsed.service, existing)
  }

  const services: ApiExplorerService[] = Array.from(serviceMap.entries())
    .map(([id, methods]) => ({
      id,
      label: getServiceLabel(id),
      description: getServiceDescription(id, tagDescriptions),
      methods: sortMethodsByWeight(methods, spec),
    }))
    .sort((a, b) => compareServices(a.id, b.id))

  return {
    platform,
    version: spec.info?.version,
    services,
  }
}

/**
 * Append console-only native database services (postgresql / mysql / mongo)
 * from a console OpenAPI parse onto a client/server parse.
 * Existing services with the same id are left unchanged.
 */
export function mergeConsoleOnlyDatabaseServices(
  base: ParsedApiSpec,
  consoleParsed: ParsedApiSpec,
): ParsedApiSpec {
  const existingIds = new Set(base.services.map((service) => service.id))
  const extras = consoleParsed.services.filter(
    (service) =>
      isConsoleOnlyDatabaseApiService(service.id) &&
      service.methods.length > 0 &&
      !existingIds.has(service.id),
  )

  if (extras.length === 0) return base

  return {
    ...base,
    services: [...base.services, ...extras].sort((a, b) =>
      compareServices(a.id, b.id),
    ),
  }
}

export function formatResourceGroupLabel(group: string): string {
  if (!group) return ''
  return group.replace(/([a-z])([A-Z])/g, '$1 $2')
}

export function groupMethodsByResource(
  methods: ApiExplorerMethod[],
): Array<{ id: string; label: string; methods: ApiExplorerMethod[] }> {
  const groups = new Map<string, ApiExplorerMethod[]>()
  const groupOrder: string[] = []

  // Methods are weight-sorted in parseOpenApiSpec. Group order follows first
  // encounter while iterating that sorted list (website references pattern).
  for (const method of methods) {
    const key = method.resourceGroup ?? ''
    if (!groups.has(key)) {
      groups.set(key, [])
      groupOrder.push(key)
    }
    groups.get(key)!.push(method)
  }

  return groupOrder.map((id) => ({
    id,
    label: formatResourceGroupLabel(id),
    methods: sortMethodsByOperationOrder(groups.get(id) ?? []),
  }))
}

/** OpenAPI specs use angle-bracket tokens as documentation placeholders, not literal API values. */
export function isOpenApiPlaceholderExample(value: unknown): boolean {
  if (typeof value !== 'string') return false
  return /^<[A-Z][A-Z0-9_]*>$/.test(value.trim())
}

export function getDefaultParamValue(param: OpenApiParameter): string {
  const schema = param.schema
  if (!schema) return ''
  if (schema.default !== undefined && schema.default !== null) {
    return String(schema.default)
  }
  const example = schema.example ?? schema['x-example']
  if (example !== undefined && example !== null) {
    const exampleString = String(example)
    if (isOpenApiPlaceholderExample(exampleString)) return ''
    return exampleString
  }
  if (schema.enum?.length) return String(schema.enum[0])
  if (schema.type === 'boolean') return 'false'
  if (schema.type === 'integer' || schema.type === 'number') return '0'
  return ''
}

export function generateSampleRequestBody(
  schema: OpenApiSchema | undefined,
): string {
  if (!schema) return '{\n  \n}'
  const sample = buildSampleValue(schema)
  return JSON.stringify(sample, null, 2)
}

/** Appwrite creatable resource IDs mention ID.unique() in OpenAPI descriptions. */
const CREATABLE_ID_DESCRIPTION =
  /(?:choose a custom .{0,40}? id|generate a random id|id\.unique\(\))/i

function isCreatableIdSchemaForSample(schema: OpenApiSchema): boolean {
  const description = schema.description?.trim() ?? ''
  if (!description) return false
  return CREATABLE_ID_DESCRIPTION.test(description)
}

export function buildSampleValue(schema: OpenApiSchema): unknown {
  if (schema.example !== undefined) {
    if (!isOpenApiPlaceholderExample(schema.example)) return schema.example
  }
  if (schema['x-example'] !== undefined) {
    if (!isOpenApiPlaceholderExample(schema['x-example'])) return schema['x-example']
  }
  if (schema.default !== undefined) return schema.default
  if (schema.enum?.length) return schema.enum[0]

  switch (schema.type) {
    case 'object': {
      const obj: Record<string, unknown> = {}
      for (const [key, prop] of Object.entries(schema.properties ?? {})) {
        if (isCreatableIdSchemaForSample(prop)) continue
        obj[key] = buildSampleValue(prop)
      }
      return obj
    }
    case 'array':
      return schema.items ? [buildSampleValue(schema.items)] : []
    case 'boolean':
      return false
    case 'integer':
    case 'number':
      return 0
    case 'string': {
      switch (schema.format) {
        case 'email':
          return 'user@example.com'
        case 'url':
          return 'https://example.com'
        case 'phone':
          return '+1234567890'
        case 'ip':
          return '127.0.0.1'
        case 'datetime':
          return new Date().toISOString()
        case 'password':
          return ''
        default:
          return ''
      }
    }
    default:
      return ''
  }
}

export function filterServices(
  services: ApiExplorerService[],
  search: string,
): ApiExplorerService[] {
  const q = search.trim().toLowerCase()
  if (!q) return services

  return services
    .map((service) => {
      const serviceMatches =
        service.label.toLowerCase().includes(q) ||
        service.id.toLowerCase().includes(q)

      const methods = service.methods.filter(
        (method) =>
          serviceMatches ||
          method.summary.toLowerCase().includes(q) ||
          method.id.toLowerCase().includes(q) ||
          method.operationId.toLowerCase().includes(q) ||
          method.path.toLowerCase().includes(q) ||
          (method.resourceGroup?.toLowerCase().includes(q) ?? false) ||
          (method.scope?.toLowerCase().includes(q) ?? false),
      )

      return methods.length > 0 ? { ...service, methods } : null
    })
    .filter((service): service is ApiExplorerService => service !== null)
}

export function findMethodByOperationId(
  services: ApiExplorerService[],
  operationId?: string,
): ApiExplorerMethod | undefined {
  if (!operationId) return undefined
  for (const service of services) {
    const match = service.methods.find(
      (method) =>
        method.operationId === operationId || method.id === operationId,
    )
    if (match) return match
  }
  return undefined
}

export function findServiceForMethod(
  services: ApiExplorerService[],
  method?: ApiExplorerMethod,
): ApiExplorerService | undefined {
  if (!method) return undefined
  return services.find((service) => service.id === method.service)
}

export function findMethodById(
  services: ApiExplorerService[],
  methodId?: string,
): ApiExplorerMethod | undefined {
  if (!methodId) return undefined
  for (const service of services) {
    const match = service.methods.find((method) => method.id === methodId)
    if (match) return match
  }
  return undefined
}

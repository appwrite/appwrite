export type AppwriteAdditionalMethod = {
  name: string
  desc?: string
  auth?: Record<string, string[]>
  parameters?: string[]
  required?: string[]
  responses?: Array<{ code: number; model: string }>
  description?: string
  demo?: string
  public?: boolean
  deprecated?: {
    since?: string
    replaceWith?: string
  }
}

/** Appwrite x-appwrite OpenAPI extension (see appwrite/specs). */
export type AppwriteOpenApiExtension = {
  method: string
  group?: string
  weight?: number
  cookies?: boolean
  type?: string
  demo?: string
  'rate-limit'?: number
  'rate-time'?: number
  'rate-key'?: string | string[]
  scope?: string | string[]
  platforms?: Array<'console' | 'client' | 'server'>
  packaging?: boolean
  public?: boolean
  edit?: string
  auth?: Record<string, string[]>
  methods?: AppwriteAdditionalMethod[]
  deprecated?: {
    since?: string
    replaceWith?: string
  }
}

export type ApiSpecPlatform = 'server' | 'client' | 'console'

/** Project API explorer: server (API key) or client (session) specs only. */
export type ApiExplorerProjectPlatform = 'server' | 'client'

export type ApiExplorerSessionAuthMode = 'guest' | 'user'

export type ApiExplorerRequestAuth = {
  mode: ApiExplorerSessionAuthMode
  jwt?: string
}

export type OpenApiParameter = {
  name: string
  in: 'path' | 'query' | 'header' | 'cookie'
  description?: string
  required?: boolean
  schema?: OpenApiSchema
}

export type OpenApiSchema = {
  type?: string
  description?: string
  example?: unknown
  'x-example'?: unknown
  default?: unknown
  enum?: unknown[]
  properties?: Record<string, OpenApiSchema>
  required?: string[]
  items?: OpenApiSchema
  $ref?: string
  'x-nullable'?: boolean
  'x-enum-name'?: string
  'x-enum-keys'?: string[]
  'x-upload-id'?: string
  format?: string
}

export type OpenApiRequestBody = {
  required?: boolean
  content?: Record<
    string,
    {
      schema?: OpenApiSchema
    }
  >
}

export type OpenApiOperation = {
  summary?: string
  operationId?: string
  tags?: string[]
  description?: string
  deprecated?: boolean
  parameters?: OpenApiParameter[]
  requestBody?: OpenApiRequestBody
  responses?: Record<string, unknown>
  security?: Array<Record<string, string[]>>
  'x-appwrite'?: AppwriteOpenApiExtension
}

export type OpenApiSpec = {
  openapi?: string
  info?: {
    version?: string
    title?: string
    description?: string
  }
  paths?: Record<string, Record<string, OpenApiOperation>>
  components?: {
    schemas?: Record<string, OpenApiSchema>
  }
}

export type ApiExplorerMethod = {
  /** SDK method id from x-appwrite.method */
  id: string
  operationId: string
  path: string
  httpMethod: string
  summary: string
  description?: string
  deprecated: boolean
  scope?: string
  /** Top-level API service (OpenAPI tag), e.g. account, users, storage */
  service: string
  /** Resource group within a service from x-appwrite.group */
  resourceGroup?: string
  weight: number
  tags: string[]
  parameters: OpenApiParameter[]
  requestBody?: OpenApiRequestBody
  contentType?: string
  xAppwrite?: AppwriteOpenApiExtension
  authLabel: string
}

export type ApiExplorerService = {
  id: string
  label: string
  methods: ApiExplorerMethod[]
}

export type ApiExplorerProductGroupDefinition = {
  id: string
  label: string
  services: readonly string[]
}

export type ApiExplorerServiceProductGroup = {
  id: string
  label: string
  services: ApiExplorerService[]
}

export type ParsedApiSpec = {
  platform: ApiSpecPlatform
  version?: string
  services: ApiExplorerService[]
}

export type ApiExplorerConfig = {
  endpoint: string
  projectId: string
  platform?: ApiExplorerProjectPlatform
  apiKey?: string
  /** When set, only these service ids are shown. Defaults to feature-flag-aware project allowlist. */
  allowedServices?: readonly string[]
}

export type ExecuteApiRequestInput = {
  config: ApiExplorerConfig
  method: ApiExplorerMethod
  pathParams: Record<string, string>
  queryParams: Record<string, string>
  body?: string
  requestAuth?: ApiExplorerRequestAuth
}

export type ExecuteApiRequestResult = {
  status: number
  statusText: string
  headers: Record<string, string>
  body: string
  durationMs: number
  ok: boolean
}

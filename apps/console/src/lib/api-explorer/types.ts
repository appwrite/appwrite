export type AppwriteAuth = Record<string, string[]>
export type AppwritePlatformAuth = Partial<
  Record<ApiSpecPlatform, AppwriteAuth>
>

export type AppwriteAdditionalMethod = {
  name: string
  desc?: string
  summary?: string
  namespace?: string
  platforms?: ApiSpecPlatform[]
  weight?: number
  auth?: AppwriteAuth | AppwritePlatformAuth
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
  method?: string
  group?: string
  weight?: number
  type?: string
  demo?: string
  'rate-limit'?: number
  'rate-time'?: number
  'rate-key'?: string | string[]
  scope?: string | string[]
  platforms?: Array<'console' | 'client' | 'server'>
  packaging?: boolean
  public?: boolean
  auth?: AppwriteAuth | AppwritePlatformAuth
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

export type ApiExplorerServerAuthMode = 'manual' | 'ephemeral'

export type ApiExplorerClientAuthState = {
  mode: ApiExplorerSessionAuthMode
  userId: string
}

export type ApiExplorerServerAuthState = {
  mode: ApiExplorerServerAuthMode
  manualApiKey: string
  ephemeralApiKey: string
  /** Scopes selected for the next ephemeral key generation. */
  ephemeralDraftScopes: string[]
  /** Scopes granted to the currently generated ephemeral key. */
  ephemeralKeyScopes: string[]
}

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

export type OpenApiDiscriminator = {
  propertyName?: string
  mapping?: Record<string, string>
  /** Legacy compound mapping; prefer required enum conditions on union branches. */
  'x-mapping'?: Record<string, Record<string, string>>
  /** @deprecated Derive names from standard conditions or legacy x-mapping keys. */
  'x-propertyNames'?: string[]
}

export type OpenApiSchema = {
  type?: string
  title?: string
  description?: string
  example?: unknown
  'x-example'?: unknown
  default?: unknown
  const?: unknown
  enum?: unknown[]
  properties?: Record<string, OpenApiSchema>
  required?: string[]
  items?: OpenApiSchema
  $ref?: string
  nullable?: boolean
  'x-nullable'?: boolean
  /** @deprecated Replaced by schema title. */
  'x-enum-name'?: string
  /** @deprecated Replaced by titled singleton-enum oneOf/anyOf branches. */
  'x-enum-keys'?: string[]
  /** @deprecated Inferred from multipart binary + `{name}Id`. */
  'x-upload-id'?: boolean | string
  format?: string
  oneOf?: OpenApiSchema[]
  allOf?: OpenApiSchema[]
  anyOf?: OpenApiSchema[]
  not?: OpenApiSchema
  additionalProperties?: boolean | OpenApiSchema
  minProperties?: number
  maxProperties?: number
  discriminator?: OpenApiDiscriminator
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
  tags?: Array<{
    name?: string
    description?: string
  }>
  paths?: Record<string, Record<string, OpenApiOperation>>
  security?: Array<Record<string, string[]>>
  components?: {
    securitySchemes?: Record<
      string,
      {
        type?: string
        'x-appwrite'?: { platforms?: string[] }
      }
    >
    schemas?: Record<string, OpenApiSchema>
  }
}

export type ApiExplorerMethod = {
  /** SDK method name derived from operationId, or an explicit alias name. */
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
  security?: Array<Record<string, string[]>>
  rawResponses?: OpenApiOperation['responses']
  xAppwrite?: Omit<AppwriteOpenApiExtension, 'auth'> & { auth?: AppwriteAuth }
  authLabel: string
}

export type ApiExplorerService = {
  id: string
  label: string
  description?: string
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

export type ExecuteApiMultipartRequestInput = Omit<
  ExecuteApiRequestInput,
  'body'
> & {
  formData: FormData
}

export type ExecuteApiRequestResult = {
  status: number
  statusText: string
  headers: Record<string, string>
  body: string
  durationMs: number
  ok: boolean
  /** Data URL when the response Content-Type is an image. */
  imagePreviewUrl?: string
  responseContentType?: string
  /** Raw response body size in bytes. */
  responseByteSize: number
}

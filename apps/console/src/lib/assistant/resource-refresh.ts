/**
 * Deterministic mapping from agent MCP tool completions (and console
 * `resource` actions) to Console refresh scopes.
 *
 * Used to keep open Console pages fresh after the agent mutates project
 * resources via MCP — without relying on the model emitting an explicit
 * `refresh` action.
 */

import {
  isConsoleToolName,
  parseConsoleEnvelope,
} from '@/lib/assistant/console-protocol'
import {
  classifyResourceMutation,
  resolveCatalogToolName,
} from '@/lib/assistant/resource-mutations'

/**
 * Specific resource tokens take priority over broad service prefixes
 * (e.g. `storage_create_bucket` → buckets, not both buckets+files).
 */
const SPECIFIC_TOKEN_SCOPES: Record<string, string[]> = {
  user: ['users'],
  users: ['users'],
  team: ['teams'],
  teams: ['teams'],
  bucket: ['buckets'],
  buckets: ['buckets'],
  file: ['files'],
  files: ['files'],
  database: ['databases'],
  databases: ['databases'],
  table: ['tables'],
  tables: ['tables'],
  row: ['tables'],
  rows: ['tables'],
  column: ['tables'],
  columns: ['tables'],
  attribute: ['tables'],
  attributes: ['tables'],
  index: ['tables'],
  indexes: ['tables'],
  function: ['functions'],
  functions: ['functions'],
  site: ['sites'],
  sites: ['sites'],
  deployment: ['deployments'],
  deployments: ['deployments'],
  execution: ['executions'],
  executions: ['executions'],
  variable: ['variables'],
  variables: ['variables'],
  message: ['messages'],
  messages: ['messages'],
  topic: ['topics'],
  topics: ['topics'],
  provider: ['providers'],
  providers: ['providers'],
  subscriber: ['topics'],
  subscribers: ['topics'],
  webhook: ['webhooks'],
  webhooks: ['webhooks'],
  platform: ['platforms'],
  platforms: ['platforms'],
  domain: ['domains'],
  domains: ['domains'],
  rule: ['domains'],
  rules: ['domains'],
  key: ['keys'],
  keys: ['keys'],
  project: ['project'],
  projects: ['project'],
  organization: ['organization'],
  organizations: ['organization'],
  org: ['organization'],
}

/** First-token / service fallbacks when no specific resource token is present. */
const SERVICE_PREFIX_SCOPES: Record<string, string[]> = {
  users: ['users'],
  teams: ['teams'],
  storage: ['buckets', 'files'],
  databases: ['databases', 'tables'],
  database: ['databases', 'tables'],
  tables: ['tables'],
  tablesdb: ['tables', 'databases'],
  documents: ['databases', 'tables'],
  documentsdb: ['databases', 'tables'],
  functions: ['functions'],
  sites: ['sites'],
  messaging: ['messages', 'topics', 'providers'],
  projects: ['project'],
  project: ['project'],
  organizations: ['organization'],
  organization: ['organization'],
  webhooks: ['webhooks'],
  platforms: ['platforms'],
  proxy: ['domains'],
  domains: ['domains'],
  keys: ['keys'],
  apikeys: ['keys'],
}

/** Console protocol `resource.resourceType` → refresh scopes. */
const RESOURCE_TYPE_SCOPES: Record<string, string[]> = {
  database: ['databases'],
  databases: ['databases'],
  table: ['tables'],
  tables: ['tables'],
  row: ['tables'],
  column: ['tables'],
  index: ['tables'],
  bucket: ['buckets'],
  buckets: ['buckets'],
  file: ['files'],
  files: ['files'],
  user: ['users'],
  users: ['users'],
  team: ['teams'],
  teams: ['teams'],
  function: ['functions'],
  functions: ['functions'],
  site: ['sites'],
  sites: ['sites'],
  message: ['messages'],
  messages: ['messages'],
  topic: ['topics'],
  topics: ['topics'],
  provider: ['providers'],
  providers: ['providers'],
  deployment: ['deployments'],
  execution: ['executions'],
  variable: ['variables'],
  webhook: ['webhooks'],
  platform: ['platforms'],
  domain: ['domains'],
  project: ['project'],
  organization: ['organization'],
  key: ['keys'],
}

function tokenizeToolName(toolName: string): string[] {
  const normalized = toolName.trim().toLowerCase()
  if (!normalized) return []
  return normalized
    .replace(/([a-z0-9])([A-Z])/g, '$1_$2')
    .toLowerCase()
    .split(/[._\s-]+/)
    .filter(Boolean)
}

function addScopes(target: Set<string>, scopes: string[] | undefined): void {
  if (!scopes?.length) return
  for (const scope of scopes) {
    const normalized = scope.trim().toLowerCase()
    if (normalized) target.add(normalized)
  }
}

/**
 * Map an Appwrite catalog tool name to Console refresh scopes.
 * Returns [] for reads, meta tools, and unrecognized names.
 */
export function refreshScopesForToolName(toolName: string): string[] {
  if (!classifyResourceMutation(toolName)) return []

  const tokens = tokenizeToolName(toolName)
  if (tokens.length === 0) return []

  const scopes = new Set<string>()

  for (const token of tokens) {
    addScopes(scopes, SPECIFIC_TOKEN_SCOPES[token])
  }
  if (scopes.size > 0) return [...scopes]

  addScopes(scopes, SERVICE_PREFIX_SCOPES[tokens[0]!])
  return [...scopes]
}

/**
 * Map a console-protocol resource type string to refresh scopes.
 */
export function refreshScopesForResourceType(
  resourceType: string | null | undefined,
): string[] {
  const normalized = resourceType?.trim().toLowerCase()
  if (!normalized) return []
  return RESOURCE_TYPE_SCOPES[normalized] ?? [normalized]
}

type ToolLike = {
  name?: string | null
  status?: string | null
  argumentsJson?: string | null
  input?: unknown
  errorMessage?: string | null
  output?: unknown
}

/**
 * Collect refresh scopes from a single completed tool (MCP mutation or
 * console-protocol `resource` actions with a mutation).
 */
export function refreshScopesFromCompletedTool(tool: ToolLike): string[] {
  if (tool.errorMessage) return []

  if (isConsoleToolName(tool.name)) {
    const envelope = parseConsoleEnvelope(tool.output)
    if (!envelope) return []
    const scopes = new Set<string>()
    for (const action of envelope.actions) {
      if (!action || typeof action !== 'object') continue
      const type = (action as { type?: string }).type
      if (type === 'resource') {
        const mutation = (action as { mutation?: string }).mutation
        if (
          mutation !== 'create' &&
          mutation !== 'update' &&
          mutation !== 'delete'
        ) {
          continue
        }
        addScopes(
          scopes,
          refreshScopesForResourceType(
            (action as { resourceType?: string }).resourceType,
          ),
        )
      } else if (type === 'refresh') {
        const refreshScopes = (action as { scopes?: unknown }).scopes
        if (!Array.isArray(refreshScopes)) continue
        for (const scope of refreshScopes) {
          if (typeof scope === 'string') addScopes(scopes, [scope])
        }
      }
    }
    return [...scopes]
  }

  return refreshScopesForToolName(resolveCatalogToolName(tool))
}

/**
 * Union refresh scopes from many completed tools (one agent turn).
 */
export function collectRefreshScopesFromTools(
  tools: ToolLike[] | null | undefined,
): string[] {
  if (!tools?.length) return []
  const scopes = new Set<string>()
  for (const tool of tools) {
    addScopes(scopes, refreshScopesFromCompletedTool(tool))
  }
  return [...scopes]
}

import type { Models } from '@appwrite.io/console'
import {
  buildTurnView,
  type AssistantMessageLike,
  type TurnToolView,
} from '@/lib/assistant/turn-view'
import {
  isConsoleToolName,
  parseConsoleEnvelope,
} from '@/lib/assistant/console-protocol'

export type ResourceMutationKind = 'create' | 'update' | 'delete'

export type ResourceMutationCounts = {
  created: number
  updated: number
  deleted: number
}

const META_TOOLS = new Set([
  'appwrite_call_tool',
  'appwrite_search_tools',
  'appwrite_get_context',
  'appwrite_search_docs',
  'appwrite_list_tools',
  'console',
  'clarify',
])

const CREATE_VERBS = new Set(['create', 'add', 'insert', 'upload', 'new'])
const UPDATE_VERBS = new Set([
  'update',
  'upsert',
  'patch',
  'set',
  'edit',
  'rename',
  'move',
  'replace',
])
const DELETE_VERBS = new Set(['delete', 'remove', 'destroy', 'drop', 'purge'])
const READ_VERBS = new Set([
  'list',
  'get',
  'read',
  'fetch',
  'search',
  'find',
  'preview',
  'download',
  'export',
])

function parseMaybeJson(value: unknown): unknown {
  if (typeof value !== 'string') return value
  const trimmed = value.trim()
  if (!trimmed) return value
  try {
    return JSON.parse(trimmed)
  } catch {
    return value
  }
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return !!value && typeof value === 'object' && !Array.isArray(value)
}

function tokenizeToolName(toolName: string): string[] {
  const normalized = toolName.trim().toLowerCase()
  if (!normalized) return []
  // users_create / users.create / users-create / usersCreate / createUser
  return normalized
    .replace(/([a-z0-9])([A-Z])/g, '$1_$2')
    .toLowerCase()
    .split(/[._\s-]+/)
    .filter(Boolean)
}

function isFailedToolStatus(status?: string | null): boolean {
  const normalized = status?.toLowerCase() ?? ''
  return (
    normalized === 'failed' ||
    normalized === 'error' ||
    normalized === 'cancelled' ||
    normalized === 'canceled' ||
    normalized === 'stopped'
  )
}

function isInFlightToolStatus(status?: string | null): boolean {
  const normalized = status?.toLowerCase() ?? ''
  return (
    normalized === 'running' ||
    normalized === 'queued' ||
    normalized === 'pending' ||
    normalized === 'processing'
  )
}

function stringField(
  record: Record<string, unknown>,
  keys: string[],
): string | undefined {
  for (const key of keys) {
    const value = record[key]
    if (typeof value === 'string' && value.trim()) {
      return value.trim()
    }
  }
  return undefined
}

function extractCatalogNameFromInput(input: unknown): string | undefined {
  const parsed = parseMaybeJson(input)
  if (!isRecord(parsed)) return undefined

  const direct = stringField(parsed, [
    'tool_name',
    'toolName',
    'tool',
    'name',
    'operation',
    'method',
    'action',
  ])
  if (direct) return direct

  // Nested shapes: { arguments: { tool_name } }, { params: { toolName } }
  for (const nestedKey of ['arguments', 'params', 'input', 'data'] as const) {
    const nested = parsed[nestedKey]
    if (!isRecord(nested)) continue
    const nestedName = stringField(nested, [
      'tool_name',
      'toolName',
      'tool',
      'name',
      'operation',
      'method',
      'action',
    ])
    if (nestedName) return nestedName
  }

  return undefined
}

function isCallToolWrapper(name: string): boolean {
  const normalized = name.trim().toLowerCase()
  return (
    normalized === 'appwrite_call_tool' ||
    normalized.endsWith('_call_tool') ||
    normalized.endsWith('.call_tool') ||
    normalized.includes('appwrite_call_tool')
  )
}

/** Resolve the catalog tool name from an outer MCP wrapper when needed. */
export function resolveCatalogToolName(tool: {
  name?: string | null
  argumentsJson?: string | null
  input?: unknown
}): string {
  const outer = tool.name?.trim() || ''
  const fromInput = extractCatalogNameFromInput(
    tool.input !== undefined ? tool.input : tool.argumentsJson,
  )

  if (fromInput && (!outer || isCallToolWrapper(outer))) {
    return fromInput
  }

  // Prefer unwrapped catalog name even when outer is present but still a wrapper.
  if (fromInput && isCallToolWrapper(outer)) {
    return fromInput
  }

  return outer || fromInput || ''
}

/**
 * Classify an Appwrite catalog tool name into a resource mutation kind.
 * Expects names like `users_create`, `storage_update_bucket`, `tables_delete_row`.
 */
export function classifyResourceMutation(
  toolName: string,
): ResourceMutationKind | null {
  const normalized = toolName.trim().toLowerCase()
  if (!normalized || META_TOOLS.has(normalized) || isCallToolWrapper(normalized)) {
    return null
  }

  const tokens = tokenizeToolName(normalized)
  if (tokens.length === 0) return null

  for (const token of tokens) {
    if (CREATE_VERBS.has(token)) return 'create'
    if (UPDATE_VERBS.has(token)) return 'update'
    if (DELETE_VERBS.has(token)) return 'delete'
    if (READ_VERBS.has(token)) return null
  }

  return null
}

export function emptyResourceMutationCounts(): ResourceMutationCounts {
  return { created: 0, updated: 0, deleted: 0 }
}

export function hasResourceMutations(counts: ResourceMutationCounts): boolean {
  return counts.created > 0 || counts.updated > 0 || counts.deleted > 0
}

type ToolLike = {
  name?: string | null
  status?: string | null
  argumentsJson?: string | null
  input?: unknown
  errorMessage?: string | null
  output?: unknown
}

function shouldCountTool(tool: ToolLike): boolean {
  if (tool.errorMessage) return false
  if (isFailedToolStatus(tool.status)) return false
  if (isInFlightToolStatus(tool.status)) return false
  // Count terminal success and also tools that finished without an explicit
  // status (common on timeline-derived views / partial list payloads).
  return true
}

export function countResourceMutationsFromTools(
  tools: ToolLike[] | null | undefined,
): ResourceMutationCounts {
  const counts = emptyResourceMutationCounts()
  if (!tools?.length) return counts

  for (const tool of tools) {
    if (!shouldCountTool(tool)) continue

    // Prefer explicit console protocol `resource` mutations over MCP name heuristics.
    if (isConsoleToolName(tool.name)) {
      const envelope = parseConsoleEnvelope(tool.output)
      if (!envelope) continue
      for (const action of envelope.actions) {
        if (!action || typeof action !== 'object') continue
        if ((action as { type?: string }).type !== 'resource') continue
        const mutation = (action as { mutation?: string }).mutation
        if (mutation === 'create') counts.created += 1
        else if (mutation === 'update') counts.updated += 1
        else if (mutation === 'delete') counts.deleted += 1
      }
      continue
    }

    const kind = classifyResourceMutation(resolveCatalogToolName(tool))
    if (kind === 'create') counts.created += 1
    else if (kind === 'update') counts.updated += 1
    else if (kind === 'delete') counts.deleted += 1
  }

  return counts
}

function toolsFromMessage(message: AssistantMessageLike): ToolLike[] {
  const turn = buildTurnView(message)
  const fromTurn: TurnToolView[] = turn.toolOrder
    .map((key) => turn.tools[key])
    .filter((tool): tool is TurnToolView => !!tool)

  if (fromTurn.length > 0) return fromTurn

  // Fallback to raw tools when timeline replay produced nothing.
  return Array.isArray(message.tools) ? message.tools : []
}

export function countResourceMutations(
  messages: AssistantMessageLike[] | null | undefined,
): ResourceMutationCounts {
  const counts = emptyResourceMutationCounts()
  if (!messages?.length) return counts

  for (const message of messages) {
    if (message.role && message.role.toLowerCase() === 'user') continue
    const next = countResourceMutationsFromTools(toolsFromMessage(message))
    counts.created += next.created
    counts.updated += next.updated
    counts.deleted += next.deleted
  }

  return counts
}

export function messageNeedsToolHydration(
  message: Models.AgentMessage | AssistantMessageLike,
): boolean {
  if (message.role?.toLowerCase() === 'user') return false

  const counts = countResourceMutationsFromTools(
    toolsFromMessage(message as AssistantMessageLike),
  )
  // Already classifiable from list/timeline payload.
  if (hasResourceMutations(counts)) return false

  const tools = Array.isArray(message.tools) ? message.tools : []
  const turn = buildTurnView(message as AssistantMessageLike)

  // No tool signal at all - getMessage may still include tools.
  if (tools.length === 0 && turn.toolOrder.length === 0) return true

  // Wrapper tools without resolvable catalog names need full argumentsJson.
  const unresolvedWrappers = [...tools, ...Object.values(turn.tools)].some(
    (tool) => {
      const outer = tool?.name?.trim() || ''
      if (!outer || !isCallToolWrapper(outer)) return false
      return !extractCatalogNameFromInput(
        'input' in tool && tool.input !== undefined
          ? tool.input
          : 'argumentsJson' in tool
            ? tool.argumentsJson
            : undefined,
      )
    },
  )
  return unresolvedWrappers
}

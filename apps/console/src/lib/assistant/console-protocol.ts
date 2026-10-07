/**
 * Appwrite Console protocol (`appwrite.console/v1`).
 *
 * Shareable contract between the assistant engine and the Console.
 * The agent posts UI metadata via the built-in `console` tool; the Console
 * parses the tool result and turns it into components / side-effects.
 *
 * ## Chart actions (usage)
 *
 * After calling `usage_list_events` / `usage_list_gauges`, emit a `chart` action
 * so the Console can render the series with the shared usage charts library.
 * Prefer requesting an `interval` (e.g. `1h`) for time-series charts.
 *
 * Example (pass `metrics` through from the usage tool response):
 *
 * ```json
 * {
 *   "type": "chart",
 *   "title": "Requests (last 24 hours)",
 *   "unitLabel": "requests",
 *   "interval": "1h",
 *   "startAt": "2026-08-03T06:00:00.000Z",
 *   "endAt": "2026-08-04T06:00:00.000Z",
 *   "projectId": "…",
 *   "metrics": [
 *     {
 *       "metric": "network.requests",
 *       "points": [{ "time": "2026-08-03T07:00:00.000Z", "value": 42 }]
 *     }
 *   ]
 * }
 * ```
 *
 * Use metric `network.requests` for API request counts (never bare `requests`).
 * Use `chartType: "bar"` (or point `label`s) for dimension breakdowns.
 * Use `kind: "gauges"` when the source tool was `usage_list_gauges`.
 */

/** Wire protocol id — bump only on breaking changes. */
export type ConsoleProtocolId = 'appwrite.console/v1'

export const CONSOLE_PROTOCOL_ID: ConsoleProtocolId = 'appwrite.console/v1'
export const CONSOLE_TOOL_NAME = 'console'

export type ConsoleEnvelope = {
  protocol: ConsoleProtocolId
  actions: ConsoleAction[]
}

export type CreateResourceType =
  | 'database'
  | 'bucket'
  | 'user'
  | 'team'
  | 'function'
  | 'site'

export type ConsoleDialog =
  | 'invite_member'
  | 'create_project'
  | 'connect_mcp'
  | 'shortcuts'
  | 'docs_search'
  | 'feedback'
  | 'support'

export type ConsoleResourceItem = {
  resourceId: string
  title: string
  subtitle?: string
  /** Console-relative path, e.g. /projects/{id}/databases/{db} */
  href?: string
  status?: string
  metadata?: Array<{ label: string; value: string }>
  /**
   * Typed attributes for Console filters / validation
   * (email, phone, status, enabled, region, …). Prefer this over parsing metadata.
   */
  fields?: Record<string, string | number | boolean | null>
}

/**
 * One usage datapoint for a console chart.
 * Mirrors `usage_list_events` / `usage_list_gauges` point shape (time + value).
 */
export type ConsoleChartPoint = {
  /** ISO 8601 timestamp from the usage API */
  time: string
  value: number
  /**
   * Optional category label for bar charts / dimension breakdowns
   * (e.g. country, path, method). When omitted for bars, `time` is used.
   */
  label?: string
}

/** One metric series — mirrors usage API `metrics[]` entries. */
export type ConsoleChartMetric = {
  /** Metric id or display name (e.g. `requests`, `executions`) */
  metric: string
  points: ConsoleChartPoint[]
}

export type ConsoleChartAxisFormat = 'count' | 'bytes' | 'gbhours'

export type ConsoleChartKind = 'events' | 'gauges'

export type ConsoleChartType = 'area' | 'bar'

export type ConsoleAction =
  | { type: 'set_theme'; theme: 'light' | 'dark' | 'system' }
  | { type: 'navigate'; path: string; hash?: string; replace?: boolean }
  | { type: 'open_create'; resource: CreateResourceType; projectId?: string }
  | { type: 'open_dialog'; dialog: ConsoleDialog; projectId?: string }
  | {
      type: 'toast'
      level: 'success' | 'error' | 'info' | 'warning'
      message: string
      description?: string
    }
  | { type: 'show_pane'; content: 'agent' | 'docs' | 'none' }
  | { type: 'toggle_terminal' }
  | { type: 'scroll_to_card'; cardId: string }
  | ({
      type: 'resource'
      mutation: 'create' | 'update' | 'delete'
      /** Appwrite resource kind, e.g. database, bucket, user, function, site, table, file, team */
      resourceType: string
    } & ConsoleResourceItem)
  | {
      type: 'resource_list'
      resourceType: string
      items: ConsoleResourceItem[]
      /** Heading shown above the list UI */
      title?: string
      description?: string
      /** Total matches (may be > items.length when truncated/paginated) */
      total?: number
      /** Deep link to the full Console list page */
      listHref?: string
      emptyMessage?: string
      projectId?: string
      /** Optional column hints for table layout; `key` should match `fields` keys */
      columns?: Array<{ key: string; label: string }>
    }
  | {
      /**
       * Render a usage chart from agent tool data (usage_list_events / gauges).
       * Prefer passing `metrics` straight through from the usage API response.
       */
      type: 'chart'
      title: string
      description?: string
      /** Visual: area (default, time series) or bar (categorical / breakdown) */
      chartType?: ConsoleChartType
      /**
       * Fill strategy when gap-filling a time series.
       * `events` zero-fills missing buckets; `gauges` carry-forward.
       */
      kind?: ConsoleChartKind
      /** Unit next to the total, e.g. "requests", "executions" */
      unitLabel?: string
      /** Y-axis / value formatter hint */
      axisFormat?: ConsoleChartAxisFormat
      /**
       * Bucket size from the usage API (`1m`, `15m`, `30m`, `1h`, `1d`).
       * Empty / omitted = flat aggregate (KPI + optional single-point chart).
       */
      interval?: string
      /** ISO 8601 range start — enables gap-filling when paired with endAt + interval */
      startAt?: string
      /** ISO 8601 range end */
      endAt?: string
      /** Optional % change vs previous period (hides comparison when omitted) */
      changePercent?: number
      /** Deep link to the Console usage page */
      href?: string
      projectId?: string
      /**
       * Series data. Prefer mirroring usage_list_events / usage_list_gauges:
       * `{ metric, points: [{ time, value }] }`.
       */
      metrics: ConsoleChartMetric[]
    }
  | { type: 'refresh'; scopes: string[] }

export type ConsoleRenderableAction = Extract<
  ConsoleAction,
  { type: 'resource' } | { type: 'resource_list' } | { type: 'chart' }
>

export type ConsoleSideEffectAction = Exclude<
  ConsoleAction,
  ConsoleRenderableAction
>

export function isConsoleToolName(name: string | null | undefined): boolean {
  return (name?.trim().toLowerCase() ?? '') === CONSOLE_TOOL_NAME
}

function toolOutputToText(output: unknown): string | null {
  if (output === undefined || output === null) return null
  if (typeof output === 'string') return output
  try {
    return JSON.stringify(output)
  } catch {
    return String(output)
  }
}

/**
 * Parse a console tool result into a protocol envelope.
 * Returns null for validation errors, unknown protocol, or malformed JSON.
 */
export function parseConsoleEnvelope(output: unknown): ConsoleEnvelope | null {
  const text = toolOutputToText(output)?.trim()
  if (!text) return null
  if (text.startsWith('Error:')) return null
  try {
    const parsed = JSON.parse(text) as unknown
    if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) {
      return null
    }
    const record = parsed as Record<string, unknown>
    if (record.protocol !== CONSOLE_PROTOCOL_ID) return null
    if (!Array.isArray(record.actions)) return null
    return {
      protocol: CONSOLE_PROTOCOL_ID,
      actions: record.actions as ConsoleAction[],
    }
  } catch {
    return null
  }
}

export function isRenderableConsoleAction(
  action: ConsoleAction,
): action is ConsoleRenderableAction {
  return (
    action.type === 'resource' ||
    action.type === 'resource_list' ||
    action.type === 'chart'
  )
}

/** Resolve a deep link for a console chart (usage overview by default). */
export function resolveConsoleChartHref(
  href: string | undefined,
  projectId?: string | null,
): string | undefined {
  if (href?.trim()) return normalizeConsolePath(href)
  const pid = projectId?.trim()
  if (!pid) return undefined
  return `/projects/${pid}/usage`
}

export function isSideEffectConsoleAction(
  action: ConsoleAction,
): action is ConsoleSideEffectAction {
  return !isRenderableConsoleAction(action)
}

/** Normalize engine paths (`/project/…`) to Console routes (`/projects/…`). */
export function normalizeConsolePath(path: string): string {
  const trimmed = path.trim()
  if (!trimmed) return trimmed
  if (trimmed.startsWith('/project/')) {
    return `/projects/${trimmed.slice('/project/'.length)}`
  }
  if (trimmed === '/project') return '/projects'
  return trimmed.startsWith('/') ? trimmed : `/${trimmed}`
}

function fieldString(
  fields: ConsoleResourceItem['fields'] | undefined,
  keys: string[],
): string | undefined {
  if (!fields) return undefined
  for (const key of keys) {
    const direct = fields[key]
    if (typeof direct === 'string' && direct.trim()) return direct.trim()
    const match = Object.entries(fields).find(
      ([k]) => k.toLowerCase() === key.toLowerCase(),
    )
    if (typeof match?.[1] === 'string' && match[1].trim()) {
      return match[1].trim()
    }
  }
  return undefined
}

/**
 * Resolve a deep link for a console resource item.
 * Prefers explicit `href`, otherwise builds a best-effort path from type + project.
 */
export function resolveConsoleResourceHref(
  item: Pick<ConsoleResourceItem, 'href' | 'resourceId' | 'fields'>,
  resourceType: string,
  projectId?: string | null,
): string | undefined {
  if (item.href?.trim()) return normalizeConsolePath(item.href)

  const pid = projectId?.trim()
  const id = item.resourceId?.trim()
  if (!pid || !id) return undefined

  const type = resourceType.trim().toLowerCase()
  if (type === 'user' || type === 'users') {
    return `/projects/${pid}/auth/users/${id}`
  }
  if (type === 'team' || type === 'teams') {
    return `/projects/${pid}/auth/teams/${id}`
  }
  if (type === 'bucket' || type === 'buckets') {
    return `/projects/${pid}/storage/${id}`
  }
  if (type === 'function' || type === 'functions') {
    return `/projects/${pid}/functions/${id}`
  }
  if (type === 'site' || type === 'sites') {
    return `/projects/${pid}/sites/${id}`
  }
  if (type === 'database' || type === 'databases') {
    const kind =
      fieldString(item.fields, ['type', 'dbKind', 'kind'])?.toLowerCase() ||
      'tablesdb'
    const dbKind =
      kind === 'legacy' || kind === 'documentsdb' || kind === 'collections'
        ? 'legacy'
        : kind === 'postgres' || kind === 'postgresql'
          ? 'postgres'
          : kind === 'mysql'
            ? 'mysql'
            : kind === 'mongo' || kind === 'mongodb'
              ? 'mongo'
              : 'tablesdb'
    return `/projects/${pid}/databases/${dbKind}/${id}`
  }
  if (type === 'file' || type === 'files') {
    const bucketId = fieldString(item.fields, ['bucketId', 'bucket'])
    if (bucketId) return `/projects/${pid}/storage/${bucketId}/files/${id}`
  }
  if (type === 'table' || type === 'tables' || type === 'collection') {
    const databaseId = fieldString(item.fields, [
      'databaseId',
      'database',
      'database_id',
    ])
    const kind =
      fieldString(item.fields, ['dbKind', 'type', 'kind'])?.toLowerCase() ||
      'tablesdb'
    if (databaseId) {
      const dbKind =
        kind === 'legacy' || kind === 'documentsdb' ? 'legacy' : 'tablesdb'
      return dbKind === 'legacy'
        ? `/projects/${pid}/databases/legacy/${databaseId}/collections/${id}`
        : `/projects/${pid}/databases/tablesdb/${databaseId}/tables/${id}`
    }
  }

  return undefined
}

export function resolveConsoleListHref(
  listHref: string | undefined,
  resourceType: string,
  projectId?: string | null,
): string | undefined {
  if (listHref?.trim()) return normalizeConsolePath(listHref)

  const pid = projectId?.trim()
  if (!pid) return undefined
  const type = resourceType.trim().toLowerCase()
  if (type === 'user' || type === 'users') return `/projects/${pid}/auth`
  if (type === 'team' || type === 'teams') return `/projects/${pid}/auth/teams`
  if (type === 'bucket' || type === 'buckets' || type === 'file' || type === 'files') {
    return `/projects/${pid}/storage`
  }
  if (type === 'function' || type === 'functions') {
    return `/projects/${pid}/functions`
  }
  if (type === 'site' || type === 'sites') return `/projects/${pid}/sites`
  if (
    type === 'database' ||
    type === 'databases' ||
    type === 'table' ||
    type === 'tables'
  ) {
    return `/projects/${pid}/databases`
  }
  return undefined
}

export function normalizeCardId(cardId: string): string {
  const trimmed = cardId.trim()
  if (trimmed.toLowerCase().startsWith('card-')) {
    return trimmed.slice('card-'.length)
  }
  return trimmed
}

export function scrollToConsoleCard(cardId: string): void {
  if (typeof document === 'undefined') return
  const id = normalizeCardId(cardId)
  if (!id) return

  const highlightClasses = [
    'ring-2',
    'ring-foreground/30',
    'ring-offset-2',
    'ring-offset-background',
    'transition-all',
    'duration-700',
  ] as const

  let attempts = 0
  const tick = () => {
    const el = document.querySelector<HTMLElement>(
      `[data-card-id="${CSS.escape(id)}"]`,
    )
    if (el) {
      el.scrollIntoView({ behavior: 'smooth', block: 'start' })
      el.classList.add(...highlightClasses)
      window.setTimeout(() => {
        el.classList.remove(...highlightClasses)
      }, 1600)
      return
    }
    if (attempts++ < 10) {
      window.setTimeout(tick, 80)
    }
  }
  tick()
}

/** Suggested React Query key prefixes per refresh scope. */
export const CONSOLE_REFRESH_SCOPE_KEYS: Record<string, string[]> = {
  databases: ['databases', 'database'],
  tables: ['tables', 'table', 'rows', 'columns', 'indexes'],
  buckets: ['buckets', 'bucket'],
  files: ['files', 'file', 'file-tokens'],
  users: ['users', 'user'],
  teams: ['teams', 'team'],
  functions: ['functions', 'function'],
  sites: ['sites', 'site'],
  providers: ['providers', 'provider'],
  topics: ['topics', 'topic'],
  messages: ['messages', 'message'],
  project: ['project'],
  organization: ['organization', 'organizations'],
  webhooks: ['webhooks', 'webhook'],
  platforms: ['platforms'],
  variables: ['variables'],
  deployments: ['deployments', 'deployment'],
  executions: ['executions', 'execution'],
  domains: ['proxy-rules', 'domains'],
  keys: ['apiKeys'],
}

export type ConsoleToolResult = {
  /**
   * Stable unique id for applying side-effects once.
   * Prefer messageId + toolCallId — the engine reuses `toolCallId`
   * values like `appwrite_console_0` across turns in the same conversation.
   */
  key: string
  toolCallId?: string
  envelope: ConsoleEnvelope
}

/**
 * Build a unique key for a console tool result.
 * Prefer messageId + toolCallId: the engine reuses toolCallId values like
 * `appwrite_console_0` across turns, and `$id` may appear only after hydration.
 */
export function consoleToolApplyKey(
  tool: {
    toolCallId?: string | null
    id?: string | null
    name?: string | null
  },
  options?: { messageId?: string | null; fallbackIndex?: number },
): string {
  const toolCallId = tool.toolCallId?.trim()
  const messageId = options?.messageId?.trim()
  if (messageId && toolCallId) return `msg:${messageId}:call:${toolCallId}`

  const documentId = tool.id?.trim()
  if (documentId) return `tool:${documentId}`

  if (messageId) {
    return `msg:${messageId}:console:${options?.fallbackIndex ?? 0}`
  }
  if (toolCallId) return `call:${toolCallId}`
  return `console:${options?.fallbackIndex ?? 0}`
}

export function collectConsoleToolResults(
  tools: Array<{
    toolCallId?: string | null
    id?: string | null
    name?: string | null
    status?: string | null
    errorMessage?: string | null
    output?: unknown
    /** Optional message scope for uniqueness when `$id` is not yet hydrated. */
    messageId?: string | null
  }>,
): ConsoleToolResult[] {
  const results: ConsoleToolResult[] = []
  for (const tool of tools) {
    if (!isConsoleToolName(tool.name)) continue
    const status = tool.status?.toLowerCase() ?? ''
    if (
      status === 'running' ||
      status === 'queued' ||
      status === 'pending' ||
      status === 'processing'
    ) {
      continue
    }
    if (
      status === 'error' ||
      status === 'failed' ||
      status === 'cancelled' ||
      status === 'canceled' ||
      !!tool.errorMessage
    ) {
      continue
    }
    const envelope = parseConsoleEnvelope(tool.output)
    if (!envelope) continue
    const key = consoleToolApplyKey(tool, {
      messageId: tool.messageId,
      fallbackIndex: results.length,
    })
    results.push({
      key,
      toolCallId: tool.toolCallId?.trim() || undefined,
      envelope,
    })
  }
  return results
}

export function collectRenderableConsoleActions(
  tools: Array<{
    toolCallId?: string | null
    id?: string | null
    name?: string | null
    status?: string | null
    errorMessage?: string | null
    output?: unknown
    messageId?: string | null
  }>,
): Array<ConsoleRenderableAction & { key: string }> {
  const collected: Array<ConsoleRenderableAction & { key: string }> = []
  for (const result of collectConsoleToolResults(tools)) {
    result.envelope.actions.forEach((action, index) => {
      if (!isRenderableConsoleAction(action)) return
      collected.push({
        ...action,
        key: `${result.key}:${index}`,
      })
    })
  }
  return collected
}

/** Side-effect actions that should expose a replay CTA in chat history. */
export type ConsoleCtaAction = ConsoleSideEffectAction & { key: string }

export function isConsoleCtaAction(
  action: ConsoleAction,
): action is ConsoleSideEffectAction {
  switch (action.type) {
    case 'open_dialog':
    case 'open_create':
    case 'navigate':
    case 'toggle_terminal':
    case 'show_pane':
    case 'set_theme':
      return true
    default:
      return false
  }
}

export function collectConsoleCtaActions(
  tools: Array<{
    toolCallId?: string | null
    id?: string | null
    name?: string | null
    status?: string | null
    errorMessage?: string | null
    output?: unknown
    messageId?: string | null
  }>,
): ConsoleCtaAction[] {
  const collected: ConsoleCtaAction[] = []
  for (const result of collectConsoleToolResults(tools)) {
    result.envelope.actions.forEach((action, index) => {
      if (!isConsoleCtaAction(action)) return
      collected.push({
        ...action,
        key: `${result.key}:cta:${index}`,
      })
    })
  }
  return collected
}

export function consoleCtaLabel(action: ConsoleSideEffectAction): string {
  switch (action.type) {
    case 'open_dialog': {
      switch (action.dialog) {
        case 'shortcuts':
          return 'Show keyboard shortcuts'
        case 'docs_search':
          return 'Search docs'
        case 'feedback':
          return 'Send feedback'
        case 'support':
          return 'Contact support'
        case 'connect_mcp':
          return 'Connect MCP'
        case 'create_project':
          return 'Create project'
        case 'invite_member':
          return 'Invite member'
        default:
          return 'Open'
      }
    }
    case 'open_create': {
      switch (action.resource) {
        case 'database':
          return 'Create database'
        case 'bucket':
          return 'Create bucket'
        case 'user':
          return 'Create user'
        case 'team':
          return 'Create team'
        case 'function':
          return 'Create function'
        case 'site':
          return 'Create site'
        default:
          return 'Create'
      }
    }
    case 'navigate':
      return 'Open in Console'
    case 'toggle_terminal':
      return 'Open terminal'
    case 'show_pane':
      if (action.content === 'docs') return 'Open docs'
      if (action.content === 'agent') return 'Open agent'
      return 'Close panel'
    case 'set_theme':
      if (action.theme === 'dark') return 'Switch to dark mode'
      if (action.theme === 'light') return 'Switch to light mode'
      return 'Use system theme'
    default:
      return 'Open'
  }
}

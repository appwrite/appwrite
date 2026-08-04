import type { Models } from '@appwrite.io/console'

export type AssistantTurnStatus =
  | 'queued'
  | 'running'
  | 'completed'
  | 'failed'
  | 'stopped'
  | string

export type TimelineEvent = {
  type: string
  [key: string]: unknown
}

export type TurnAgentSection = {
  agent: string
  open: boolean
  summary?: string
  failed?: boolean
  toolCallCount?: number
}

export type TurnToolView = {
  id?: string
  toolCallId?: string
  name: string
  agent?: string
  status: string
  input?: unknown
  output?: unknown
  errorMessage?: string
}

export type TurnRoute = {
  agent?: string
  next?: string
  reason?: string
}

export type TurnView = {
  messageId: string
  status: AssistantTurnStatus
  contentText: string
  route?: TurnRoute
  statusLabel?: string
  answeringAgent?: string
  agents: TurnAgentSection[]
  tools: Record<string, TurnToolView>
  toolOrder: string[]
  timeline: TimelineEvent[]
  error?: string
}

export type AssistantMessageLike = Pick<
  Models.AgentMessage,
  | '$id'
  | 'status'
  | 'contentText'
  | 'routeAgent'
  | 'routeNext'
  | 'routeReason'
  | 'tools'
> & {
  role?: string
  conversationId?: string
  timeline?: unknown
  timelineJson?: unknown
  errorCode?: string
  errorMessage?: string
}

const KNOWN_AGENT_LABELS: Record<string, string> = {
  supervisor: 'Supervisor',
  researcher: 'Researcher',
  appwrite: 'Appwrite',
  worker: 'Worker',
  platform: 'Platform',
  planner: 'Planner',
  FINISH: 'Supervisor',
}

/**
 * Display label for an assistant/subagent id.
 * Always includes an "agent" suffix so short ids like "platform" stay clear in UI.
 */
export function getAssistantAgentLabel(agent?: string | null): string {
  if (!agent) return 'Agent'
  const base = KNOWN_AGENT_LABELS[agent] ?? agent
  const trimmed = String(base).trim()
  if (!trimmed) return 'Agent'
  if (/agent$/i.test(trimmed)) return trimmed
  return `${trimmed} agent`
}

export function isAssistantMessageInFlight(status?: string | null): boolean {
  const normalized = status?.toLowerCase()
  return (
    normalized === 'running' ||
    normalized === 'queued' ||
    normalized === 'processing' ||
    normalized === 'pending'
  )
}

export function isAssistantConversationInFlight(conversation?: {
  status?: string | null
  lockState?: string | null
} | null): boolean {
  if (!conversation) return false
  const status = conversation.status?.toLowerCase()
  const lockState = conversation.lockState?.toLowerCase()
  return status === 'running' || status === 'queued' || lockState === 'locked'
}

/** Visual tone for conversation status dots in the conversations list. */
export type AssistantConversationStatusTone =
  | 'ready'
  | 'running'
  | 'queued'
  | 'failed'
  | 'stopped'

export function getAssistantConversationStatusTone(conversation?: {
  status?: string | null
  lockState?: string | null
} | null): AssistantConversationStatusTone {
  if (!conversation) return 'ready'
  const status = conversation.status?.toLowerCase() ?? ''
  const lockState = conversation.lockState?.toLowerCase() ?? ''

  if (status === 'failed' || status === 'error') return 'failed'
  // Idle for list UI (no status dot): stopped, cancelled, and archived
  // (archived is already shown via the Archived section).
  if (
    status === 'stopped' ||
    status === 'cancelled' ||
    status === 'canceled' ||
    status === 'archived'
  ) {
    return 'ready'
  }
  if (status === 'queued' || status === 'pending') return 'queued'
  if (status === 'running' || lockState === 'locked') return 'running'
  return 'ready'
}

/** English label for `t(...)` – keep keys stable for i18n. */
export function getAssistantConversationStatusLabel(
  tone: AssistantConversationStatusTone,
): string {
  switch (tone) {
    case 'running':
      return 'Running'
    case 'queued':
      return 'Queued'
    case 'failed':
      return 'Failed'
    case 'stopped':
      return 'Stopped'
    case 'ready':
    default:
      return 'Ready'
  }
}

export function getAssistantConversationStatusDotClass(
  tone: AssistantConversationStatusTone,
): string {
  switch (tone) {
    case 'running':
      return 'bg-blue-500 animate-pulse'
    case 'queued':
      return 'bg-amber-500'
    case 'failed':
      return 'bg-destructive'
    case 'stopped':
      return 'bg-amber-500'
    case 'ready':
    default:
      return 'bg-muted-foreground/40'
  }
}

/** Coarse UI phase for bubble activity during a live turn. */
export type AssistantBubblePhase =
  | 'idle'
  | 'waiting'
  | 'routing'
  | 'working'
  | 'answering'

export function normalizeTimeline(raw: unknown): TimelineEvent[] {
  if (Array.isArray(raw)) {
    return raw.filter(
      (event): event is TimelineEvent =>
        !!event &&
        typeof event === 'object' &&
        typeof (event as TimelineEvent).type === 'string',
    )
  }

  if (typeof raw === 'string' && raw.trim()) {
    try {
      return normalizeTimeline(JSON.parse(raw))
    } catch {
      return []
    }
  }

  if (raw && typeof raw === 'object') {
    const asRecord = raw as Record<string, unknown>
    if (Array.isArray(asRecord.events)) {
      return normalizeTimeline(asRecord.events)
    }
  }

  return []
}

function parseMaybeJson(value: unknown): unknown {
  if (typeof value !== 'string') return value
  const trimmed = value.trim()
  if (!trimmed) return value
  if (
    !(
      (trimmed.startsWith('{') && trimmed.endsWith('}')) ||
      (trimmed.startsWith('[') && trimmed.endsWith(']'))
    )
  ) {
    return value
  }
  try {
    return JSON.parse(trimmed)
  } catch {
    return value
  }
}

function toolKey(tool: {
  toolCallId?: string | null
  id?: string | null
  $id?: string | null
  agent?: string | null
  name?: string | null
  status?: string | null
}): string {
  if (tool.toolCallId) return tool.toolCallId
  if (tool.id) return tool.id
  if (tool.$id) return tool.$id
  return `${tool.agent ?? 'agent'}:${tool.name ?? 'tool'}:${tool.status ?? 'unknown'}`
}

function isTerminalToolStatus(status?: string | null): boolean {
  const normalized = status?.toLowerCase()
  return (
    normalized === 'success' ||
    normalized === 'completed' ||
    normalized === 'error' ||
    normalized === 'failed' ||
    normalized === 'cancelled' ||
    normalized === 'canceled'
  )
}

function upsertTool(
  tools: Record<string, TurnToolView>,
  toolOrder: string[],
  next: TurnToolView,
  preferredKey?: string,
): string {
  const key = preferredKey || toolKey(next)
  const existing = tools[key]
  if (!existing) {
    tools[key] = next
  } else {
    // Skip undefined so incomplete tool docs / timeline events do not wipe
    // a previously hydrated input/output.
    const merged: TurnToolView = { ...existing }
    ;(Object.keys(next) as Array<keyof TurnToolView>).forEach((field) => {
      const value = next[field]
      if (value !== undefined) {
        ;(merged as Record<string, unknown>)[field] = value
      }
    })
    tools[key] = merged
  }
  if (!toolOrder.includes(key)) {
    toolOrder.push(key)
  }
  return key
}

function findRunningToolKey(
  tools: Record<string, TurnToolView>,
  toolOrder: string[],
  agent?: string,
  name?: string,
): string | undefined {
  for (let index = toolOrder.length - 1; index >= 0; index -= 1) {
    const key = toolOrder[index]
    const tool = tools[key]
    if (!tool) continue
    if (agent && tool.agent && tool.agent !== agent) continue
    if (name && tool.name !== name) continue
    if (tool.status?.toLowerCase() === 'running') return key
  }
  return undefined
}

function applyToolDocument(
  tools: Record<string, TurnToolView>,
  toolOrder: string[],
  tool: Models.AgentTool | TurnToolView | Record<string, unknown>,
): void {
  const record = tool as Record<string, unknown>
  const name =
    (typeof record.name === 'string' && record.name) ||
    (typeof record.tool === 'string' && record.tool) ||
    'tool'
  const toolCallId =
    typeof record.toolCallId === 'string' ? record.toolCallId : undefined
  const id =
    typeof record.$id === 'string'
      ? record.$id
      : typeof record.id === 'string'
        ? record.id
        : undefined
  const agent = typeof record.agent === 'string' ? record.agent : undefined
  const status =
    typeof record.status === 'string' ? record.status : 'running'
  const input =
    record.input !== undefined
      ? record.input
      : record.argumentsJson !== undefined
        ? parseMaybeJson(record.argumentsJson)
        : undefined
  // Prefer structured JSON when present; fall back to text. Empty strings are
  // treated as missing so they do not clobber a fuller value already on the turn.
  const output =
    record.output !== undefined && record.output !== ''
      ? record.output
      : record.resultJson !== undefined && record.resultJson !== ''
        ? parseMaybeJson(record.resultJson)
        : record.resultText !== undefined && record.resultText !== ''
          ? record.resultText
          : undefined
  const errorMessage =
    typeof record.errorMessage === 'string' ? record.errorMessage : undefined

  upsertTool(
    tools,
    toolOrder,
    {
      id,
      toolCallId,
      name,
      agent,
      status,
      input,
      output,
      errorMessage,
    },
    toolCallId || id,
  )
}

/**
 * Replay a full timeline into derived turn UI state.
 * Timeline is always treated as a complete ordered list (not a delta).
 * Seed tool documents are overlaid afterward so hydrated status/results win.
 */
export function replayTimeline(
  timeline: TimelineEvent[],
  seedTools: Array<Models.AgentTool | TurnToolView | Record<string, unknown>> = [],
  seedRoute?: TurnRoute,
): Pick<
  TurnView,
  | 'route'
  | 'statusLabel'
  | 'answeringAgent'
  | 'agents'
  | 'tools'
  | 'toolOrder'
  | 'error'
> {
  let route = seedRoute
  let statusLabel: string | undefined
  let answeringAgent: string | undefined
  let error: string | undefined
  const agents: TurnAgentSection[] = []
  const tools: Record<string, TurnToolView> = {}
  const toolOrder: string[] = []

  for (const event of timeline) {
    switch (event.type) {
      case 'status': {
        if (typeof event.message === 'string' && event.message.trim()) {
          statusLabel = event.message
        }
        break
      }
      case 'route': {
        // Routing decided - drop the transient "Routing request…" chip.
        statusLabel = undefined
        route = {
          agent: typeof event.agent === 'string' ? event.agent : route?.agent,
          next: typeof event.next === 'string' ? event.next : route?.next,
          reason:
            typeof event.reason === 'string' ? event.reason : route?.reason,
        }
        break
      }
      case 'subagent_start': {
        statusLabel = undefined
        const agent =
          typeof event.agent === 'string' ? event.agent : 'unknown'
        const existing = agents.find((entry) => entry.agent === agent && entry.open)
        if (existing) {
          existing.open = true
        } else {
          agents.push({ agent, open: true })
        }
        break
      }
      case 'subagent_end': {
        const agent =
          typeof event.agent === 'string' ? event.agent : 'unknown'
        const section =
          [...agents].reverse().find((entry) => entry.agent === agent) ??
          agents[agents.length - 1]
        if (section) {
          section.open = false
          if (typeof event.summary === 'string') {
            section.summary = event.summary
          }
          if (typeof event.failed === 'boolean') {
            section.failed = event.failed
          }
          if (typeof event.tool_calls === 'number') {
            section.toolCallCount = event.tool_calls
          }
        }
        break
      }
      case 'tool_start': {
        const agent = typeof event.agent === 'string' ? event.agent : undefined
        const name =
          typeof event.tool === 'string'
            ? event.tool
            : typeof event.name === 'string'
              ? event.name
              : 'tool'
        const toolCallId =
          typeof event.toolCallId === 'string' ? event.toolCallId : undefined
        upsertTool(
          tools,
          toolOrder,
          {
            toolCallId,
            name,
            agent,
            status: 'running',
            input: event.input,
          },
          toolCallId,
        )
        break
      }
      case 'tool_end': {
        const agent = typeof event.agent === 'string' ? event.agent : undefined
        const name =
          typeof event.tool === 'string'
            ? event.tool
            : typeof event.name === 'string'
              ? event.name
              : undefined
        const toolCallId =
          typeof event.toolCallId === 'string' ? event.toolCallId : undefined
        const key =
          (toolCallId && tools[toolCallId] ? toolCallId : undefined) ||
          findRunningToolKey(tools, toolOrder, agent, name) ||
          (toolCallId
            ? upsertTool(
                tools,
                toolOrder,
                {
                  toolCallId,
                  name: name || 'tool',
                  agent,
                  status: 'success',
                  output: event.output,
                },
                toolCallId,
              )
            : undefined) ||
          (name
            ? upsertTool(
                tools,
                toolOrder,
                {
                  name,
                  agent,
                  status: 'success',
                  output: event.output,
                },
                undefined,
              )
            : findRunningToolKey(tools, toolOrder, agent, undefined))
        if (key && tools[key]) {
          tools[key] = {
            ...tools[key],
            status: 'success',
            output: event.output ?? tools[key].output,
            agent: agent ?? tools[key].agent,
            name: name ?? tools[key].name,
            toolCallId: toolCallId ?? tools[key].toolCallId,
          }
        }
        break
      }
      case 'answer_start': {
        // Answer text is about to stream - status chips / tool spinners are done.
        statusLabel = undefined
        if (typeof event.agent === 'string') {
          answeringAgent = event.agent
        }
        for (const key of Object.keys(tools)) {
          const tool = tools[key]
          if (!tool || isTerminalToolStatus(tool.status)) continue
          tools[key] = {
            ...tool,
            status: tool.errorMessage ? 'error' : 'success',
          }
        }
        break
      }
      case 'error': {
        statusLabel = undefined
        if (typeof event.detail === 'string' && event.detail.trim()) {
          error = event.detail
        }
        break
      }
      default:
        // Unknown timeline types: ignore for derived UI; do not fail the turn.
        break
    }
  }

  // Hydrated tool documents win for status/results (GET + Realtime tools).
  for (const tool of seedTools) {
    applyToolDocument(tools, toolOrder, tool)
  }

  return {
    route,
    statusLabel,
    answeringAgent,
    agents,
    tools,
    toolOrder,
    error,
  }
}

function finalizeTurnDerivedState(
  derived: Pick<
    TurnView,
    | 'route'
    | 'statusLabel'
    | 'answeringAgent'
    | 'agents'
    | 'tools'
    | 'toolOrder'
    | 'error'
  >,
  message: AssistantMessageLike,
): Pick<
  TurnView,
  | 'route'
  | 'statusLabel'
  | 'answeringAgent'
  | 'agents'
  | 'tools'
  | 'toolOrder'
  | 'error'
> {
  const inFlight = isAssistantMessageInFlight(message.status)
  const hasAnswer =
    typeof message.contentText === 'string' &&
    message.contentText.trim().length > 0
  const toolsPhaseDone = !inFlight || hasAnswer || !!derived.answeringAgent

  let statusLabel = derived.statusLabel
  if (toolsPhaseDone) {
    statusLabel = undefined
  }

  const tools = { ...derived.tools }
  if (toolsPhaseDone) {
    for (const key of Object.keys(tools)) {
      const tool = tools[key]
      if (!tool || isTerminalToolStatus(tool.status)) continue
      tools[key] = {
        ...tool,
        status: tool.errorMessage ? 'error' : 'success',
      }
    }
  }

  const agents = derived.agents.map((section) =>
    toolsPhaseDone && section.open ? { ...section, open: false } : section,
  )

  return {
    ...derived,
    statusLabel,
    tools,
    agents,
  }
}

export function buildTurnView(
  message: AssistantMessageLike,
  extraTools: Array<
    Models.AgentTool | TurnToolView | Record<string, unknown>
  > = [],
): TurnView {
  const timeline = normalizeTimeline(
    message.timeline !== undefined && message.timeline !== null
      ? message.timeline
      : message.timelineJson,
  )

  const seedRoute: TurnRoute | undefined =
    message.routeAgent || message.routeNext || message.routeReason
      ? {
          agent: message.routeAgent || undefined,
          next: message.routeNext || undefined,
          reason: message.routeReason || undefined,
        }
      : undefined

  const messageTools = Array.isArray(message.tools) ? message.tools : []
  const derived = finalizeTurnDerivedState(
    replayTimeline(timeline, [...messageTools, ...extraTools], seedRoute),
    message,
  )

  const errorFromMessage =
    typeof message.errorMessage === 'string' && message.errorMessage.trim()
      ? message.errorMessage
      : undefined

  return {
    messageId: message.$id,
    status: message.status ?? 'queued',
    contentText: message.contentText ?? '',
    route: derived.route ?? seedRoute,
    statusLabel: derived.statusLabel,
    answeringAgent: derived.answeringAgent,
    agents: derived.agents,
    tools: derived.tools,
    toolOrder: derived.toolOrder,
    timeline,
    error: derived.error ?? errorFromMessage,
  }
}

export function getAssistantBubblePhase(input: {
  isConversationRunning: boolean
  isSending?: boolean
  latestUserWaiting?: boolean
  message?: AssistantMessageLike | null
  turn?: TurnView | null
}): AssistantBubblePhase {
  if (input.isSending) return 'waiting'
  if (!input.isConversationRunning) return 'idle'

  const message = input.message
  if (!message) {
    return input.latestUserWaiting ? 'waiting' : 'routing'
  }

  const turn = input.turn ?? buildTurnView(message)
  if (turn.contentText.trim() || turn.answeringAgent) return 'answering'
  if (turn.agents.some((agent) => agent.open) || turn.toolOrder.length > 0) {
    return 'working'
  }
  if (turn.statusLabel || turn.route?.agent) return 'routing'
  if (isAssistantMessageInFlight(message.status)) return 'waiting'
  return 'waiting'
}

export function toolsForAgent(
  turn: TurnView,
  agent?: string,
): TurnToolView[] {
  return turn.toolOrder
    .map((key) => turn.tools[key])
    .filter((tool): tool is TurnToolView => {
      if (!tool) return false
      if (!agent) return true
      return tool.agent === agent
    })
}

export function unscopedTools(turn: TurnView): TurnToolView[] {
  const scopedAgents = new Set(turn.agents.map((agent) => agent.agent))
  const scopedToolIds = new Set(
    turn.toolOrder
      .map((key) => turn.tools[key])
      .filter(
        (tool): tool is TurnToolView =>
          !!tool?.agent && scopedAgents.has(tool.agent),
      )
      .flatMap((tool) =>
        [tool.toolCallId, tool.id].filter(
          (value): value is string => typeof value === 'string' && !!value,
        ),
      ),
  )

  return turn.toolOrder
    .map((key) => turn.tools[key])
    .filter((tool): tool is TurnToolView => {
      if (!tool) return false
      if (tool.agent && scopedAgents.has(tool.agent)) return false
      // Drop placeholder duplicates already represented inside a subagent section.
      if (
        scopedToolIds.size > 0 &&
        tool.name === 'tool' &&
        !tool.toolCallId &&
        !tool.id
      ) {
        return false
      }
      if (
        (tool.toolCallId && scopedToolIds.has(tool.toolCallId)) ||
        (tool.id && scopedToolIds.has(tool.id))
      ) {
        return false
      }
      return true
    })
}

import type { QueryClient } from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'
import { isAssistantMessageInFlight, normalizeTimeline } from './turn-view'

type MessagesCache = {
  messages: Models.AssistantMessage[]
  total: number
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return !!value && typeof value === 'object'
}

function asAssistantMessage(
  payload: Record<string, unknown>,
): Models.AssistantMessage | null {
  if (typeof payload.$id !== 'string') return null
  if (typeof payload.conversationId !== 'string') return null

  const timeline =
    payload.timeline !== undefined
      ? normalizeTimeline(payload.timeline)
      : typeof payload.timelineJson === 'string'
        ? normalizeTimeline(payload.timelineJson)
        : Array.isArray(payload.timeline)
          ? normalizeTimeline(payload.timeline)
          : undefined

  return {
    ...(payload as unknown as Models.AssistantMessage),
    $id: payload.$id,
    conversationId: payload.conversationId,
    ...(timeline !== undefined ? { timeline } : {}),
  }
}

function asAssistantTool(
  payload: Record<string, unknown>,
): Models.AssistantTool | null {
  if (typeof payload.$id !== 'string') return null
  if (typeof payload.messageId !== 'string') return null
  return payload as unknown as Models.AssistantTool
}

function asAssistantConversation(
  payload: Record<string, unknown>,
): Models.AssistantConversation | null {
  if (typeof payload.$id !== 'string') return null
  return payload as unknown as Models.AssistantConversation
}

function mergeMessageFields(
  previous: Models.AssistantMessage | undefined,
  next: Models.AssistantMessage,
): Models.AssistantMessage {
  if (!previous) return next

  const merged: Models.AssistantMessage = { ...previous, ...next }

  // Streaming message updates often omit hydrated tools; keep what we already have.
  if (!Array.isArray(next.tools)) {
    merged.tools = previous.tools
  } else if (next.tools.length === 0 && previous.tools?.length) {
    merged.tools = previous.tools
  }

  return merged
}

function upsertMessageInList(
  messages: Models.AssistantMessage[],
  next: Models.AssistantMessage,
): Models.AssistantMessage[] {
  const existingIndex = messages.findIndex((message) => message.$id === next.$id)
  let nextMessages =
    existingIndex >= 0
      ? messages.map((message, index) =>
          index === existingIndex
            ? mergeMessageFields(message, next)
            : message,
        )
      : [...messages, next]

  // Drop superseded originals when a replacement arrives.
  const editedFrom =
    typeof next.editedFromMessageId === 'string'
      ? next.editedFromMessageId
      : ''
  if (editedFrom) {
    nextMessages = nextMessages.filter((message) => message.$id !== editedFrom)
  }

  return nextMessages.sort((a, b) =>
    a.$createdAt.localeCompare(b.$createdAt),
  )
}

function upsertToolOnMessage(
  message: Models.AssistantMessage,
  tool: Models.AssistantTool,
): Models.AssistantMessage {
  const tools = Array.isArray(message.tools) ? [...message.tools] : []
  const existingIndex = tools.findIndex(
    (entry) =>
      entry.$id === tool.$id ||
      (!!tool.toolCallId && entry.toolCallId === tool.toolCallId),
  )
  if (existingIndex >= 0) {
    tools[existingIndex] = { ...tools[existingIndex], ...tool }
  } else {
    tools.push(tool)
  }
  return { ...message, tools }
}

function sortConversationsByUpdatedAt(
  conversations: Models.AssistantConversation[],
): Models.AssistantConversation[] {
  return [...conversations].sort((a, b) =>
    (b.$updatedAt ?? '').localeCompare(a.$updatedAt ?? ''),
  )
}

function conversationsEqualForList(
  previous: Models.AssistantConversation,
  next: Models.AssistantConversation,
): boolean {
  return (
    previous.status === next.status &&
    previous.lockState === next.lockState &&
    previous.title === next.title &&
    previous.$updatedAt === next.$updatedAt &&
    previous.activeMessageId === next.activeMessageId
  )
}

function isAutomationRunConversation(conversation: {
  automationId?: string | null
}): boolean {
  return Boolean(
    typeof conversation.automationId === 'string' &&
      conversation.automationId.trim(),
  )
}

export function mergeAssistantConversationIntoCache(
  queryClient: QueryClient,
  conversation: Models.AssistantConversation,
): void {
  queryClient.setQueriesData<Models.AssistantConversation[] | undefined>(
    { queryKey: ['assistant', 'conversations'], exact: false },
    (old) => {
      if (!old) return old
      const index = old.findIndex((entry) => entry.$id === conversation.$id)
      // Agents list must not include automation runs.
      if (isAutomationRunConversation(conversation)) {
        if (index < 0) return old
        const next = old.filter((entry) => entry.$id !== conversation.$id)
        return next.length === old.length ? old : next
      }
      if (index >= 0) {
        const merged = { ...old[index], ...conversation }
        if (conversationsEqualForList(old[index], merged)) {
          return old
        }
        const next = [...old]
        next[index] = merged
        // Only re-sort when updatedAt moved; status-only patches keep order stable.
        if (old[index].$updatedAt === merged.$updatedAt) {
          return next
        }
        return sortConversationsByUpdatedAt(next)
      }
      return sortConversationsByUpdatedAt([conversation, ...old])
    },
  )

  // Re-hydrate list-row resource counters when a run finishes (tools may only
  // be complete on the terminal conversation/message payloads).
  const status = conversation.status?.toLowerCase() ?? ''
  if (
    status === 'ready' ||
    status === 'completed' ||
    status === 'failed' ||
    status === 'error' ||
    status === 'stopped' ||
    status === 'cancelled' ||
    status === 'canceled'
  ) {
    void queryClient.invalidateQueries({
      queryKey: [
        'assistant',
        'conversation-resource-stats',
        conversation.$id,
      ],
      exact: false,
    })
  }
}

/** Patch a conversation row (e.g. status) without requiring a full payload. */
export function patchAssistantConversationInCache(
  queryClient: QueryClient,
  conversationId: string,
  patch: Partial<Models.AssistantConversation>,
): void {
  queryClient.setQueriesData<Models.AssistantConversation[] | undefined>(
    { queryKey: ['assistant', 'conversations'], exact: false },
    (old) => {
      if (!old) return old
      const index = old.findIndex((entry) => entry.$id === conversationId)
      if (index < 0) return old
      const merged = { ...old[index], ...patch }
      if (conversationsEqualForList(old[index], merged)) {
        return old
      }
      const next = [...old]
      next[index] = merged
      if (
        patch.$updatedAt !== undefined &&
        old[index].$updatedAt !== merged.$updatedAt
      ) {
        return sortConversationsByUpdatedAt(next)
      }
      return next
    },
  )
}

function syncConversationStatusFromMessage(
  queryClient: QueryClient,
  message: Models.AssistantMessage,
): void {
  if (message.role !== 'assistant') return

  const status = message.status?.toLowerCase() ?? ''
  if (!status) return

  // Status-only — do not bump $updatedAt from message streams (re-sorts/flicker).
  if (isAssistantMessageInFlight(message.status)) {
    patchAssistantConversationInCache(queryClient, message.conversationId, {
      status: status === 'queued' ? 'queued' : 'running',
    })
    return
  }

  if (status === 'failed' || status === 'error') {
    patchAssistantConversationInCache(queryClient, message.conversationId, {
      status: 'failed',
    })
    return
  }

  if (status === 'stopped' || status === 'cancelled' || status === 'canceled') {
    patchAssistantConversationInCache(queryClient, message.conversationId, {
      status: 'stopped',
    })
  }

  // Terminal "ready/completed" is left to conversation realtime payloads so we
  // do not clear an in-flight conversation from a single message completion.
}

export function removeAssistantConversationFromCache(
  queryClient: QueryClient,
  conversationId: string,
): void {
  queryClient.setQueriesData<Models.AssistantConversation[] | undefined>(
    { queryKey: ['assistant', 'conversations'], exact: false },
    (old) => old?.filter((entry) => entry.$id !== conversationId),
  )
  queryClient.removeQueries({
    queryKey: ['assistant', 'messages', conversationId],
  })
  queryClient.removeQueries({
    queryKey: ['assistant', 'conversation-resource-stats', conversationId],
  })
}

function applyMessagesCacheUpdate(
  queryClient: QueryClient,
  conversationId: string,
  updater: (old: MessagesCache | undefined) => MessagesCache | undefined,
): void {
  queryClient.setQueriesData<MessagesCache | undefined>(
    { queryKey: ['assistant', 'messages', conversationId], exact: false },
    updater,
  )
  // List-row resource counters use a dedicated hydrated query; keep it in sync.
  queryClient.setQueriesData<MessagesCache | undefined>(
    {
      queryKey: ['assistant', 'conversation-resource-stats', conversationId],
      exact: false,
    },
    updater,
  )
}

export function mergeAssistantMessageIntoCache(
  queryClient: QueryClient,
  message: Models.AssistantMessage,
): void {
  applyMessagesCacheUpdate(queryClient, message.conversationId, (old) => {
    if (!old) {
      return { messages: [message], total: 1 }
    }
    const previousLength = old.messages.length
    const messages = upsertMessageInList(old.messages, message)
    const added = messages.length - previousLength
    return {
      messages,
      total: Math.max(old.total + added, messages.length),
    }
  })
}

export function mergeAssistantToolIntoCache(
  queryClient: QueryClient,
  tool: Models.AssistantTool,
): void {
  const conversationId = tool.conversationId
  if (!conversationId) {
    // Fall back to scanning all message caches for this message id.
    const updater = (old: MessagesCache | undefined) => {
      if (!old?.messages?.length) return old
      let changed = false
      const messages = old.messages.map((message) => {
        if (message.$id !== tool.messageId) return message
        changed = true
        return upsertToolOnMessage(message, tool)
      })
      return changed ? { ...old, messages } : old
    }
    queryClient.setQueriesData<MessagesCache | undefined>(
      { queryKey: ['assistant', 'messages'], exact: false },
      updater,
    )
    queryClient.setQueriesData<MessagesCache | undefined>(
      { queryKey: ['assistant', 'conversation-resource-stats'], exact: false },
      updater,
    )
    return
  }

  applyMessagesCacheUpdate(queryClient, conversationId, (old) => {
    if (!old?.messages?.length) return old
    let changed = false
    const messages = old.messages.map((message) => {
      if (message.$id !== tool.messageId) return message
      changed = true
      return upsertToolOnMessage(message, tool)
    })
    return changed ? { ...old, messages } : old
  })
}

/** Match both `assistant.messages` and compacted `assistantmessages` event forms. */
function eventMatchesAssistantResource(
  eventNames: string[],
  resource: 'conversations' | 'messages' | 'tools' | 'mcps',
): boolean {
  const compacted = `assistant${resource}`
  const dotted = `assistant.${resource}`
  return eventNames.some(
    (event) => event.includes(compacted) || event.includes(dotted),
  )
}

export function applyAssistantRealtimePayload(
  queryClient: QueryClient,
  events: string[],
  payload: unknown,
): boolean {
  if (!isRecord(payload)) return false

  const eventNames = events.map((event) => event.toLowerCase())
  const isDelete = eventNames.some((event) => event.includes('.delete'))

  if (eventMatchesAssistantResource(eventNames, 'conversations')) {
    if (isDelete && typeof payload.$id === 'string') {
      removeAssistantConversationFromCache(queryClient, payload.$id)
      void queryClient.invalidateQueries({
        queryKey: ['assistant', 'automations'],
        exact: false,
      })
      return true
    }
    const conversation = asAssistantConversation(payload)
    if (conversation) {
      mergeAssistantConversationIntoCache(queryClient, conversation)
      // Keep automation run lists in sync (conversations keyed by automationId).
      void queryClient.invalidateQueries({
        queryKey: ['assistant', 'automations'],
        exact: false,
      })
      return true
    }
  }

  if (eventMatchesAssistantResource(eventNames, 'messages')) {
    const message = asAssistantMessage(payload)
    if (message) {
      mergeAssistantMessageIntoCache(queryClient, message)
      // Keep conversation list status dots in sync when message events arrive
      // before (or instead of) a conversation payload.
      syncConversationStatusFromMessage(queryClient, message)
      return true
    }
  }

  if (eventMatchesAssistantResource(eventNames, 'tools')) {
    const tool = asAssistantTool(payload)
    if (tool) {
      mergeAssistantToolIntoCache(queryClient, tool)
      return true
    }
  }

  if (eventMatchesAssistantResource(eventNames, 'mcps')) {
    queryClient.invalidateQueries({ queryKey: ['assistant', 'mcps'] })
    return true
  }

  return false
}

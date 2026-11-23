import { useEffect, useRef } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { createConsoleRefreshHandler } from '@/lib/assistant/console-dispatch'
import { consoleToolApplyKey } from '@/lib/assistant/console-protocol'
import { collectRefreshScopesFromTools } from '@/lib/assistant/resource-refresh'
import {
  buildTurnView,
  type AssistantMessageLike,
} from '@/lib/assistant/turn-view'

const INCOMPLETE_TOOL_STATUSES = new Set([
  'running',
  'queued',
  'pending',
  'processing',
  'in_progress',
  'in-progress',
])

function isIncompleteToolStatus(status: string | null | undefined): boolean {
  return INCOMPLETE_TOOL_STATUSES.has((status ?? '').trim().toLowerCase())
}

function isSuccessfulToolStatus(status: string | null | undefined): boolean {
  const normalized = (status ?? '').trim().toLowerCase()
  return normalized === 'success' || normalized === 'completed'
}

/**
 * When agent MCP tools (or console `resource` / `refresh` actions) finish
 * successfully during this session, refetch matching React Query scopes so
 * open Console pages stay in sync.
 *
 * Mirrors `useConsoleProtocolEffects`: first observation of an already-
 * completed tool is treated as history and does not refetch.
 */
export function useAgentResourceRefreshEffects(
  messages: AssistantMessageLike[] | null | undefined,
  options?: {
    conversationId?: string | null
    projectId?: string | null
  },
) {
  const queryClient = useQueryClient()
  const conversationId = options?.conversationId
  const projectId = options?.projectId

  const toolStatusByKeyRef = useRef<Map<string, string>>(new Map())
  const refreshedToolKeysRef = useRef<Set<string>>(new Set())
  const trackedConversationRef = useRef<string | null>(null)

  useEffect(() => {
    if (!conversationId) return

    if (trackedConversationRef.current !== conversationId) {
      trackedConversationRef.current = conversationId
      toolStatusByKeyRef.current = new Map()
      refreshedToolKeysRef.current = new Set()
    }

    const newlyCompleted: Array<{
      name?: string | null
      status?: string | null
      argumentsJson?: string | null
      input?: unknown
      errorMessage?: string | null
      output?: unknown
      applyKey: string
    }> = []

    for (const message of messages ?? []) {
      const turn = buildTurnView(message)
      const messageId = turn.messageId?.trim()
      if (!messageId) continue

      for (const toolKey of turn.toolOrder) {
        const tool = turn.tools[toolKey]
        if (!tool) continue

        const applyKey = `${conversationId}:${consoleToolApplyKey(tool, {
          messageId,
        })}`
        const status = (tool.status ?? '').trim().toLowerCase() || 'unknown'
        const previousStatus = toolStatusByKeyRef.current.get(applyKey)
        toolStatusByKeyRef.current.set(applyKey, status)

        // First sighting: record only. Historical completed tools must not
        // trigger a refetch storm when opening a conversation.
        if (previousStatus === undefined) continue
        if (refreshedToolKeysRef.current.has(applyKey)) continue
        if (!isIncompleteToolStatus(previousStatus)) continue
        if (!isSuccessfulToolStatus(status)) continue

        newlyCompleted.push({ ...tool, applyKey })
      }
    }

    if (newlyCompleted.length === 0) return

    const scopes = collectRefreshScopesFromTools(newlyCompleted)
    for (const tool of newlyCompleted) {
      refreshedToolKeysRef.current.add(tool.applyKey)
    }
    if (scopes.length === 0) return

    void createConsoleRefreshHandler(queryClient, projectId)(scopes)
  }, [conversationId, messages, projectId, queryClient])
}

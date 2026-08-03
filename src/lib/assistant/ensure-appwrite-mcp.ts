/**
 * Ensure the hosted Appwrite MCP connection exists for the signed-in user.
 * Runs authorize + approve via Console APIs (no popup / consent redirect).
 */

import { useEffect, useRef } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { sdk } from '@/lib/appwrite/sdk'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import { connectMcpOAuthSilently } from '@/lib/assistant/mcp-oauth'
import {
  APPWRITE_ASSISTANT_MCP_ID,
  getAppwriteAssistantMcpConnectInput,
} from '@/lib/assistant/mcp-appwrite'
import {
  fetchAssistantMcpConnections,
  type AssistantMcpConnection,
} from '@/lib/react-query/hooks/assistant'

type EnsureResult =
  | { status: 'connected'; connection: AssistantMcpConnection }
  | { status: 'already-connected'; connection: AssistantMcpConnection }
  | { status: 'skipped' }

let ensureInFlight: Promise<EnsureResult> | null = null

function appwriteConnectionFromList(
  connections: AssistantMcpConnection[],
): AssistantMcpConnection | undefined {
  return connections.find(
    (connection) => connection.$id === APPWRITE_ASSISTANT_MCP_ID,
  )
}

async function persistSilentConnect(
  exists: boolean,
): Promise<AssistantMcpConnection> {
  const result = await connectMcpOAuthSilently(
    getAppwriteAssistantMcpConnectInput(),
  )
  const payload = {
    mcpId: result.mcpId,
    name: result.name,
    url: result.url,
    description: result.description,
    enabled: true,
    status: 'connected',
    tokens: JSON.stringify(result.tokens),
    clientInfo: JSON.stringify(result.clientInfo),
  }
  if (exists) {
    return await sdk.forConsole.agent.updateMcpConnection(payload)
  }
  try {
    return await sdk.forConsole.agent.createMcpConnection(payload)
  } catch (error) {
    const message =
      error && typeof error === 'object' && 'message' in error
        ? String((error as { message?: unknown }).message)
        : ''
    if (!/already exists|conflict|409/i.test(message)) {
      throw error
    }
    return await sdk.forConsole.agent.updateMcpConnection(payload)
  }
}

/**
 * Connect Appwrite MCP when missing tokens. Safe to call from multiple mounts;
 * concurrent callers share one in-flight promise.
 */
export async function ensureAppwriteMcpConnected(options?: {
  /** Pre-fetched list; when omitted, fetches from the Agent API. */
  connections?: AssistantMcpConnection[]
}): Promise<EnsureResult> {
  if (typeof window === 'undefined') return { status: 'skipped' }
  if (!getActiveProfileFeatures().aiAssistant) return { status: 'skipped' }

  if (ensureInFlight) return ensureInFlight

  ensureInFlight = (async (): Promise<EnsureResult> => {
    const connections =
      options?.connections ?? (await fetchAssistantMcpConnections())
    const existing = appwriteConnectionFromList(connections)

    // Tokens present means the agent backend can use MCP (enabled is a separate toggle).
    if (existing?.hasTokens) {
      return { status: 'already-connected', connection: existing }
    }

    const connection = await persistSilentConnect(!!existing)
    return { status: 'connected', connection }
  })().finally(() => {
    ensureInFlight = null
  })

  return ensureInFlight
}

/**
 * On agent load: if Appwrite MCP is not connected, connect silently in the background.
 */
export function useEnsureAppwriteMcpConnected(options?: {
  enabled?: boolean
  connections?: AssistantMcpConnection[]
  /** True once the connections query has settled (success or empty). */
  connectionsReady?: boolean
}) {
  const enabled = options?.enabled ?? true
  const queryClient = useQueryClient()
  const attemptedRef = useRef(false)

  useEffect(() => {
    if (!enabled) return
    if (options?.connectionsReady === false) return
    if (attemptedRef.current) return
    if (typeof window === 'undefined') return
    if (!getActiveProfileFeatures().aiAssistant) return

    const connections = options?.connections
    if (connections) {
      const existing = appwriteConnectionFromList(connections)
      if (existing?.hasTokens) {
        attemptedRef.current = true
        return
      }
    }

    attemptedRef.current = true
    void ensureAppwriteMcpConnected({ connections })
      .then(async (result) => {
        if (result.status === 'connected') {
          await queryClient.refetchQueries({ queryKey: ['agent', 'mcps'] })
        }
      })
      .catch(() => {
        // Allow a later mount / manual Connect to try again.
        attemptedRef.current = false
      })
  }, [
    enabled,
    options?.connections,
    options?.connectionsReady,
    queryClient,
  ])
}

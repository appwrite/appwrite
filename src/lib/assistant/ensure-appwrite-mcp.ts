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
  getAppwriteAssistantMcpUrl,
  isAppwriteMcpConnectionCurrent,
} from '@/lib/assistant/mcp-appwrite'
import { subscribeToDebugMcpEndpointChange } from '@/lib/debug-mcp-endpoint'
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
 * Connect Appwrite MCP when missing tokens or when the stored URL no longer
 * matches the effective MCP endpoint (new instance / debug override).
 * Safe to call from multiple mounts; concurrent callers share one in-flight promise.
 */
export async function ensureAppwriteMcpConnected(options?: {
  /** Pre-fetched list; when omitted, fetches from the Agent API. */
  connections?: AssistantMcpConnection[]
}): Promise<EnsureResult> {
  if (typeof window === 'undefined') return { status: 'skipped' }
  if (!getActiveProfileFeatures().agent) return { status: 'skipped' }

  if (ensureInFlight) return ensureInFlight

  ensureInFlight = (async (): Promise<EnsureResult> => {
    const connections =
      options?.connections ?? (await fetchAssistantMcpConnections())
    const existing = appwriteConnectionFromList(connections)
    const mcpUrl = getAppwriteAssistantMcpUrl()

    // Tokens for the *current* MCP URL mean the agent can use MCP.
    // A prior instance (different URL) must reconnect even if hasTokens is true.
    if (isAppwriteMcpConnectionCurrent(existing, mcpUrl)) {
      return { status: 'already-connected', connection: existing! }
    }

    const connection = await persistSilentConnect(!!existing)
    return { status: 'connected', connection }
  })().finally(() => {
    ensureInFlight = null
  })

  return ensureInFlight
}

/**
 * On agent load: if Appwrite MCP is not connected to the current endpoint,
 * connect silently in the background. Re-runs when the debug MCP URL changes.
 */
export function useEnsureAppwriteMcpConnected(options?: {
  enabled?: boolean
  connections?: AssistantMcpConnection[]
  /** True once the connections query has settled (success or empty). */
  connectionsReady?: boolean
}) {
  const enabled = options?.enabled ?? true
  const queryClient = useQueryClient()
  const attemptedForUrlRef = useRef<string | null>(null)

  useEffect(() => {
    if (!enabled) return
    if (options?.connectionsReady === false) return
    if (typeof window === 'undefined') return
    if (!getActiveProfileFeatures().agent) return

    const run = () => {
      const mcpUrl = getAppwriteAssistantMcpUrl()
      const connections = options?.connections
      if (connections) {
        const existing = appwriteConnectionFromList(connections)
        if (isAppwriteMcpConnectionCurrent(existing, mcpUrl)) {
          attemptedForUrlRef.current = mcpUrl
          return
        }
      }

      if (attemptedForUrlRef.current === mcpUrl) return
      attemptedForUrlRef.current = mcpUrl

      // Always re-fetch when reconnecting so we do not reuse a stale list
      // keyed to a previous MCP URL from the first effect run.
      void ensureAppwriteMcpConnected()
        .then(async (result) => {
          if (result.status === 'connected') {
            await queryClient.refetchQueries({ queryKey: ['agent', 'mcps'] })
          }
        })
        .catch((error) => {
          // Allow a later mount / endpoint change / manual Connect to try again.
          if (attemptedForUrlRef.current === mcpUrl) {
            attemptedForUrlRef.current = null
          }
          if (import.meta.env.DEV) {
            console.warn('[agent] Appwrite MCP auto-connect failed', error)
          }
        })
    }

    run()
    return subscribeToDebugMcpEndpointChange(run)
  }, [
    enabled,
    options?.connections,
    options?.connectionsReady,
    queryClient,
  ])
}

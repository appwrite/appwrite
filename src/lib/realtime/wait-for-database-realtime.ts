/**
 * Wait for a dedicated database realtime event during create / upgrade flows.
 * Registers temporary listeners on the shared console hubs (main + regional).
 */

import type { RealtimeResponseEvent } from '@appwrite.io/console'
import { PROJECT_CHANNELS } from './constants'
import {
  findDedicatedDatabaseScopedEvent,
  isDedicatedDatabaseLifecycleEvent,
  parseDedicatedDatabaseEvent,
  resolveDedicatedRealtimeEngineAliases,
} from './dedicated-database-cache'
import { registerConsoleRealtimeListener } from './console-hub'
import { registerRegionalConsoleRealtimeListener } from './regional-console-hub'

export type WaitForDatabaseRealtimeOptions = {
  projectId: string
  databaseId: string
  engine: string
  /** Defaults to {@link resolveDedicatedRealtimeEngineAliases}(engine). */
  engineAliases?: string[]
  /**
   * When true, resolve on database lifecycle events (`{engine}.{id}.create|update|delete`)
   * even when the payload is missing or incomplete. Callers should re-fetch status from the API.
   */
  lifecycleOnly?: boolean
  /** Extra check on the merged payload (status, replicas, etc.). Ignored when lifecycleOnly matches. */
  predicate?: (payload: Record<string, unknown>) => boolean
  timeoutMs?: number
}

function readPayload(
  response: RealtimeResponseEvent<unknown>,
): Record<string, unknown> | null {
  if (!response.payload || typeof response.payload !== 'object') return null
  return response.payload as Record<string, unknown>
}

function isForProject(
  projectId: string,
  channels: string[],
  payload: Record<string, unknown> | null,
): boolean {
  const projectChannel = `projects.${projectId}`
  if (channels.includes(projectChannel)) return true
  if (channels.some((c) => c.startsWith('projects.') && c.includes(projectId))) {
    return true
  }
  const payloadProjectId = payload?.projectId
  if (typeof payloadProjectId === 'string' && payloadProjectId === projectId) {
    return true
  }
  return false
}

function isRelevantForDatabaseWait(
  projectId: string,
  databaseId: string,
  channels: string[],
  events: string[],
  payload: Record<string, unknown> | null,
): boolean {
  if (
    events.some(
      (event) => parseDedicatedDatabaseEvent(event)?.databaseId === databaseId,
    )
  ) {
    return true
  }
  return isForProject(projectId, channels, payload)
}

/**
 * Resolves when a matching database realtime event arrives, or rejects on timeout.
 */
export async function waitForDatabaseRealtimeEvent(
  options: WaitForDatabaseRealtimeOptions,
): Promise<Record<string, unknown>> {
  const {
    projectId,
    databaseId,
    engine,
    engineAliases = resolveDedicatedRealtimeEngineAliases(engine),
    lifecycleOnly = false,
    predicate,
    timeoutMs = 9 * 60 * 1000,
  } = options

  return new Promise((resolve, reject) => {
    let settled = false
    let timeoutId: ReturnType<typeof setTimeout> | undefined
    let unregisterMain: (() => Promise<void>) | null = null
    let unregisterRegional: (() => Promise<void>) | null = null

    const finish = async (result: 'resolve' | 'reject', value?: unknown) => {
      if (settled) return
      settled = true
      if (timeoutId) clearTimeout(timeoutId)
      await Promise.all([unregisterMain?.(), unregisterRegional?.()])
      if (result === 'resolve') {
        resolve(value as Record<string, unknown>)
      } else {
        reject(value)
      }
    }

    timeoutId = setTimeout(() => {
      void finish('reject', new Error('Database operation timed out'))
    }, timeoutMs)

    const handler = (response: RealtimeResponseEvent<unknown>) => {
      const payload = readPayload(response)
      if (
        !isRelevantForDatabaseWait(
          projectId,
          databaseId,
          response.channels,
          response.events,
          payload,
        )
      ) {
        return
      }

      const lifecycleMatch = findDedicatedDatabaseScopedEvent(
        response.events,
        databaseId,
        engineAliases,
        { lifecycleOnly: true },
      )

      if (lifecycleOnly && lifecycleMatch) {
        void finish('resolve', payload ?? { $id: databaseId })
        return
      }

      const match = findDedicatedDatabaseScopedEvent(
        response.events,
        databaseId,
        engineAliases,
      )
      if (!match) return

      if (isDedicatedDatabaseLifecycleEvent(match)) {
        if (!predicate || (payload && predicate(payload))) {
          void finish('resolve', payload ?? { $id: databaseId })
        }
        return
      }

      if (predicate && payload && predicate(payload)) {
        void finish('resolve', payload)
      }
    }

    const channels = [...PROJECT_CHANNELS]

    void Promise.all([
      registerConsoleRealtimeListener(channels, handler),
      registerRegionalConsoleRealtimeListener(projectId, channels, handler),
    ])
      .then(([main, regional]) => {
        unregisterMain = main
        unregisterRegional = regional
      })
      .catch((error) => {
        void finish('reject', error)
      })
  })
}

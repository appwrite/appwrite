/**
 * Wait for a dedicated database realtime event during create / upgrade flows.
 * Registers temporary listeners on the shared console hubs (main + regional).
 */

import type { RealtimeResponseEvent } from '@appwrite.io/console'
import { PROJECT_CHANNELS } from './constants'
import {
  parseDedicatedDatabaseEvent,
  type ParsedDedicatedDatabaseEvent,
} from './dedicated-database-cache'
import { registerConsoleRealtimeListener } from './console-hub'
import { registerRegionalConsoleRealtimeListener } from './regional-console-hub'

export type WaitForDatabaseRealtimeOptions = {
  projectId: string
  databaseId: string
  engine: string
  /** Extra check on the merged payload (status, replicas, etc.). */
  predicate?: (payload: Record<string, unknown>) => boolean
  timeoutMs?: number
}

function normalizeEngine(engine: string): string {
  const key = engine.toLowerCase().trim()
  if (key === 'postgres') return 'postgresql'
  if (key === 'mongo') return 'mongodb'
  return key
}

function eventMatchesDatabaseScope(
  events: string[],
  scope: Pick<WaitForDatabaseRealtimeOptions, 'databaseId' | 'engine'>,
): ParsedDedicatedDatabaseEvent | null {
  const normalizedEngine = normalizeEngine(scope.engine)
  for (const event of events) {
    const parsed = parseDedicatedDatabaseEvent(event)
    if (!parsed) continue
    if (parsed.databaseId !== scope.databaseId) continue
    if (normalizeEngine(parsed.engine) !== normalizedEngine) continue
    return parsed
  }
  return null
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
      await Promise.all([
        unregisterMain?.(),
        unregisterRegional?.(),
      ])
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
      if (!isForProject(projectId, response.channels, payload)) return

      const match = eventMatchesDatabaseScope(response.events, {
        databaseId,
        engine,
      })
      if (!match) return
      if (predicate && payload && !predicate(payload)) return
      if (predicate && !payload) return

      void finish('resolve', payload ?? { $id: databaseId })
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

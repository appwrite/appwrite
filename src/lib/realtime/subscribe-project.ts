/**
 * Unified project-level realtime subscriptions.
 *
 * Subscribes to the console channel only (project=console), filters events by
 * current project where needed, and invalidates React Query cache so the UI updates.
 * For migration update events we merge the payload into the cache instead of
 * invalidating, to avoid many refetches during export/import progress.
 */

import type { QueryClient } from '@tanstack/react-query'
import type { RealtimeResponseEvent } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import { PROJECT_CHANNELS, REALTIME_EVENTS } from './constants'

/** Realtime payload may have statusCounters as JSON string; normalize to object */
function normalizeMigrationPayload(
  payload: Record<string, unknown>,
): Record<string, unknown> {
  const out = { ...payload }
  if (typeof out.statusCounters === 'string') {
    try {
      out.statusCounters = JSON.parse(out.statusCounters as string) as object
    } catch {
      // leave as-is
    }
  }
  if (typeof out.resourceData === 'string') {
    try {
      out.resourceData = JSON.parse(out.resourceData as string) as object
    } catch {
      // leave as-is
    }
  }
  return out
}

/**
 * Merge a migration realtime payload into all migration list caches for this project.
 * Avoids refetching on every progress event.
 */
function mergeMigrationPayloadIntoCache(
  queryClient: QueryClient,
  projectId: string,
  payload: Record<string, unknown>,
): void {
  const id = payload.$id as string | undefined
  if (!id) return

  const normalized = normalizeMigrationPayload(payload)

  queryClient.setQueriesData(
    { queryKey: ['migrations', 'project', projectId], exact: false },
    (old: unknown) => {
      const data = old as
        | { migrations?: Array<Record<string, unknown>> }
        | undefined
      if (!data?.migrations || !Array.isArray(data.migrations)) return old
      const next = data.migrations.map((m) =>
        m.$id === id ? { ...m, ...normalized } : m,
      )
      return { ...data, migrations: next }
    },
  )
}

/**
 * Merge a rule realtime payload into all proxy-rules list caches.
 * Avoids refetching on every rule status update (e.g. 18 rules → 18 refetches).
 */
function mergeRulePayloadIntoCache(
  queryClient: QueryClient,
  payload: Record<string, unknown>,
): void {
  const id = payload.$id as string | undefined
  if (!id) return

  queryClient.setQueriesData(
    { queryKey: ['proxy-rules'], exact: false },
    (old: unknown) => {
      const data = old as
        | { rules?: Array<Record<string, unknown>>; total?: number }
        | undefined
      if (!data?.rules || !Array.isArray(data.rules)) return old
      const idx = data.rules.findIndex((r) => r.$id === id)
      if (idx < 0) return old
      const next = [...data.rules]
      next[idx] = { ...next[idx], ...payload }
      return { ...data, rules: next }
    },
  )
}

/** Check if the event list includes an event that starts with the given prefix */
function eventMatches(events: string[], prefix: string): boolean {
  return events.some((e) => e === prefix || e.startsWith(prefix))
}

/** Check if the event list includes an exact or wildcard match */
function hasEvent(events: string[], name: string): boolean {
  if (events.includes(name)) return true
  if (
    name.endsWith('.*') &&
    events.some((e) => e.startsWith(name.slice(0, -2)))
  )
    return true
  return false
}

export type OnMigrationEvent = (payload: unknown) => void

/**
 * Handle a realtime event: invalidate or refetch the relevant queries.
 * For project-scoped channels we only react when the event is for the current project.
 */
function handleRealtimeEvent(
  queryClient: QueryClient,
  projectId: string,
  response: RealtimeResponseEvent<unknown>,
  onMigrationEvent?: OnMigrationEvent,
): void {
  const { events, channels } = response

  // Project-scoped filter: only react if this event is for our project
  const projectChannel = `projects.${projectId}`
  const isForThisProject =
    channels.includes(projectChannel) ||
    channels.some((c) => c.startsWith('projects.') && c.includes(projectId))

  // Console-level events (sites, functions deployments/executions) - no project filter
  if (hasEvent(events, REALTIME_EVENTS.SITES_ANY)) {
    queryClient.invalidateQueries({ queryKey: ['sites', 'project', projectId] })
  }
  if (
    hasEvent(events, REALTIME_EVENTS.SITES_DEPLOYMENT_CREATE) ||
    hasEvent(events, REALTIME_EVENTS.SITES_DEPLOYMENT_UPDATE) ||
    hasEvent(events, REALTIME_EVENTS.SITES_DEPLOYMENT_DELETE) ||
    eventMatches(events, REALTIME_EVENTS.SITES_DEPLOYMENTS_ANY)
  ) {
    queryClient.invalidateQueries({
      queryKey: ['deployments', 'site', projectId],
    })
    queryClient.invalidateQueries({
      queryKey: ['deployment', 'site', projectId],
    })
    queryClient.invalidateQueries({ queryKey: ['site', 'project', projectId] })
  }
  if (hasEvent(events, REALTIME_EVENTS.SITES_EXECUTIONS_ANY)) {
    queryClient.invalidateQueries({ queryKey: ['logs', 'site', projectId] })
  }

  if (
    hasEvent(events, REALTIME_EVENTS.FUNCTIONS_DEPLOYMENT_CREATE) ||
    hasEvent(events, REALTIME_EVENTS.FUNCTIONS_DEPLOYMENT_UPDATE) ||
    hasEvent(events, REALTIME_EVENTS.FUNCTIONS_DEPLOYMENT_DELETE) ||
    eventMatches(events, REALTIME_EVENTS.FUNCTIONS_DEPLOYMENTS_ANY)
  ) {
    queryClient.invalidateQueries({
      queryKey: ['functions', 'project', projectId],
    })
    queryClient.invalidateQueries({
      queryKey: ['function', 'project', projectId],
    })
    queryClient.invalidateQueries({
      queryKey: ['deployment', 'function', projectId],
    })
    queryClient.invalidateQueries({
      queryKey: ['deployments', 'function', projectId],
    })
  }
  if (hasEvent(events, REALTIME_EVENTS.FUNCTIONS_EXECUTIONS_ANY)) {
    queryClient.invalidateQueries({
      queryKey: ['executions', 'function', projectId],
    })
  }

  // Migration events: always process (merge by $id only updates if in current project's list)
  if (hasEvent(events, REALTIME_EVENTS.MIGRATIONS_ANY)) {
    const payload = response.payload
    if (
      payload != null &&
      typeof payload === 'object' &&
      payload !== null &&
      '$id' in payload
    ) {
      mergeMigrationPayloadIntoCache(
        queryClient,
        projectId,
        payload as Record<string, unknown>,
      )
      onMigrationEvent?.(payload)
    } else {
      queryClient.invalidateQueries({
        queryKey: ['migrations', 'project', projectId],
      })
      queryClient.invalidateQueries({
        queryKey: ['migration', 'project', projectId],
      })
    }
  }

  // Project-scoped events: only invalidate if the event is for this project
  if (!isForThisProject) return

  if (
    eventMatches(events, REALTIME_EVENTS.DATABASES_TABLES_COLUMNS_ANY) ||
    hasEvent(events, REALTIME_EVENTS.DATABASES_TABLES_COLUMNS_CREATE) ||
    hasEvent(events, REALTIME_EVENTS.DATABASES_TABLES_COLUMNS_UPDATE) ||
    hasEvent(events, REALTIME_EVENTS.DATABASES_TABLES_COLUMNS_DELETE)
  ) {
    queryClient.invalidateQueries({
      queryKey: ['columns', 'project', projectId],
    })
    queryClient.invalidateQueries({
      queryKey: ['tables', 'project', projectId],
    })
    queryClient.invalidateQueries({ queryKey: ['table', 'project', projectId] })
  }
  if (hasEvent(events, REALTIME_EVENTS.DATABASES_TABLES_INDEXES_ANY)) {
    queryClient.invalidateQueries({
      queryKey: ['indexes', 'project', projectId],
    })
    queryClient.invalidateQueries({ queryKey: ['table', 'project', projectId] })
  }
  if (hasEvent(events, REALTIME_EVENTS.DATABASES_TABLES_COLUMNS_ANY)) {
    queryClient.invalidateQueries({
      queryKey: ['databases', 'project', projectId],
    })
  }

  if (hasEvent(events, REALTIME_EVENTS.ARCHIVES_ANY)) {
    queryClient.invalidateQueries({
      queryKey: ['backup-archives', 'project', projectId],
    })
  }
  if (hasEvent(events, REALTIME_EVENTS.RESTORATIONS_ANY)) {
    queryClient.invalidateQueries({
      queryKey: ['restorations', 'project', projectId],
    })
  }
  if (hasEvent(events, REALTIME_EVENTS.POLICIES_ANY)) {
    queryClient.invalidateQueries({
      queryKey: ['backup-policies', 'project', projectId],
    })
  }

  if (events.includes(`projects.${projectId}.ping`)) {
    queryClient.invalidateQueries({ queryKey: ['project', projectId] })
  }

  if (hasEvent(events, REALTIME_EVENTS.STATS_CONNECTIONS)) {
    queryClient.invalidateQueries({ queryKey: ['project', projectId] })
  }

  if (hasEvent(events, REALTIME_EVENTS.RULES_UPDATE)) {
    const payload = response.payload
    if (
      payload != null &&
      typeof payload === 'object' &&
      payload !== null &&
      '$id' in payload
    ) {
      // Merge updated rule into proxy-rules list caches so status updates in real
      // time without refetching. Avoids N refetches when N rules emit updates.
      mergeRulePayloadIntoCache(queryClient, payload as Record<string, unknown>)
      // Update single-rule cache if it exists
      const ruleId = (payload as Record<string, unknown>).$id as string
      queryClient.setQueriesData(
        { queryKey: ['proxy-rule'], exact: false },
        (old: unknown) => {
          const data = old as Record<string, unknown> | undefined
          if (!data || data.$id !== ruleId) return old
          return { ...data, ...payload }
        },
      )
    } else {
      // No payload (e.g. delete) – invalidate so lists refetch
      queryClient.invalidateQueries({ queryKey: ['proxy-rules'] })
      queryClient.invalidateQueries({ queryKey: ['proxy-rule'] })
    }
  }
}

export type RealtimeSubscriptionCleanup = () => Promise<void>

// Module-level lock: next subscriber waits on tail until we call resolveNext()
// (in cleanup), so only one subscription is ever active and we never open
// duplicate WebSockets.
let tail: Promise<void> = Promise.resolve()

export interface SubscribeProjectRealtimeOptions {
  onMigrationEvent?: OnMigrationEvent
}

/**
 * Subscribe to realtime events for the given project.
 * Uses the console client (project=console) with channel ['console'] only.
 * Events are filtered by projectId in the handler.
 *
 * Call the returned cleanup when the component unmounts or projectId changes.
 * Only one subscription is active at a time; overlapping calls wait for the
 * previous subscription to close first.
 */
export async function subscribeProjectRealtime(
  projectId: string,
  queryClient: QueryClient,
  options?: SubscribeProjectRealtimeOptions,
): Promise<RealtimeSubscriptionCleanup> {
  const { onMigrationEvent } = options ?? {}
  const handler = (response: RealtimeResponseEvent<unknown>) => {
    handleRealtimeEvent(queryClient, projectId, response, onMigrationEvent)
  }

  // Acquire lock: next caller will wait on our release (resolveNext in cleanup)
  const previousTail = tail
  let resolveNext!: () => void
  const releasePromise = new Promise<void>((r) => {
    resolveNext = r
  })
  tail = previousTail.then(() => releasePromise)
  await previousTail

  // Single connection: console client (project=console) with ['console'] only
  const consoleRealtime = sdk.getConsoleRealtime()
  const sub = await consoleRealtime.subscribe(
    [...PROJECT_CHANNELS],
    handler as (event: {
      events: string[]
      channels: string[]
      payload: unknown
    }) => void,
  )

  return async function cleanup() {
    await sub.close()
    resolveNext()
  }
}

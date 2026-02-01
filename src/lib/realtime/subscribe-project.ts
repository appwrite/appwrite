/**
 * Unified project-level realtime subscriptions.
 *
 * Subscribes to console and project channels, filters events by current project
 * where needed, and invalidates React Query cache so the UI updates.
 */

import type { QueryClient } from '@tanstack/react-query'
import type { RealtimeResponseEvent } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import { PROJECT_CHANNELS, REALTIME_EVENTS } from './constants'

/** Check if the event list includes an event that starts with the given prefix */
function eventMatches(events: string[], prefix: string): boolean {
  return events.some((e) => e === prefix || e.startsWith(prefix))
}

/** Check if the event list includes an exact or wildcard match */
function hasEvent(events: string[], name: string): boolean {
  if (events.includes(name)) return true
  if (name.endsWith('.*') && events.some((e) => e.startsWith(name.slice(0, -2)))) return true
  return false
}

/**
 * Handle a realtime event: invalidate or refetch the relevant queries.
 * For project-scoped channels we only react when the event is for the current project.
 */
function handleRealtimeEvent(
  queryClient: QueryClient,
  projectId: string,
  response: RealtimeResponseEvent<unknown>,
): void {
  const { events, channels } = response

  // Project-scoped filter: only react if this event is for our project
  const projectChannel = `projects.${projectId}`
  const isForThisProject =
    channels.includes(projectChannel) || channels.some((c) => c.startsWith('projects.') && c.includes(projectId))

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
    queryClient.invalidateQueries({ queryKey: ['deployments', 'site', projectId] })
    queryClient.invalidateQueries({ queryKey: ['deployment', 'site', projectId] })
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
    queryClient.invalidateQueries({ queryKey: ['functions', 'project', projectId] })
    queryClient.invalidateQueries({ queryKey: ['function', 'project', projectId] })
    queryClient.invalidateQueries({ queryKey: ['deployment', 'function', projectId] })
    queryClient.invalidateQueries({ queryKey: ['deployments', 'function', projectId] })
  }
  if (hasEvent(events, REALTIME_EVENTS.FUNCTIONS_EXECUTIONS_ANY)) {
    queryClient.invalidateQueries({ queryKey: ['executions', 'function', projectId] })
  }

  // Project-scoped events: only invalidate if the event is for this project
  if (!isForThisProject) return

  if (
    eventMatches(events, REALTIME_EVENTS.DATABASES_TABLES_COLUMNS_ANY) ||
    hasEvent(events, REALTIME_EVENTS.DATABASES_TABLES_COLUMNS_CREATE) ||
    hasEvent(events, REALTIME_EVENTS.DATABASES_TABLES_COLUMNS_UPDATE) ||
    hasEvent(events, REALTIME_EVENTS.DATABASES_TABLES_COLUMNS_DELETE)
  ) {
    queryClient.invalidateQueries({ queryKey: ['columns', 'project', projectId] })
    queryClient.invalidateQueries({ queryKey: ['tables', 'project', projectId] })
    queryClient.invalidateQueries({ queryKey: ['table', 'project', projectId] })
  }
  if (hasEvent(events, REALTIME_EVENTS.DATABASES_TABLES_INDEXES_ANY)) {
    queryClient.invalidateQueries({ queryKey: ['indexes', 'project', projectId] })
    queryClient.invalidateQueries({ queryKey: ['table', 'project', projectId] })
  }
  if (hasEvent(events, REALTIME_EVENTS.DATABASES_TABLES_COLUMNS_ANY)) {
    queryClient.invalidateQueries({ queryKey: ['databases', 'project', projectId] })
  }

  if (hasEvent(events, REALTIME_EVENTS.ARCHIVES_ANY)) {
    queryClient.invalidateQueries({ queryKey: ['backup-archives', 'project', projectId] })
  }
  if (hasEvent(events, REALTIME_EVENTS.RESTORATIONS_ANY)) {
    queryClient.invalidateQueries({ queryKey: ['restorations', 'project', projectId] })
  }
  if (hasEvent(events, REALTIME_EVENTS.POLICIES_ANY)) {
    queryClient.invalidateQueries({ queryKey: ['backup-policies', 'project', projectId] })
  }

  if (hasEvent(events, REALTIME_EVENTS.MIGRATIONS_ANY)) {
    queryClient.invalidateQueries({ queryKey: ['migrations', 'project', projectId] })
    queryClient.invalidateQueries({ queryKey: ['migration', 'project', projectId] })
  }

  if (events.includes(`projects.${projectId}.ping`)) {
    queryClient.invalidateQueries({ queryKey: ['project', projectId] })
  }

  if (hasEvent(events, REALTIME_EVENTS.STATS_CONNECTIONS)) {
    queryClient.invalidateQueries({ queryKey: ['project', projectId] })
  }

  if (hasEvent(events, REALTIME_EVENTS.RULES_UPDATE)) {
    queryClient.invalidateQueries({ queryKey: ['proxy-rules', 'project', projectId] })
    queryClient.invalidateQueries({ queryKey: ['proxy-rule', 'project', projectId] })
  }
}

export type RealtimeSubscriptionCleanup = () => Promise<void>

// Module-level lock: next subscriber waits on tail until we call resolveNext()
// (in cleanup), so only one subscription is ever active and we never open
// duplicate WebSockets.
let tail: Promise<void> = Promise.resolve()

/**
 * Subscribe to realtime events for the given project.
 * Uses the console client (project=console) with channels ['project', 'console'],
 * matching the backend expectation. Events are filtered by projectId in the handler.
 *
 * Call the returned cleanup when the component unmounts or projectId changes.
 * Only one subscription is active at a time; overlapping calls wait for the
 * previous subscription to close first.
 */
export async function subscribeProjectRealtime(
  projectId: string,
  queryClient: QueryClient,
): Promise<RealtimeSubscriptionCleanup> {
  const handler = (response: RealtimeResponseEvent<unknown>) => {
    handleRealtimeEvent(queryClient, projectId, response)
  }

  // Acquire lock: next caller will wait on our release (resolveNext in cleanup)
  const previousTail = tail
  let resolveNext!: () => void
  const releasePromise = new Promise<void>((r) => {
    resolveNext = r
  })
  tail = previousTail.then(() => releasePromise)
  await previousTail

  // Single connection: console client (project=console) with ['project', 'console']
  const consoleRealtime = sdk.getConsoleRealtime()
  const sub = await consoleRealtime.subscribe(
    [...PROJECT_CHANNELS],
    handler as (event: { events: string[]; channels: string[]; payload: unknown }) => void,
  )

  return async function cleanup() {
    await sub.close()
    resolveNext()
  }
}

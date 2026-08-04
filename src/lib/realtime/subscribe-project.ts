/**
 * Unified project-level realtime subscriptions.
 *
 * Subscribes twice to the console channel (project=console): the main cloud endpoint
 * via the shared console hub, and the project's regional API host via
 * {@link ./regional-console-hub.ts} when that URL differs from the base. Events from
 * either socket are handled the same way. Filters by current project where needed and
 * invalidates React Query cache. For migration updates we merge into cache instead of
 * invalidating on every tick.
 */

import type { QueryClient } from '@tanstack/react-query'
import type { RealtimeResponseEvent } from '@appwrite.io/console'
import { refetchProjectTableRelatedQueries } from '@/lib/react-query/hooks/databases'
import { PROJECT_CHANNELS, REALTIME_EVENTS } from './constants'
import { registerConsoleRealtimeListener } from './console-hub'
import { registerRegionalConsoleRealtimeListener } from './regional-console-hub'

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

  // Single-migration caches from migrations.get (e.g. backup restore progress)
  queryClient.setQueriesData(
    { queryKey: ['migration', 'project', projectId], exact: false },
    (old: unknown) => {
      if (!old || typeof old !== 'object') return old
      const migration = old as Record<string, unknown>
      if (migration.$id !== id) return old
      return { ...migration, ...normalized }
    },
  )
}

type DeploymentResourceType = 'site' | 'function'

/**
 * Merge a deployment realtime payload into list and detail caches.
 * Keeps build duration/status in sync when the deployments list is unmounted
 * (e.g. user is on the deployment detail page while a build finishes).
 */
function mergeDeploymentPayloadIntoCache(
  queryClient: QueryClient,
  projectId: string,
  resourceType: DeploymentResourceType,
  payload: Record<string, unknown>,
): boolean {
  const id = payload.$id as string | undefined
  if (!id) return false

  const resourceId = payload.resourceId as string | undefined
  if (!resourceId) return false

  const listPrefix =
    resourceType === 'site'
      ? ['deployments', 'site', projectId, resourceId]
      : ['deployments', 'function', projectId, resourceId]

  let mergedInList = false

  queryClient.setQueriesData(
    { queryKey: listPrefix, exact: false },
    (old: unknown) => {
      const data = old as
        | { deployments?: Array<Record<string, unknown>>; total?: number }
        | undefined
      if (!data?.deployments || !Array.isArray(data.deployments)) return old
      const idx = data.deployments.findIndex((d) => d.$id === id)
      if (idx < 0) return old
      mergedInList = true
      const next = [...data.deployments]
      next[idx] = { ...next[idx], ...payload }
      return { ...data, deployments: next }
    },
  )

  const detailKey =
    resourceType === 'site'
      ? ['deployment', 'site', projectId, resourceId, id]
      : ['deployment', 'function', projectId, resourceId, id]

  queryClient.setQueryData(detailKey, (old: unknown) => {
    if (!old || typeof old !== 'object') return payload
    return { ...(old as Record<string, unknown>), ...payload }
  })

  return mergedInList
}

function refetchDeploymentQueries(
  queryClient: QueryClient,
  projectId: string,
  resourceType: DeploymentResourceType,
  resourceId?: string,
): void {
  const listKey = resourceId
    ? resourceType === 'site'
      ? ['deployments', 'site', projectId, resourceId]
      : ['deployments', 'function', projectId, resourceId]
    : resourceType === 'site'
      ? ['deployments', 'site', projectId]
      : ['deployments', 'function', projectId]
  const detailKey = resourceId
    ? resourceType === 'site'
      ? ['deployment', 'site', projectId, resourceId]
      : ['deployment', 'function', projectId, resourceId]
    : resourceType === 'site'
      ? ['deployment', 'site', projectId]
      : ['deployment', 'function', projectId]

  // Lists use refetchOnMount: false; refetch so active views update immediately.
  void queryClient.refetchQueries({ queryKey: listKey, exact: false })
  void queryClient.refetchQueries({ queryKey: detailKey, exact: false })
}

function isDeploymentDeleteEvent(events: string[]): boolean {
  return (
    hasEvent(events, REALTIME_EVENTS.SITES_DEPLOYMENT_DELETE) ||
    hasEvent(events, REALTIME_EVENTS.FUNCTIONS_DEPLOYMENT_DELETE) ||
    events.some((e) => e.includes('.deployments.') && e.endsWith('.delete'))
  )
}

function isDeploymentCreateEvent(events: string[]): boolean {
  return (
    hasEvent(events, REALTIME_EVENTS.SITES_DEPLOYMENT_CREATE) ||
    hasEvent(events, REALTIME_EVENTS.FUNCTIONS_DEPLOYMENT_CREATE) ||
    events.some((e) => e.includes('.deployments.') && e.endsWith('.create'))
  )
}

function extractDeploymentResourceIdFromEvents(
  events: string[],
  resourceType: DeploymentResourceType,
): string | undefined {
  const prefix = resourceType === 'site' ? 'sites.' : 'functions.'
  for (const event of events) {
    const match = event.match(new RegExp(`^${prefix}([^.]+)\\.deployments\\.`))
    const id = match?.[1]
    if (id && id !== '*') return id
  }
  return undefined
}

function handleDeploymentRealtimeEvent(
  queryClient: QueryClient,
  projectId: string,
  resourceType: DeploymentResourceType,
  events: string[],
  payload: Record<string, unknown> | null,
  parentQueryKey: string[],
): void {
  const isCreate = isDeploymentCreateEvent(events)
  const isDelete = isDeploymentDeleteEvent(events)
  const resourceId =
    (payload?.resourceId as string | undefined) ??
    extractDeploymentResourceIdFromEvents(events, resourceType)
  const canMerge =
    payload != null &&
    typeof payload.$id === 'string' &&
    !!resourceId &&
    !isCreate &&
    !isDelete

  let mergedInList = false
  if (canMerge) {
    mergedInList = mergeDeploymentPayloadIntoCache(
      queryClient,
      projectId,
      resourceType,
      {
        ...payload,
        resourceId,
      },
    )
  }

  // Refetch when the list cannot be patched in place: new builds, deletes, or
  // updates for deployments that are not in the cached page (common when a build
  // starts and the row has not appeared yet).
  if (isCreate || isDelete || !canMerge || !mergedInList) {
    refetchDeploymentQueries(queryClient, projectId, resourceType, resourceId)
  }

  queryClient.invalidateQueries({ queryKey: parentQueryKey })
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

/** Parse `databases.{databaseId}.tables.{tableId}.…` from realtime event names. */
function extractDatabaseIdFromRealtimeEvents(events: string[]): string | undefined {
  for (const event of events) {
    const match = event.match(/^databases\.([^.]+)\.tables\./)
    const id = match?.[1]
    if (id && id !== '*') return id
  }
  return undefined
}

function extractTableIdFromRealtimeEvents(events: string[]): string | undefined {
  for (const event of events) {
    const match = event.match(/^databases\.[^.]+\.tables\.([^.]+)\./)
    const id = match?.[1]
    if (id && id !== '*') return id
  }
  return undefined
}

function tableScopeFromRealtime(
  events: string[],
  payload: Record<string, unknown> | null,
): { databaseId?: string; tableId?: string } {
  const databaseId =
    (typeof payload?.databaseId === 'string' && payload.databaseId) ||
    (typeof payload?.$databaseId === 'string' && payload.$databaseId) ||
    extractDatabaseIdFromRealtimeEvents(events)
  const tableId =
    (typeof payload?.tableId === 'string' && payload.tableId) ||
    (typeof payload?.$tableId === 'string' && payload.$tableId) ||
    (typeof payload?.collectionId === 'string' && payload.collectionId) ||
    extractTableIdFromRealtimeEvents(events)
  return { databaseId, tableId }
}

function invalidateAssistantQueries(
  queryClient: QueryClient,
  payload: Record<string, unknown> | null,
): void {
  // Keep this module free of assistant UI imports (avoids SSR circular deps via
  // routeTree → AgentChat → @/lib/realtime → subscribe-project). Live merge for the
  // chat panel happens in AgentChat via applyAssistantRealtimePayload.
  const conversationId = payload?.conversationId as string | undefined

  queryClient.invalidateQueries({ queryKey: ['agent', 'conversations'] })

  if (conversationId) {
    queryClient.invalidateQueries({
      queryKey: ['agent', 'messages', conversationId],
    })
  } else {
    queryClient.invalidateQueries({ queryKey: ['agent', 'messages'] })
  }
}

function isAgentRealtimeSignal(values: string[]): boolean {
  return values.some((value) => {
    const lower = value.toLowerCase()
    return (
      lower.includes('agentconversation') ||
      lower.includes('agentmessage') ||
      lower.includes('agenttool') ||
      lower.includes('agentmcp') ||
      lower.includes('agentmodel') ||
      lower.includes('agentautomation') ||
      lower.includes('agent.conversation') ||
      lower.includes('agent.message') ||
      lower.includes('agent.tool') ||
      lower.includes('agent.mcp') ||
      lower.includes('agent.model') ||
      lower.includes('agent.automation') ||
      // Legacy Assistant product names (pre-rename).
      lower.includes('assistant')
    )
  })
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
  const payload =
    response.payload && typeof response.payload === 'object'
      ? (response.payload as Record<string, unknown>)
      : null

  const hasAssistantEvent = isAgentRealtimeSignal(events) || isAgentRealtimeSignal(channels)

  if (hasAssistantEvent) {
    invalidateAssistantQueries(queryClient, payload)
  }

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
    handleDeploymentRealtimeEvent(
      queryClient,
      projectId,
      'site',
      events,
      payload,
      ['site', 'project', projectId],
    )
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
    handleDeploymentRealtimeEvent(
      queryClient,
      projectId,
      'function',
      events,
      payload,
      ['function', 'project', projectId],
    )
  }
  if (hasEvent(events, REALTIME_EVENTS.FUNCTIONS_EXECUTIONS_ANY)) {
    queryClient.invalidateQueries({
      queryKey: ['executions', 'function', projectId],
    })
  }

  // Migration events: always process (merge by $id only updates if in current project's list)
  if (hasEvent(events, REALTIME_EVENTS.MIGRATIONS_ANY)) {
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
    const { databaseId, tableId } = tableScopeFromRealtime(events, payload)
    if (databaseId && tableId) {
      void refetchProjectTableRelatedQueries(
        queryClient,
        projectId,
        databaseId,
        tableId,
      )
    } else {
      void queryClient.refetchQueries({
        queryKey: ['columns', 'project', projectId],
        type: 'all',
      })
      void queryClient.refetchQueries({
        queryKey: ['tables', 'project', projectId],
        type: 'active',
      })
      void queryClient.refetchQueries({
        queryKey: ['table', 'project', projectId],
        type: 'active',
      })
    }
  }
  if (hasEvent(events, REALTIME_EVENTS.DATABASES_TABLES_INDEXES_ANY)) {
    void queryClient.refetchQueries({
      queryKey: ['indexes', 'project', projectId],
      type: 'all',
    })
    void queryClient.refetchQueries({
      queryKey: ['table', 'project', projectId],
      type: 'active',
    })
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
    queryClient.invalidateQueries({
      queryKey: ['dedicated-backup-policies', 'project', projectId],
    })
  }

  if (events.includes(`projects.${projectId}.ping`)) {
    queryClient.invalidateQueries({ queryKey: ['project', projectId] })
  }

  if (hasEvent(events, REALTIME_EVENTS.STATS_CONNECTIONS)) {
    queryClient.invalidateQueries({ queryKey: ['project', projectId] })
  }

  if (hasEvent(events, REALTIME_EVENTS.RULES_UPDATE)) {
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

export interface SubscribeProjectRealtimeOptions {
  onMigrationEvent?: OnMigrationEvent
}

/**
 * Subscribe to realtime events for the given project.
 * Uses the shared console hub on the main endpoint plus a regional console socket when
 * the project's API host differs (multi-region cloud).
 *
 * Call the returned cleanup when the component unmounts or projectId changes.
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

  const channels = [...PROJECT_CHANNELS]
  const unregisterMain = await registerConsoleRealtimeListener(channels, handler)
  const unregisterRegional =
    await registerRegionalConsoleRealtimeListener(projectId, channels, handler)

  return async () => {
    await Promise.all([unregisterRegional(), unregisterMain()])
  }
}

/**
 * React Query hooks for runtime logs: the lines a function, site or dedicated
 * database wrote to stdout and stderr (Cloud `/v1/logs`). A null resource type
 * reads every resource in the project.
 */

import { infiniteQueryOptions, queryOptions } from '@tanstack/react-query'
import { Query, type Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import {
  fillRuntimeLogVolume,
  runtimeLogUsageInterval,
  type RuntimeLogUsageBucket,
} from '@/lib/runtime-log-usage'
import { nextCursorAfter } from './cursor'

export type RuntimeLogResourceType = 'functions' | 'sites' | 'databases'

/** One resource, or `null` for every resource in the project. */
export type RuntimeLogScope = RuntimeLogResourceType | null

export type RuntimeLogStream = 'all' | 'stdout' | 'stderr'

/** Look-back windows offered in the viewer. `retention` sends no lower bound. */
export const RUNTIME_LOG_RANGES = {
  '15m': 15 * 60 * 1000,
  '1h': 60 * 60 * 1000,
  '6h': 6 * 60 * 60 * 1000,
  '24h': 24 * 60 * 60 * 1000,
  retention: null,
} as const satisfies Record<string, number | null>

export type RuntimeLogRange = keyof typeof RUNTIME_LOG_RANGES

export const RUNTIME_LOGS_PAGE_LIMIT = 100

export interface RuntimeLogsFilter {
  /** Required for functions and sites; ignored for databases and the project. */
  deploymentId?: string
  /** Narrows a project-wide list to one kind of resource. */
  resourceType?: RuntimeLogResourceType
  /** Narrows a project-wide list to one resource of `resourceType`. */
  resourceId?: string
  stream: RuntimeLogStream
  range: RuntimeLogRange
  /** A window picked on the volume chart; replaces `range` while set. */
  window?: RuntimeLogWindow
}

/** ISO timestamps, both inclusive. */
export interface RuntimeLogWindow {
  from: string
  to: string
}

function filterQueries(
  resourceType: RuntimeLogScope,
  filter: RuntimeLogsFilter,
): string[] {
  const queries: string[] = []
  if (resourceType === null) {
    if (filter.resourceType) {
      queries.push(Query.equal('resourceType', [filter.resourceType]))
      if (filter.resourceId) {
        queries.push(Query.equal('resourceId', [filter.resourceId]))
      }
    }
  } else if (resourceType !== 'databases' && filter.deploymentId) {
    queries.push(Query.equal('deploymentId', [filter.deploymentId]))
  }
  if (filter.stream !== 'all') {
    queries.push(Query.equal('stream', [filter.stream]))
  }
  return queries
}

/** The filter's time bounds, resolved against the clock now. */
function timeBounds(filter: RuntimeLogsFilter): {
  from: Date | null
  to: Date | null
} {
  if (filter.window) {
    return {
      from: new Date(filter.window.from),
      to: new Date(filter.window.to),
    }
  }
  const span = RUNTIME_LOG_RANGES[filter.range]
  return { from: span === null ? null : new Date(Date.now() - span), to: null }
}

function rangeQuery(filter: RuntimeLogsFilter): string[] {
  const { from, to } = timeBounds(filter)
  const queries: string[] = []
  if (from)
    queries.push(Query.greaterThanEqual('timestamp', from.toISOString()))
  if (to) queries.push(Query.lessThanEqual('timestamp', to.toISOString()))
  return queries
}

/** True when the filter names everything the API needs to answer. */
export function canListRuntimeLogs(
  projectId: string | null | undefined,
  resourceType: RuntimeLogScope,
  resourceId: string | null | undefined,
  filter: RuntimeLogsFilter,
): boolean {
  if (!projectId) return false
  if (resourceType === null) return true
  if (!resourceId) return false
  return resourceType === 'databases' || !!filter.deploymentId
}

/** A project-wide list leaves out the resource. */
async function listRuntimeLogs(
  projectId: string,
  resourceType: RuntimeLogScope,
  resourceId: string | null | undefined,
  queries: string[],
): Promise<Models.RuntimeLog[]> {
  const response = await sdk.forProject(projectId).logs.list({
    ...(resourceType !== null ? { resourceType, resourceId: resourceId! } : {}),
    queries,
    total: false,
  })
  return response.logs ?? []
}

/**
 * Newest first, one cursor page at a time. The look-back window is resolved
 * when a page is fetched, so a refetch moves it forward with the clock.
 */
export function runtimeLogsInfiniteQueryOptions(
  projectId: string | null | undefined,
  resourceType: RuntimeLogScope,
  resourceId: string | null | undefined,
  filter: RuntimeLogsFilter,
) {
  return infiniteQueryOptions({
    queryKey: ['runtime-logs', projectId, resourceType, resourceId, filter],
    queryFn: async ({ pageParam }) => {
      const queries = [
        ...filterQueries(resourceType, filter),
        ...rangeQuery(filter),
        Query.orderDesc('timestamp'),
        Query.limit(RUNTIME_LOGS_PAGE_LIMIT),
      ]
      if (pageParam) queries.push(Query.cursorAfter(pageParam))
      const logs = await listRuntimeLogs(
        projectId!,
        resourceType,
        resourceId,
        queries,
      )
      return { logs }
    },
    initialPageParam: undefined as string | undefined,
    getNextPageParam: (lastPage) =>
      nextCursorAfter(lastPage.logs, RUNTIME_LOGS_PAGE_LIMIT),
    enabled: canListRuntimeLogs(projectId, resourceType, resourceId, filter),
    retry: false,
    refetchOnWindowFocus: false,
  })
}

/**
 * Lines at or after `since`, oldest first, for live tail. Rows sharing the
 * newest shown timestamp come back again; callers drop them by `$id`.
 */
export async function fetchRuntimeLogsSince(
  projectId: string,
  resourceType: RuntimeLogScope,
  resourceId: string | null | undefined,
  filter: RuntimeLogsFilter,
  since: string,
): Promise<Models.RuntimeLog[]> {
  return listRuntimeLogs(projectId, resourceType, resourceId, [
    ...filterQueries(resourceType, filter),
    Query.greaterThanEqual('timestamp', since),
    Query.orderAsc('timestamp'),
    Query.limit(RUNTIME_LOGS_PAGE_LIMIT),
  ])
}

/**
 * Lines per time bucket for the volume chart, with one bar per bucket from the
 * start of the filter's window to its end (now, for a look-back range).
 */
export function runtimeLogsUsageQueryOptions(
  projectId: string | null | undefined,
  resourceType: RuntimeLogScope,
  resourceId: string | null | undefined,
  filter: RuntimeLogsFilter,
) {
  return queryOptions({
    queryKey: [
      'runtime-logs-usage',
      projectId,
      resourceType,
      resourceId,
      filter,
    ],
    queryFn: async () => {
      const { from, to } = timeBounds(filter)
      const end = to ?? new Date()
      const span = from ? end.getTime() - from.getTime() : null
      const interval = runtimeLogUsageInterval(span)
      const queries = [
        ...filterQueries(resourceType, filter),
        ...rangeQuery(filter),
      ]
      const usage = await getRuntimeLogUsage(
        projectId!,
        resourceType,
        resourceId,
        queries,
        interval,
      )
      return {
        interval,
        total: usage.linesTotal,
        points: fillRuntimeLogVolume(usage.buckets, from, end, interval),
      }
    },
    enabled: canListRuntimeLogs(projectId, resourceType, resourceId, filter),
    retry: false,
    refetchOnWindowFocus: false,
  })
}

interface RuntimeLogUsage {
  interval: string
  linesTotal: number
  buckets: RuntimeLogUsageBucket[]
}

/** Like the list, a project-wide count leaves out the resource. */
async function getRuntimeLogUsage(
  projectId: string,
  resourceType: RuntimeLogScope,
  resourceId: string | null | undefined,
  queries: string[],
  interval: string,
): Promise<RuntimeLogUsage> {
  const response: Partial<RuntimeLogUsage> = await sdk
    .forProject(projectId)
    .logs.getUsage({
      ...(resourceType !== null
        ? { resourceType, resourceId: resourceId! }
        : {}),
      queries,
      interval,
    })
  return {
    interval: response.interval ?? interval,
    linesTotal: response.linesTotal ?? 0,
    buckets: response.buckets ?? [],
  }
}

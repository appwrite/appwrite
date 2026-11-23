/**
 * React Query hooks for runtime logs: the lines a function, site or dedicated
 * database wrote to stdout and stderr (Cloud `/v1/logs`).
 */

import { infiniteQueryOptions } from '@tanstack/react-query'
import { Query, type Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import { nextCursorAfter } from './cursor'

export type RuntimeLogResourceType = 'functions' | 'sites' | 'databases'

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
  /** Required for functions and sites; ignored for databases. */
  deploymentId?: string
  stream: RuntimeLogStream
  range: RuntimeLogRange
}

function filterQueries(
  resourceType: RuntimeLogResourceType,
  filter: RuntimeLogsFilter,
): string[] {
  const queries: string[] = []
  if (resourceType !== 'databases' && filter.deploymentId) {
    queries.push(Query.equal('deploymentId', [filter.deploymentId]))
  }
  if (filter.stream !== 'all') {
    queries.push(Query.equal('stream', [filter.stream]))
  }
  return queries
}

function rangeQuery(range: RuntimeLogRange): string[] {
  const window = RUNTIME_LOG_RANGES[range]
  if (window === null) return []
  return [
    Query.greaterThanEqual(
      'timestamp',
      new Date(Date.now() - window).toISOString(),
    ),
  ]
}

/** True when the filter names everything the API needs to answer. */
export function canListRuntimeLogs(
  projectId: string | null | undefined,
  resourceType: RuntimeLogResourceType,
  resourceId: string | null | undefined,
  filter: RuntimeLogsFilter,
): boolean {
  if (!projectId || !resourceId) return false
  return resourceType === 'databases' || !!filter.deploymentId
}

/**
 * Newest first, one cursor page at a time. The look-back window is resolved
 * when a page is fetched, so a refetch moves it forward with the clock.
 */
export function runtimeLogsInfiniteQueryOptions(
  projectId: string | null | undefined,
  resourceType: RuntimeLogResourceType,
  resourceId: string | null | undefined,
  filter: RuntimeLogsFilter,
) {
  return infiniteQueryOptions({
    queryKey: ['runtime-logs', projectId, resourceType, resourceId, filter],
    queryFn: async ({ pageParam }) => {
      const queries = [
        ...filterQueries(resourceType, filter),
        ...rangeQuery(filter.range),
        Query.orderDesc('timestamp'),
        Query.limit(RUNTIME_LOGS_PAGE_LIMIT),
      ]
      if (pageParam) queries.push(Query.cursorAfter(pageParam))
      const response = await sdk.forProject(projectId!).logs.list({
        resourceType,
        resourceId: resourceId!,
        queries,
        total: false,
      })
      return { logs: response.logs ?? [] }
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
  resourceType: RuntimeLogResourceType,
  resourceId: string,
  filter: RuntimeLogsFilter,
  since: string,
): Promise<Models.RuntimeLog[]> {
  const response = await sdk.forProject(projectId).logs.list({
    resourceType,
    resourceId,
    queries: [
      ...filterQueries(resourceType, filter),
      Query.greaterThanEqual('timestamp', since),
      Query.orderAsc('timestamp'),
      Query.limit(RUNTIME_LOGS_PAGE_LIMIT),
    ],
    total: false,
  })
  return response.logs ?? []
}

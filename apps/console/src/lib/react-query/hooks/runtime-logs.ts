/**
 * React Query hooks for runtime logs: the lines a function, site or dedicated
 * database wrote to stdout and stderr (Cloud `/v1/logs`). A null resource type
 * reads every resource in the project.
 */

import { infiniteQueryOptions } from '@tanstack/react-query'
import { Query, type Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
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
  stream: RuntimeLogStream
  range: RuntimeLogRange
}

function filterQueries(
  resourceType: RuntimeLogScope,
  filter: RuntimeLogsFilter,
): string[] {
  const queries: string[] = []
  if (resourceType === null) {
    if (filter.resourceType) {
      queries.push(Query.equal('resourceType', [filter.resourceType]))
    }
  } else if (resourceType !== 'databases' && filter.deploymentId) {
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
  resourceType: RuntimeLogScope,
  resourceId: string | null | undefined,
  filter: RuntimeLogsFilter,
): boolean {
  if (!projectId) return false
  if (resourceType === null) return true
  if (!resourceId) return false
  return resourceType === 'databases' || !!filter.deploymentId
}

/**
 * The pinned Console SDK requires a resource, so a project-wide list calls the
 * route directly. Switch to `logs.list()` once the SDK makes both optional.
 */
async function listRuntimeLogs(
  projectId: string,
  resourceType: RuntimeLogScope,
  resourceId: string | null | undefined,
  queries: string[],
): Promise<Models.RuntimeLog[]> {
  const project = sdk.forProject(projectId)
  if (resourceType !== null) {
    const response = await project.logs.list({
      resourceType,
      resourceId: resourceId!,
      queries,
      total: false,
    })
    return response.logs ?? []
  }
  const client = project.client
  const uri = new URL(client.config.endpoint + '/logs')
  for (const query of queries) uri.searchParams.append('queries[]', query)
  uri.searchParams.set('total', 'false')
  const response = (await client.call('get', uri, {
    'X-Appwrite-Project': client.config.project,
    'content-type': 'application/json',
  })) as Models.RuntimeLogList
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
        ...rangeQuery(filter.range),
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

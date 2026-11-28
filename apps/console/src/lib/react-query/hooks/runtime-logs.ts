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
import { isNativeDatabaseTypeValue } from '@/lib/databases/database-type'
import { nextCursorAfter } from './cursor'
import {
  fetchProjectConsoleDatabases,
  fetchProjectDedicatedDatabases,
} from './databases'
import { fetchProjectFunctionsByIds } from './functions'
import { fetchProjectSitesByIds } from './sites'

export type RuntimeLogResourceType = 'functions' | 'sites' | 'databases'

/** One resource, or `null` for every resource in the project. */
export type RuntimeLogScope = RuntimeLogResourceType | null

export type RuntimeLogStream = 'all' | 'stdout' | 'stderr'

/**
 * Rolling look-back windows, as the shared date range picker's presets name
 * them. Every plan keeps a day of logs; older windows are picked by date.
 */
export const RUNTIME_LOG_RANGES = {
  '15m': 15 * 60 * 1000,
  '1h': 60 * 60 * 1000,
  '6h': 6 * 60 * 60 * 1000,
  '24h': 24 * 60 * 60 * 1000,
} as const satisfies Record<string, number>

export type RuntimeLogRange = keyof typeof RUNTIME_LOG_RANGES

export const RUNTIME_LOGS_PAGE_LIMIT = 100

export interface RuntimeLogsFilter {
  /** Required for functions and sites; ignored for databases and the project. */
  deploymentId?: string
  /** Narrows a project-wide list to one kind of resource. */
  resourceType?: RuntimeLogResourceType
  /**
   * Narrows a project-wide list to one resource of `resourceType`. A database
   * is named by its own ID, as the database list lists it.
   */
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

/**
 * What a request reads: one resource, named in the path, or the whole project
 * with filters. A database picked on the project's Logs page is read by path:
 * a TablesDB, DocumentsDB or VectorsDB files its lines under the compute it
 * runs on, and only the API knows which compute that is.
 */
function requestScope(
  resourceType: RuntimeLogScope,
  resourceId: string | null | undefined,
  filter: RuntimeLogsFilter,
): { resourceType: RuntimeLogScope; resourceId: string | null | undefined } {
  if (
    resourceType === null &&
    filter.resourceType === 'databases' &&
    filter.resourceId
  ) {
    return { resourceType: 'databases', resourceId: filter.resourceId }
  }
  return { resourceType, resourceId }
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
  return {
    from: new Date(Date.now() - RUNTIME_LOG_RANGES[filter.range]),
    to: null,
  }
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
      const scope = requestScope(resourceType, resourceId, filter)
      const queries = [
        ...filterQueries(scope.resourceType, filter),
        ...rangeQuery(filter),
        Query.orderDesc('timestamp'),
        Query.limit(RUNTIME_LOGS_PAGE_LIMIT),
      ]
      if (pageParam) queries.push(Query.cursorAfter(pageParam))
      const logs = await listRuntimeLogs(
        projectId!,
        scope.resourceType,
        scope.resourceId,
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
  const scope = requestScope(resourceType, resourceId, filter)
  return listRuntimeLogs(projectId, scope.resourceType, scope.resourceId, [
    ...filterQueries(scope.resourceType, filter),
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
      const scope = requestScope(resourceType, resourceId, filter)
      const queries = [
        ...filterQueries(scope.resourceType, filter),
        ...rangeQuery(filter),
      ]
      const usage = await getRuntimeLogUsage(
        projectId!,
        scope.resourceType,
        scope.resourceId,
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

/** The resource that wrote a line on a project-wide list, by name. */
export interface RuntimeLogSource {
  type: RuntimeLogResourceType
  id: string
  name: string
  /** Functions only: `node-22`, `python-3.12`... */
  runtime?: string
  /** Sites only: `nextjs`, `astro`... */
  framework?: string
  /** Databases only: the engine it runs on, `postgresql`, `mysql`... */
  engine?: string
  /** Databases only: `tablesdb`, `documentsdb` or `vectorsdb`; unset for a native engine. */
  api?: string
}

/** Dedicated databases listed for picking and naming; most projects have far fewer. */
export const RUNTIME_LOG_DATABASES_LIMIT = 100

/**
 * A database from the project's database list (`console.listDatabases`, as
 * the Databases page lists them) as a log source. Only databases on dedicated
 * compute write logs.
 */
export function runtimeLogDatabaseSource(
  database: Pick<Models.Database, '$id' | 'name' | 'type' | 'engine'>,
): RuntimeLogSource | null {
  if (!database.engine) return null
  return {
    type: 'databases',
    id: database.$id,
    name: database.name,
    engine: database.engine,
    api: isNativeDatabaseTypeValue(database.type) ? undefined : database.type,
  }
}

/** Sources keyed `<type>:<id>`. */
export type RuntimeLogSources = ReadonlyMap<string, RuntimeLogSource>

export function runtimeLogSourceKey(type: string, id: string): string {
  return `${type}:${id}`
}

/**
 * Names the functions and sites that wrote the loaded lines (one list call
 * each, by ID) and the project's databases, from the list the Databases page
 * reads. A native PostgreSQL or MySQL database shares its ID with its
 * compute, which its lines carry; a TablesDB, DocumentsDB or VectorsDB on
 * dedicated compute logs under the compute's own ID, named from the compute
 * list. A resource that fails to load is left unnamed, not an error.
 */
export function runtimeLogSourcesQueryOptions(
  projectId: string | null | undefined,
  functionIds: readonly string[],
  siteIds: readonly string[],
  withDatabases: boolean,
) {
  return queryOptions({
    queryKey: [
      'runtime-log-sources',
      projectId,
      functionIds,
      siteIds,
      withDatabases,
    ],
    queryFn: async (): Promise<RuntimeLogSources> => {
      const [functions, sites, databases, computes] = await Promise.all([
        fetchProjectFunctionsByIds(projectId!, [...functionIds]).catch(() => ({
          functions: [],
        })),
        fetchProjectSitesByIds(projectId!, [...siteIds]).catch(() => ({
          sites: [],
        })),
        withDatabases
          ? fetchProjectConsoleDatabases(
              projectId!,
              0,
              RUNTIME_LOG_DATABASES_LIMIT,
            ).catch(() => ({ databases: [] }))
          : { databases: [] },
        withDatabases
          ? fetchProjectDedicatedDatabases(projectId!).catch(() => ({
              databases: [],
            }))
          : { databases: [] },
      ])
      const sources = new Map<string, RuntimeLogSource>()
      for (const fn of functions.functions) {
        sources.set(runtimeLogSourceKey('functions', fn.$id), {
          type: 'functions',
          id: fn.$id,
          name: fn.name,
          runtime: fn.runtime,
        })
      }
      for (const site of sites.sites) {
        sources.set(runtimeLogSourceKey('sites', site.$id), {
          type: 'sites',
          id: site.$id,
          name: site.name,
          framework: site.framework,
        })
      }
      // Computes first, so a database of the same ID names it
      for (const compute of computes.databases) {
        sources.set(runtimeLogSourceKey('databases', compute.$id), {
          type: 'databases',
          id: compute.$id,
          name: compute.name,
          engine: compute.engine ?? undefined,
          api: compute.api ?? undefined,
        })
      }
      for (const database of databases.databases) {
        const source = runtimeLogDatabaseSource(database)
        if (source) {
          sources.set(runtimeLogSourceKey('databases', source.id), source)
        }
      }
      return sources
    },
    enabled:
      !!projectId &&
      (functionIds.length > 0 || siteIds.length > 0 || withDatabases),
    staleTime: 5 * 60 * 1000,
    retry: false,
    refetchOnWindowFocus: false,
  })
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

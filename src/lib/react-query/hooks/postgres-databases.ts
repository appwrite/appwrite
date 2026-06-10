import {
  queryOptions,
  useMutation,
  useQuery,
  useQueryClient,
} from '@tanstack/react-query'
import { Query } from '@appwrite.io/console'
import type { Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import {
  buildPostgresCountSql,
  buildPostgresSelectSql,
  executionResultRows,
  POSTGRES_LIST_SCHEMAS_SQL,
  POSTGRES_LIST_TABLES_SQL,
  type PostgresSchemaRow,
  type PostgresTableRow,
} from '@/lib/postgres-sql'
import { parsePostgresTableId } from '@/lib/postgres-database-routes'
import { DEFAULT_STALE_TIME } from './constants'

function isPostgresEngine(engine: string | undefined): boolean {
  const normalized = engine?.toLowerCase() ?? ''
  return normalized === 'postgres' || normalized === 'postgresql'
}

async function fetchPostgresDatabaseFromList(
  projectId: string,
  databaseId: string,
): Promise<Models.DedicatedDatabase | null> {
  const response = await sdk.forProject(projectId).compute.listDatabases({
    queries: [Query.equal('$id', databaseId), Query.limit(1)],
  })
  return response.databases?.find((db) => db.$id === databaseId) ?? null
}

export async function fetchPostgresDatabase(
  projectId: string,
  databaseId: string,
): Promise<Models.DedicatedDatabase | null> {
  if (!projectId || !databaseId) return null
  try {
    const database = await sdk
      .forProject(projectId)
      .compute.getDatabase({ databaseId })
    if (database?.$id) return database
  } catch {
    /* fall back to list */
  }

  try {
    return await fetchPostgresDatabaseFromList(projectId, databaseId)
  } catch {
    return null
  }
}

export { isPostgresEngine }

export async function executePostgresDatabaseSql(
  projectId: string,
  databaseId: string,
  sql: string,
  timeoutSeconds?: number,
): Promise<Models.DedicatedDatabaseExecution> {
  return await sdk.forProject(projectId).compute.createDatabaseExecution({
    databaseId,
    sql,
    timeoutSeconds,
  })
}

export async function fetchPostgresSchemas(projectId: string, databaseId: string) {
  const execution = await executePostgresDatabaseSql(
    projectId,
    databaseId,
    POSTGRES_LIST_SCHEMAS_SQL,
  )
  const rows = executionResultRows<PostgresSchemaRow>(execution)
  return {
    schemas: rows.map((row) => row.schema_name).filter(Boolean),
    total: rows.length,
  }
}

export async function fetchPostgresTables(projectId: string, databaseId: string) {
  const execution = await executePostgresDatabaseSql(
    projectId,
    databaseId,
    POSTGRES_LIST_TABLES_SQL,
  )
  const rows = executionResultRows<PostgresTableRow>(execution)
  return {
    tables: rows.filter((row) => row.table_schema && row.table_name),
    total: rows.length,
  }
}

export async function fetchPostgresTableRows(
  projectId: string,
  databaseId: string,
  tableId: string,
  page: number,
  limit: number,
) {
  const { schema, table } = parsePostgresTableId(tableId)
  const offset = page * limit

  const [dataExecution, countExecution] = await Promise.all([
    executePostgresDatabaseSql(
      projectId,
      databaseId,
      buildPostgresSelectSql(schema, table, limit, offset),
    ),
    executePostgresDatabaseSql(
      projectId,
      databaseId,
      buildPostgresCountSql(schema, table),
    ),
  ])

  const rows = executionResultRows<Record<string, unknown>>(dataExecution)
  const countRows = executionResultRows<{ total?: number | string }>(
    countExecution,
  )
  const totalRaw = countRows[0]?.total
  const total =
    typeof totalRaw === 'number'
      ? totalRaw
      : Number.parseInt(String(totalRaw ?? rows.length), 10) || rows.length

  return {
    rows,
    total,
    columns: dataExecution.columns ?? [],
    durationMs: dataExecution.durationMs,
    truncated: dataExecution.truncated,
  }
}

export function postgresDatabaseQueryOptions(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
) {
  return queryOptions({
    queryKey: ['postgres-database', 'project', projectId, databaseId],
    queryFn: () => fetchPostgresDatabase(projectId!, databaseId!),
    enabled: !!projectId && !!databaseId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId && databaseId ? 5 * 60 * 1000 : 0,
  })
}

export function postgresSchemasQueryOptions(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
) {
  return queryOptions({
    queryKey: ['postgres-schemas', 'project', projectId, databaseId],
    queryFn: () => fetchPostgresSchemas(projectId!, databaseId!),
    enabled: !!projectId && !!databaseId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId && databaseId ? 5 * 60 * 1000 : 0,
  })
}

export function postgresTablesQueryOptions(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
) {
  return queryOptions({
    queryKey: ['postgres-tables', 'project', projectId, databaseId],
    queryFn: () => fetchPostgresTables(projectId!, databaseId!),
    enabled: !!projectId && !!databaseId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId && databaseId ? 5 * 60 * 1000 : 0,
  })
}

export async function fetchPostgresDatabaseConnections(
  projectId: string,
  databaseId: string,
): Promise<Models.DedicatedDatabaseConnectionList> {
  return await sdk.forProject(projectId).compute.listDatabaseConnections({
    databaseId,
  })
}

export function postgresDatabaseConnectionsQueryOptions(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
) {
  return queryOptions({
    queryKey: [
      'postgres-database-connections',
      'project',
      projectId,
      databaseId,
    ],
    queryFn: () =>
      fetchPostgresDatabaseConnections(projectId!, databaseId!),
    enabled: !!projectId && !!databaseId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId && databaseId ? 5 * 60 * 1000 : 0,
  })
}

export function postgresTableRowsQueryOptions(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  tableId: string | null | undefined,
  page: number = 0,
  limit: number = 25,
) {
  return queryOptions({
    queryKey: [
      'postgres-table-rows',
      'project',
      projectId,
      databaseId,
      tableId,
      page,
      limit,
    ],
    queryFn: () =>
      fetchPostgresTableRows(projectId!, databaseId!, tableId!, page, limit),
    enabled: !!projectId && !!databaseId && !!tableId && tableId !== '-',
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId && databaseId && tableId ? 5 * 60 * 1000 : 0,
  })
}

export function usePostgresDatabase(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
) {
  const { data, isLoading, error, refetch } = useQuery(
    postgresDatabaseQueryOptions(projectId, databaseId),
  )
  return { database: data ?? null, isLoading, error, refetch }
}

export function usePostgresSchemas(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
) {
  const { data, isLoading, error, refetch, isFetching } = useQuery(
    postgresSchemasQueryOptions(projectId, databaseId),
  )
  return {
    schemas: data?.schemas ?? [],
    total: data?.total ?? 0,
    isLoading,
    isFetching,
    error,
    refetch,
  }
}

export function usePostgresTables(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
) {
  const { data, isLoading, error, refetch, isFetching } = useQuery(
    postgresTablesQueryOptions(projectId, databaseId),
  )
  return {
    tables: data?.tables ?? [],
    total: data?.total ?? 0,
    isLoading,
    isFetching,
    error,
    refetch,
  }
}

export function usePostgresDatabaseConnections(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
) {
  const { data, isLoading, isFetching, error, refetch } = useQuery(
    postgresDatabaseConnectionsQueryOptions(projectId, databaseId),
  )
  return {
    connections: data?.connections ?? [],
    total: data?.total ?? 0,
    isLoading,
    isFetching,
    error,
    refetch,
  }
}

export function usePostgresTableRows(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  tableId: string | null | undefined,
  page: number = 0,
  limit: number = 25,
) {
  const { data, isLoading, isFetching, error, refetch } = useQuery(
    postgresTableRowsQueryOptions(projectId, databaseId, tableId, page, limit),
  )
  return {
    rows: data?.rows ?? [],
    total: data?.total ?? 0,
    columns: data?.columns ?? [],
    durationMs: data?.durationMs,
    truncated: data?.truncated,
    isLoading,
    isFetching,
    error,
    refetch,
  }
}

export function useExecutePostgresSql(
  projectId: string,
  databaseId: string,
) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (sql: string) =>
      executePostgresDatabaseSql(projectId, databaseId, sql),
    onSuccess: () => {
      void queryClient.invalidateQueries({
        queryKey: ['postgres-schemas', 'project', projectId, databaseId],
      })
      void queryClient.invalidateQueries({
        queryKey: ['postgres-tables', 'project', projectId, databaseId],
      })
      void queryClient.invalidateQueries({
        queryKey: ['postgres-table-rows', 'project', projectId, databaseId],
      })
    },
  })
}

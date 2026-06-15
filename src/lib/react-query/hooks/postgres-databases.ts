import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import {
  infiniteQueryOptions,
  keepPreviousData,
  queryOptions,
  useInfiniteQuery,
  useMutation,
  useQuery,
  useQueryClient,
} from '@tanstack/react-query'
import { Query } from '@appwrite.io/console'
import type { Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import {
  normalizePostgresExecutionResult,
  wrapPostgresSqlForDisplay,
} from '@/lib/postgres-execution-values'
import {
  executionResultRows,
  buildPostgresListSchemasCountSql,
  buildPostgresListSchemasSql,
  buildPostgresListTablesCountSql,
  buildPostgresListTablesSql,
  buildPostgresTableColumnsSql,
  buildPostgresTableIndexesSql,
  buildPostgresTableInfoSql,
  POSTGRES_LIST_COLUMNS_SQL,
  POSTGRES_LIST_SCHEMAS_SQL,
  POSTGRES_SIDEBAR_LIST_PAGE_SIZE,
  type PostgresColumnRow,
  type PostgresListSchemasOptions,
  type PostgresListTablesOptions,
  type PostgresSchemaRow,
  type PostgresTableColumnRow,
  type PostgresTableIndexRow,
  type PostgresTableInfoRow,
  type PostgresTableRow,
  sortPostgresTableColumns,
  sortPostgresTableIndexes,
} from '@/lib/postgres-sql'
import { parsePostgresTableId, quotePostgresIdentifier } from '@/lib/postgres-database-routes'
import {
  buildPostgresCountRowsSql,
  buildPostgresDeleteRowSql,
  buildPostgresInsertRowSql,
  buildPostgresSelectRowsSql,
  buildPostgresUpdateRowSql,
  POSTGRES_ROW_CTID_COLUMN,
  type PostgresRowIdentity,
} from '@/lib/postgres-row-sql'
import {
  groupPostgresEditsByRow,
  type PendingPostgresRowCellEdit,
} from '@/lib/postgres-row-edits'
import type { RowCellValue } from '@/lib/database-row-inline-edits'
import type { CompactFilterKey } from '@/lib/table-filters/types'
import {
  buildPostgresRowsListWhereClause,
} from '@/lib/postgres-row-filters'
import {
  buildPostgresSavedQueriesPrefs,
  buildPostgresSavedQueriesScopePrefs,
  buildPostgresSelectedSchemaPrefs,
  MAX_SAVED_POSTGRES_QUERIES,
  MAX_SAVED_POSTGRES_QUERY_NAME_LENGTH,
  MAX_SAVED_POSTGRES_QUERY_SQL_CHARS,
  mergePostgresQueryHistoryIntoPrefs,
  parsePostgresQueryHistory,
  parsePostgresSavedQueries,
  parsePostgresSavedQueriesScope,
  parsePostgresSelectedSchema,
  resolvePostgresSavedQueriesScope,
  resolvePostgresSelectedSchema,
  type PostgresQueryHistoryEntry,
  type SavedPostgresQuery,
  type UserPrefs,
} from '@/lib/user-prefs-keys'
import {
  getConsoleAccountFromCache,
  syncConsoleAccountAfterMutation,
  updateAccountPrefs,
} from './auth'
import { useConsoleTeam, useUpdateConsoleTeamPrefs } from './teams'
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
  const execution = await sdk.forProject(projectId).compute.createDatabaseExecution({
    databaseId,
    sql: wrapPostgresSqlForDisplay(sql),
    timeoutSeconds,
  })
  return normalizePostgresExecutionResult(execution)
}

function parsePostgresCountTotal(
  execution: Models.DedicatedDatabaseExecution,
  fallback = 0,
): number {
  const rows = executionResultRows<{ total?: number | string }>(execution)
  const totalRaw = rows[0]?.total
  if (typeof totalRaw === 'number' && Number.isFinite(totalRaw)) return totalRaw
  const parsed = Number.parseInt(String(totalRaw ?? fallback), 10)
  return Number.isFinite(parsed) ? parsed : fallback
}

export async function fetchPostgresSchemasPage(
  projectId: string,
  databaseId: string,
  options?: Pick<PostgresListSchemasOptions, 'search'> & {
    page?: number
    limit?: number
  },
) {
  const limit = options?.limit ?? POSTGRES_SIDEBAR_LIST_PAGE_SIZE
  const page = options?.page ?? 0
  const offset = page * limit
  const search = options?.search?.trim() || undefined

  const [dataExecution, countExecution] = await Promise.all([
    executePostgresDatabaseSql(
      projectId,
      databaseId,
      buildPostgresListSchemasSql({ search, limit, offset }),
    ),
    executePostgresDatabaseSql(
      projectId,
      databaseId,
      buildPostgresListSchemasCountSql({ search }),
    ),
  ])

  const rows = executionResultRows<PostgresSchemaRow>(dataExecution)
  const schemas = rows.map((row) => row.schema_name).filter(Boolean)
  const total = parsePostgresCountTotal(countExecution, schemas.length)

  return {
    schemas,
    total,
    page,
    limit,
    hasMore: offset + schemas.length < total,
  }
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

function normalizePostgresTablesListOptions(
  options?: PostgresListTablesOptions,
): PostgresListTablesOptions | undefined {
  if (!options) return undefined
  const schema = options.schema?.trim() || undefined
  const search = options.search?.trim() || undefined
  if (!schema && !search) return undefined
  return { schema, search }
}

export async function fetchPostgresTablesPage(
  projectId: string,
  databaseId: string,
  options: {
    schema: string
    search?: string
    page?: number
    limit?: number
  },
) {
  const schema = options.schema.trim()
  const limit = options.limit ?? POSTGRES_SIDEBAR_LIST_PAGE_SIZE
  const page = options.page ?? 0
  const offset = page * limit
  const search = options.search?.trim() || undefined

  const [dataExecution, countExecution] = await Promise.all([
    executePostgresDatabaseSql(
      projectId,
      databaseId,
      buildPostgresListTablesSql({ schema, search, limit, offset }),
    ),
    executePostgresDatabaseSql(
      projectId,
      databaseId,
      buildPostgresListTablesCountSql({ schema, search }),
    ),
  ])

  const rows = executionResultRows<PostgresTableRow>(dataExecution)
  const tables = rows.filter((row) => row.table_schema && row.table_name)
  const total = parsePostgresCountTotal(countExecution, tables.length)

  return {
    tables,
    total,
    page,
    limit,
    hasMore: offset + tables.length < total,
  }
}

export async function fetchPostgresTables(
  projectId: string,
  databaseId: string,
  options?: PostgresListTablesOptions,
) {
  const normalized = normalizePostgresTablesListOptions(options)
  const execution = await executePostgresDatabaseSql(
    projectId,
    databaseId,
    buildPostgresListTablesSql(normalized),
  )
  const rows = executionResultRows<PostgresTableRow>(execution)
  return {
    tables: rows.filter((row) => row.table_schema && row.table_name),
    total: rows.length,
  }
}

export async function fetchPostgresColumns(projectId: string, databaseId: string) {
  const execution = await executePostgresDatabaseSql(
    projectId,
    databaseId,
    POSTGRES_LIST_COLUMNS_SQL,
  )
  const rows = executionResultRows<PostgresColumnRow>(execution)
  return {
    columns: rows.filter(
      (row) => row.table_schema && row.table_name && row.column_name,
    ),
    total: rows.length,
  }
}

export async function fetchPostgresTableColumns(
  projectId: string,
  databaseId: string,
  tableId: string,
) {
  const { schema, table } = parsePostgresTableId(tableId)
  const execution = await executePostgresDatabaseSql(
    projectId,
    databaseId,
    buildPostgresTableColumnsSql(schema, table),
  )
  const rows = executionResultRows<PostgresTableColumnRow>(execution)
  const columns = sortPostgresTableColumns(
    rows.filter((row) => row.column_name),
  )
  return {
    columns,
    total: columns.length,
  }
}

export async function fetchPostgresTableIndexes(
  projectId: string,
  databaseId: string,
  tableId: string,
) {
  const { schema, table } = parsePostgresTableId(tableId)
  const execution = await executePostgresDatabaseSql(
    projectId,
    databaseId,
    buildPostgresTableIndexesSql(schema, table),
  )
  const rows = executionResultRows<PostgresTableIndexRow>(execution)
  const indexes = sortPostgresTableIndexes(
    rows.filter((row) => row.index_name),
  )
  return {
    indexes,
    total: indexes.length,
  }
}

export async function fetchPostgresTableInfo(
  projectId: string,
  databaseId: string,
  tableId: string,
) {
  const { schema, table } = parsePostgresTableId(tableId)
  const execution = await executePostgresDatabaseSql(
    projectId,
    databaseId,
    buildPostgresTableInfoSql(schema, table),
  )
  const rows = executionResultRows<PostgresTableInfoRow>(execution)
  return rows[0] ?? null
}

export type PostgresTableRowsListParams = {
  search?: string
  filterKeys?: CompactFilterKey[]
  orderBy?: string
  orderDirection?: 'asc' | 'desc'
}

async function resolvePostgresRowsWhereClause(
  projectId: string,
  databaseId: string,
  tableId: string,
  params?: PostgresTableRowsListParams,
): Promise<string | undefined> {
  const columns = await fetchPostgresTableColumns(projectId, databaseId, tableId)
  return buildPostgresRowsListWhereClause(
    params?.filterKeys,
    params?.search,
    columns.columns,
  )
}

function buildPostgresRowsOrderClause(
  columns: PostgresTableColumnRow[],
  sortBy?: string,
  orderDirection: 'asc' | 'desc' = 'asc',
): string | undefined {
  const direction = orderDirection === 'desc' ? 'DESC' : 'ASC'
  const sortColumn = sortBy?.trim()
  if (sortColumn && columns.some((column) => column.column_name === sortColumn)) {
    return `${quotePostgresIdentifier(sortColumn)} ${direction}`
  }
  const pkColumns = columns.filter(
    (column) =>
      column.is_primary_key === true || column.is_primary_key === 'true',
  )
  if (pkColumns.length > 0) {
    return pkColumns
      .map((column) => `${quotePostgresIdentifier(column.column_name)} ${direction}`)
      .join(', ')
  }
  return `${quotePostgresIdentifier(POSTGRES_ROW_CTID_COLUMN)} ${direction}`
}

export async function fetchPostgresTableRows(
  projectId: string,
  databaseId: string,
  tableId: string,
  page: number,
  limit: number,
  params?: PostgresTableRowsListParams,
) {
  const columnsResult = await fetchPostgresTableColumns(
    projectId,
    databaseId,
    tableId,
  )
  const whereClause = await resolvePostgresRowsWhereClause(
    projectId,
    databaseId,
    tableId,
    params,
  )
  const orderByClause = buildPostgresRowsOrderClause(
    columnsResult.columns,
    params?.orderBy,
    params?.orderDirection ?? 'asc',
  )
  const offset = page * limit

  const [dataExecution, countExecution] = await Promise.all([
    executePostgresDatabaseSql(
      projectId,
      databaseId,
      buildPostgresSelectRowsSql(tableId, {
        whereClause,
        orderByClause,
        limit,
        offset,
      }),
    ),
    executePostgresDatabaseSql(
      projectId,
      databaseId,
      buildPostgresCountRowsSql(tableId, whereClause),
    ),
  ])

  const rows = executionResultRows<Record<string, unknown>>(dataExecution)
  const total = parsePostgresCountTotal(countExecution, rows.length)

  return {
    rows,
    total,
    columns: dataExecution.columns ?? [],
    tableColumns: columnsResult.columns,
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
  options?: PostgresListTablesOptions,
) {
  const normalized = normalizePostgresTablesListOptions(options)
  return queryOptions({
    queryKey: [
      'postgres-tables',
      'project',
      projectId,
      databaseId,
      normalized?.schema,
      normalized?.search,
    ],
    queryFn: () =>
      fetchPostgresTables(projectId!, databaseId!, normalized),
    enabled: !!projectId && !!databaseId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId && databaseId ? 5 * 60 * 1000 : 0,
  })
}

export function postgresColumnsQueryOptions(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
) {
  return queryOptions({
    queryKey: ['postgres-columns', 'project', projectId, databaseId],
    queryFn: () => fetchPostgresColumns(projectId!, databaseId!),
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

export async function fetchPostgresDatabaseCredentials(
  projectId: string,
  databaseId: string,
): Promise<Models.DedicatedDatabaseCredentials> {
  return await sdk.forProject(projectId).compute.getDatabaseCredentials({
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

export function postgresDatabaseCredentialsQueryOptions(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
) {
  return queryOptions({
    queryKey: [
      'postgres-database-credentials',
      'project',
      projectId,
      databaseId,
    ],
    queryFn: () =>
      fetchPostgresDatabaseCredentials(projectId!, databaseId!),
    enabled: !!projectId && !!databaseId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId && databaseId ? 5 * 60 * 1000 : 0,
  })
}

export async function fetchPostgresDatabasePooler(
  projectId: string,
  databaseId: string,
): Promise<Models.DedicatedDatabasePooler | null> {
  try {
    return await sdk.forProject(projectId).compute.getDatabasePooler({
      databaseId,
    })
  } catch {
    return null
  }
}

export function postgresDatabasePoolerQueryOptions(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
) {
  return queryOptions({
    queryKey: ['postgres-database-pooler', 'project', projectId, databaseId],
    queryFn: () => fetchPostgresDatabasePooler(projectId!, databaseId!),
    enabled: !!projectId && !!databaseId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId && databaseId ? 5 * 60 * 1000 : 0,
  })
}

export function postgresTableColumnsQueryOptions(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  tableId: string | null | undefined,
) {
  return queryOptions({
    queryKey: [
      'postgres-table-columns',
      'project',
      projectId,
      databaseId,
      tableId,
    ],
    queryFn: () =>
      fetchPostgresTableColumns(projectId!, databaseId!, tableId!),
    enabled: !!projectId && !!databaseId && !!tableId && tableId !== '-',
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId && databaseId && tableId ? 5 * 60 * 1000 : 0,
  })
}

export function postgresTableIndexesQueryOptions(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  tableId: string | null | undefined,
) {
  return queryOptions({
    queryKey: [
      'postgres-table-indexes',
      'project',
      projectId,
      databaseId,
      tableId,
    ],
    queryFn: () =>
      fetchPostgresTableIndexes(projectId!, databaseId!, tableId!),
    enabled: !!projectId && !!databaseId && !!tableId && tableId !== '-',
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId && databaseId && tableId ? 5 * 60 * 1000 : 0,
  })
}

export function postgresTableInfoQueryOptions(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  tableId: string | null | undefined,
) {
  return queryOptions({
    queryKey: [
      'postgres-table-info',
      'project',
      projectId,
      databaseId,
      tableId,
    ],
    queryFn: () => fetchPostgresTableInfo(projectId!, databaseId!, tableId!),
    enabled: !!projectId && !!databaseId && !!tableId && tableId !== '-',
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId && databaseId && tableId ? 5 * 60 * 1000 : 0,
  })
}

export function postgresTableRowsQueryOptions(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  tableId: string | null | undefined,
  page: number = 0,
  limit: number = 25,
  params?: PostgresTableRowsListParams,
) {
  const filterKey = params?.filterKeys?.length
    ? JSON.stringify(params.filterKeys)
    : undefined
  return queryOptions({
    queryKey: [
      'postgres-table-rows',
      'project',
      projectId,
      databaseId,
      tableId,
      page,
      limit,
      params?.search?.trim() || undefined,
      filterKey,
      params?.orderBy,
      params?.orderDirection,
    ],
    queryFn: () =>
      fetchPostgresTableRows(
        projectId!,
        databaseId!,
        tableId!,
        page,
        limit,
        params,
      ),
    enabled: !!projectId && !!databaseId && !!tableId && tableId !== '-',
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    placeholderData: keepPreviousData,
    gcTime: projectId && databaseId && tableId ? 5 * 60 * 1000 : 0,
  })
}

export async function updatePostgresTableRow(
  projectId: string,
  databaseId: string,
  tableId: string,
  identity: PostgresRowIdentity,
  changes: Record<string, RowCellValue>,
) {
  const sql = buildPostgresUpdateRowSql(tableId, identity, changes)
  return executePostgresDatabaseSql(projectId, databaseId, sql)
}

export async function createPostgresTableRow(
  projectId: string,
  databaseId: string,
  tableId: string,
  values: Record<string, RowCellValue>,
) {
  const sql = buildPostgresInsertRowSql(tableId, values)
  return executePostgresDatabaseSql(projectId, databaseId, sql)
}

export async function deletePostgresTableRow(
  projectId: string,
  databaseId: string,
  tableId: string,
  identity: PostgresRowIdentity,
) {
  const sql = buildPostgresDeleteRowSql(tableId, identity)
  return executePostgresDatabaseSql(projectId, databaseId, sql)
}

export async function commitPostgresRowEdits(
  projectId: string,
  databaseId: string,
  tableId: string,
  edits: PendingPostgresRowCellEdit[],
) {
  const grouped = groupPostgresEditsByRow(edits)
  for (const [, { identity, changes }] of grouped) {
    await updatePostgresTableRow(
      projectId,
      databaseId,
      tableId,
      identity,
      changes,
    )
  }
}

export function useUpdatePostgresTableRow(
  projectId: string,
  databaseId: string,
  tableId: string,
) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (params: {
      identity: PostgresRowIdentity
      changes: Record<string, RowCellValue>
    }) =>
      updatePostgresTableRow(
        projectId,
        databaseId,
        tableId,
        params.identity,
        params.changes,
      ),
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: ['postgres-table-rows', 'project', projectId, databaseId, tableId],
      })
    },
  })
}

export function useCreatePostgresTableRow(
  projectId: string,
  databaseId: string,
  tableId: string,
) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (values: Record<string, RowCellValue>) =>
      createPostgresTableRow(projectId, databaseId, tableId, values),
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: ['postgres-table-rows', 'project', projectId, databaseId, tableId],
      })
    },
  })
}

export function useDeletePostgresTableRow(
  projectId: string,
  databaseId: string,
  tableId: string,
) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (identity: PostgresRowIdentity) =>
      deletePostgresTableRow(projectId, databaseId, tableId, identity),
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: ['postgres-table-rows', 'project', projectId, databaseId, tableId],
      })
    },
  })
}

export function useCommitPostgresRowEdits(
  projectId: string,
  databaseId: string,
  tableId: string,
) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (edits: PendingPostgresRowCellEdit[]) =>
      commitPostgresRowEdits(projectId, databaseId, tableId, edits),
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: ['postgres-table-rows', 'project', projectId, databaseId, tableId],
      })
    },
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

export function postgresSidebarSchemasInfiniteQueryOptions(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  search: string,
) {
  const normalizedSearch = search.trim() || undefined
  return infiniteQueryOptions({
    queryKey: [
      'postgres-schemas',
      'project',
      projectId,
      databaseId,
      'sidebar',
      normalizedSearch,
    ],
    queryFn: ({ pageParam }) =>
      fetchPostgresSchemasPage(projectId!, databaseId!, {
        search: normalizedSearch,
        page: pageParam,
      }),
    initialPageParam: 0,
    getNextPageParam: (lastPage) =>
      lastPage.hasMore ? lastPage.page + 1 : undefined,
    enabled: !!projectId && !!databaseId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId && databaseId ? 5 * 60 * 1000 : 0,
  })
}

export function postgresSidebarTablesInfiniteQueryOptions(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  schema: string | null | undefined,
  search: string,
) {
  const normalizedSearch = search.trim() || undefined
  return infiniteQueryOptions({
    queryKey: [
      'postgres-tables',
      'project',
      projectId,
      databaseId,
      'sidebar',
      schema,
      normalizedSearch,
    ],
    queryFn: ({ pageParam }) =>
      fetchPostgresTablesPage(projectId!, databaseId!, {
        schema: schema!,
        search: normalizedSearch,
        page: pageParam,
      }),
    initialPageParam: 0,
    getNextPageParam: (lastPage) =>
      lastPage.hasMore ? lastPage.page + 1 : undefined,
    enabled: !!projectId && !!databaseId && !!schema,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId && databaseId && schema ? 5 * 60 * 1000 : 0,
  })
}

export function usePostgresSidebarSchemas(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  search: string,
) {
  const normalizedSearch = search.trim()
  const {
    data,
    isLoading,
    isFetching,
    isFetchingNextPage,
    error,
    refetch,
    fetchNextPage,
    hasNextPage,
  } = useInfiniteQuery({
    ...postgresSidebarSchemasInfiniteQueryOptions(
      projectId,
      databaseId,
      normalizedSearch,
    ),
    placeholderData: keepPreviousData,
  })

  const schemas = useMemo(
    () => data?.pages.flatMap((page) => page.schemas) ?? [],
    [data?.pages],
  )
  const total = data?.pages[0]?.total ?? schemas.length

  return {
    schemas,
    total,
    isLoading,
    isFetching,
    isFetchingNextPage,
    error,
    refetch,
    fetchNextPage,
    hasNextPage: hasNextPage ?? false,
  }
}

export function usePostgresSidebarTables(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  schema: string | null | undefined,
  search: string,
) {
  const normalizedSearch = search.trim()
  const {
    data,
    isLoading,
    isFetching,
    isFetchingNextPage,
    error,
    refetch,
    fetchNextPage,
    hasNextPage,
  } = useInfiniteQuery({
    ...postgresSidebarTablesInfiniteQueryOptions(
      projectId,
      databaseId,
      schema,
      normalizedSearch,
    ),
    placeholderData: keepPreviousData,
  })

  const tables = useMemo(
    () => data?.pages.flatMap((page) => page.tables) ?? [],
    [data?.pages],
  )
  const total = data?.pages[0]?.total ?? tables.length

  return {
    tables,
    total,
    isLoading,
    isFetching,
    isFetchingNextPage,
    error,
    refetch,
    fetchNextPage,
    hasNextPage: hasNextPage ?? false,
  }
}

export function usePostgresColumns(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
) {
  const { data, isLoading, error, refetch, isFetching } = useQuery(
    postgresColumnsQueryOptions(projectId, databaseId),
  )
  return {
    columns: data?.columns ?? [],
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

export function usePostgresDatabaseCredentials(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
) {
  const { data, isLoading, isFetching, error, refetch } = useQuery(
    postgresDatabaseCredentialsQueryOptions(projectId, databaseId),
  )
  return {
    credentials: data ?? null,
    isLoading,
    isFetching,
    error,
    refetch,
  }
}

export function usePostgresDatabasePooler(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
) {
  const { data, isLoading, isFetching, error, refetch } = useQuery(
    postgresDatabasePoolerQueryOptions(projectId, databaseId),
  )
  return {
    pooler: data ?? null,
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
  params?: PostgresTableRowsListParams,
) {
  const { data, isLoading, isFetching, error, refetch } = useQuery(
    postgresTableRowsQueryOptions(
      projectId,
      databaseId,
      tableId,
      page,
      limit,
      params,
    ),
  )
  return {
    rows: data?.rows ?? [],
    total: data?.total ?? 0,
    columns: data?.columns ?? [],
    tableColumns: data?.tableColumns ?? [],
    durationMs: data?.durationMs,
    truncated: data?.truncated,
    isLoading,
    isFetching,
    error,
    refetch,
  }
}

export function usePostgresTableColumns(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  tableId: string | null | undefined,
) {
  const { data, isLoading, isFetching, error, refetch } = useQuery(
    postgresTableColumnsQueryOptions(projectId, databaseId, tableId),
  )
  return {
    columns: data?.columns ?? [],
    total: data?.total ?? 0,
    isLoading,
    isFetching,
    error,
    refetch,
  }
}

export function usePostgresTableIndexes(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  tableId: string | null | undefined,
) {
  const { data, isLoading, isFetching, error, refetch } = useQuery(
    postgresTableIndexesQueryOptions(projectId, databaseId, tableId),
  )
  return {
    indexes: data?.indexes ?? [],
    total: data?.total ?? 0,
    isLoading,
    isFetching,
    error,
    refetch,
  }
}

export function usePostgresTableInfo(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  tableId: string | null | undefined,
) {
  const { data, isLoading, isFetching, error, refetch } = useQuery(
    postgresTableInfoQueryOptions(projectId, databaseId, tableId),
  )
  return {
    tableInfo: data ?? null,
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
        queryKey: ['postgres-columns', 'project', projectId, databaseId],
      })
      void queryClient.invalidateQueries({
        queryKey: ['postgres-table-rows', 'project', projectId, databaseId],
      })
      void queryClient.invalidateQueries({
        queryKey: ['postgres-table-columns', 'project', projectId, databaseId],
      })
      void queryClient.invalidateQueries({
        queryKey: ['postgres-table-indexes', 'project', projectId, databaseId],
      })
      void queryClient.invalidateQueries({
        queryKey: ['postgres-table-info', 'project', projectId, databaseId],
      })
    },
  })
}

export type PostgresSavedQueryLevel = 'user' | 'team'

export function usePostgresSelectedSchema(
  databaseId: string | null | undefined,
  knownSchemas: string[],
  account: { prefs?: Record<string, unknown> } | undefined,
) {
  const queryClient = useQueryClient()
  const [selectedSchema, setSelectedSchemaState] = useState<string | null>(null)
  const initializedDatabaseIdRef = useRef<string | null>(null)

  useEffect(() => {
    if (!databaseId) return
    if (initializedDatabaseIdRef.current === databaseId) return
    if (!account) return

    const next = resolvePostgresSelectedSchema({
      schemas: knownSchemas,
      persisted: parsePostgresSelectedSchema(
        account.prefs as Record<string, unknown> | undefined,
        databaseId,
      ),
    })
    setSelectedSchemaState(next)
    initializedDatabaseIdRef.current = databaseId
  }, [account, databaseId, knownSchemas])

  useEffect(() => {
    initializedDatabaseIdRef.current = null
  }, [databaseId])

  useEffect(() => {
    if (selectedSchema || knownSchemas.length === 0) return
    setSelectedSchemaState(
      resolvePostgresSelectedSchema({ schemas: knownSchemas, persisted: null }),
    )
  }, [knownSchemas, selectedSchema])

  const setSelectedSchema = useCallback(
    (schema: string) => {
      const trimmed = schema.trim()
      if (!trimmed) return
      setSelectedSchemaState(trimmed)
      if (!databaseId || !account) return

      void updateAccountPrefs({
        ...(account.prefs ?? {}),
        ...buildPostgresSelectedSchemaPrefs(databaseId, trimmed),
      })
        .then((updatedAccount) => {
          syncConsoleAccountAfterMutation(queryClient, {
            apiResult: updatedAccount,
          })
        })
        .catch(() => {
          /* keep local selection on prefs write failure */
        })
    },
    [account, databaseId, queryClient],
  )

  return { selectedSchema, setSelectedSchema }
}

export function usePostgresSavedQueryScope(
  databaseId: string | null | undefined,
  account: { prefs?: Record<string, unknown> } | undefined,
  teamId: string | null | undefined,
) {
  const queryClient = useQueryClient()
  const { isLoading: teamLoading } = useConsoleTeam(teamId)
  const { userQueries, teamQueries, hasTeamLevel } = usePostgresSavedQueries(
    databaseId,
    account,
    teamId,
  )

  const [savedQueryLevel, setSavedQueryLevelState] =
    useState<PostgresSavedQueryLevel>('user')
  const initializedDatabaseIdRef = useRef<string | null>(null)

  useEffect(() => {
    if (!databaseId) return
    if (initializedDatabaseIdRef.current === databaseId) return
    if (!account) return
    if (teamId && teamLoading) return

    const next = resolvePostgresSavedQueriesScope({
      persisted: parsePostgresSavedQueriesScope(
        account.prefs as Record<string, unknown> | undefined,
        databaseId,
      ),
      hasTeamLevel,
      userQueryCount: userQueries.length,
      teamQueryCount: teamQueries.length,
    })

    setSavedQueryLevelState(next)
    initializedDatabaseIdRef.current = databaseId
  }, [
    account,
    databaseId,
    hasTeamLevel,
    teamId,
    teamLoading,
    teamQueries.length,
    userQueries.length,
  ])

  useEffect(() => {
    initializedDatabaseIdRef.current = null
  }, [databaseId])

  const setSavedQueryLevel = useCallback(
    (level: PostgresSavedQueryLevel) => {
      setSavedQueryLevelState(level)
      if (!databaseId || !account) return

      void updateAccountPrefs({
        ...(account.prefs ?? {}),
        ...buildPostgresSavedQueriesScopePrefs(databaseId, level),
      })
        .then((updatedAccount) => {
          syncConsoleAccountAfterMutation(queryClient, {
            apiResult: updatedAccount,
          })
        })
        .catch(() => {
          /* keep local selection on prefs write failure */
        })
    },
    [account, databaseId, queryClient],
  )

  return { savedQueryLevel, setSavedQueryLevel }
}

/**
 * Saved PostgreSQL queries for a dedicated database (account + team prefs).
 */
export function usePostgresSavedQueries(
  databaseId: string | null | undefined,
  account: { prefs?: Record<string, unknown> } | undefined,
  teamId: string | null | undefined,
) {
  const queryClient = useQueryClient()
  const { data: team } = useConsoleTeam(teamId)
  const updateTeamPrefs = useUpdateConsoleTeamPrefs(teamId)

  const userQueries: SavedPostgresQuery[] =
    databaseId && account?.prefs
      ? parsePostgresSavedQueries(account.prefs, databaseId)
      : []

  const teamQueries: SavedPostgresQuery[] =
    databaseId && team?.prefs && teamId
      ? parsePostgresSavedQueries(
          team.prefs as Record<string, unknown>,
          databaseId,
        )
      : []

  const addUserMutation = useMutation({
    mutationFn: async ({ name, sql }: { name: string; sql: string }) => {
      const currentAccount = getConsoleAccountFromCache(queryClient)
      if (!currentAccount || !databaseId) {
        throw new Error('Account or database not available')
      }
      const trimmedSql = sql.trim()
      if (!trimmedSql) throw new Error('SQL is required')
      if (trimmedSql.length > MAX_SAVED_POSTGRES_QUERY_SQL_CHARS) {
        throw new Error('Query is too large to save')
      }
      const current = parsePostgresSavedQueries(currentAccount.prefs, databaseId)
      const trimmedName = name.trim().slice(0, MAX_SAVED_POSTGRES_QUERY_NAME_LENGTH)
      if (!trimmedName) throw new Error('Name is required')
      if (current.length >= MAX_SAVED_POSTGRES_QUERIES) {
        throw new Error(`Maximum ${MAX_SAVED_POSTGRES_QUERIES} saved queries`)
      }
      const next: SavedPostgresQuery[] = [
        { id: crypto.randomUUID(), name: trimmedName, sql: trimmedSql },
        ...current,
      ]
      return await updateAccountPrefs({
        ...currentAccount.prefs,
        ...buildPostgresSavedQueriesPrefs(databaseId, next),
      })
    },
    onSuccess: (updatedAccount) => {
      syncConsoleAccountAfterMutation(queryClient, {
        apiResult: updatedAccount,
      })
    },
  })

  const addTeamMutation = useMutation({
    mutationFn: async ({ name, sql }: { name: string; sql: string }) => {
      const currentTeam = queryClient.getQueryData<{
        prefs?: Record<string, unknown>
      }>(['team', 'console', teamId])
      if (!currentTeam || !databaseId || !teamId) {
        throw new Error('Team or database not available')
      }
      const trimmedSql = sql.trim()
      if (!trimmedSql) throw new Error('SQL is required')
      if (trimmedSql.length > MAX_SAVED_POSTGRES_QUERY_SQL_CHARS) {
        throw new Error('Query is too large to save')
      }
      const current = parsePostgresSavedQueries(
        currentTeam.prefs as Record<string, unknown>,
        databaseId,
      )
      const trimmedName = name.trim().slice(0, MAX_SAVED_POSTGRES_QUERY_NAME_LENGTH)
      if (!trimmedName) throw new Error('Name is required')
      if (current.length >= MAX_SAVED_POSTGRES_QUERIES) {
        throw new Error(`Maximum ${MAX_SAVED_POSTGRES_QUERIES} saved queries`)
      }
      const next: SavedPostgresQuery[] = [
        { id: crypto.randomUUID(), name: trimmedName, sql: trimmedSql },
        ...current,
      ]
      await updateTeamPrefs.mutateAsync({
        ...(currentTeam.prefs as Record<string, unknown>),
        ...buildPostgresSavedQueriesPrefs(databaseId, next),
      })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['team', 'console', teamId] })
    },
  })

  const deleteUserMutation = useMutation({
    mutationFn: async (id: string) => {
      const currentAccount = getConsoleAccountFromCache(queryClient)
      if (!currentAccount || !databaseId) {
        throw new Error('Account or database not available')
      }
      const current = parsePostgresSavedQueries(currentAccount.prefs, databaseId)
      const next = current.filter((query) => query.id !== id)
      return await updateAccountPrefs({
        ...currentAccount.prefs,
        ...buildPostgresSavedQueriesPrefs(databaseId, next),
      })
    },
    onSuccess: (updatedAccount) => {
      syncConsoleAccountAfterMutation(queryClient, {
        apiResult: updatedAccount,
      })
    },
  })

  const deleteTeamMutation = useMutation({
    mutationFn: async (id: string) => {
      const currentTeam = queryClient.getQueryData<{
        prefs?: Record<string, unknown>
      }>(['team', 'console', teamId])
      if (!currentTeam || !databaseId || !teamId) {
        throw new Error('Team or database not available')
      }
      const current = parsePostgresSavedQueries(
        currentTeam.prefs as Record<string, unknown>,
        databaseId,
      )
      const next = current.filter((query) => query.id !== id)
      await updateTeamPrefs.mutateAsync({
        ...(currentTeam.prefs as Record<string, unknown>),
        ...buildPostgresSavedQueriesPrefs(databaseId, next),
      })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['team', 'console', teamId] })
    },
  })

  const addSavedQuery = async (args: {
    name: string
    sql: string
    level: PostgresSavedQueryLevel
  }) => {
    if (args.level === 'team' && teamId) {
      return addTeamMutation.mutateAsync({
        name: args.name,
        sql: args.sql,
      })
    }
    return addUserMutation.mutateAsync({
      name: args.name,
      sql: args.sql,
    })
  }

  const deleteSavedQuery = async (
    id: string,
    level: PostgresSavedQueryLevel,
  ) => {
    if (level === 'team' && teamId) {
      return deleteTeamMutation.mutateAsync(id)
    }
    return deleteUserMutation.mutateAsync(id)
  }

  return {
    userQueries,
    teamQueries,
    addSavedQuery,
    deleteSavedQuery,
    isAdding: addUserMutation.isPending || addTeamMutation.isPending,
    isDeleting: deleteUserMutation.isPending || deleteTeamMutation.isPending,
    hasTeamLevel: !!teamId,
  }
}

const POSTGRES_QUERY_HISTORY_PERSIST_DEBOUNCE_MS = 400

/**
 * Persisted PostgreSQL query history for a database
 * (`console.postgresQueryHistory.<databaseId>`).
 */
export function usePostgresQueryHistory(
  databaseId: string | null | undefined,
  account: { prefs?: Record<string, unknown> } | undefined,
) {
  const queryClient = useQueryClient()
  const recentQueries: PostgresQueryHistoryEntry[] =
    databaseId && account?.prefs
      ? parsePostgresQueryHistory(account.prefs, databaseId)
      : []

  const persistTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null)

  const updateMutation = useMutation({
    mutationFn: async (value: PostgresQueryHistoryEntry[]) => {
      if (!account || !databaseId) {
        throw new Error('Account or database not available')
      }
      return await updateAccountPrefs(
        mergePostgresQueryHistoryIntoPrefs(
          (account.prefs ?? {}) as UserPrefs,
          databaseId,
          value,
        ),
      )
    },
    onSuccess: (updatedAccount) => {
      syncConsoleAccountAfterMutation(queryClient, {
        apiResult: updatedAccount,
      })
    },
  })

  const persistRecentQueries = useCallback(
    (value: PostgresQueryHistoryEntry[]) => {
      if (!account || !databaseId) return
      const patch = mergePostgresQueryHistoryIntoPrefs(
        (account.prefs ?? {}) as UserPrefs,
        databaseId,
        value,
      )
      queryClient.setQueriesData<{ prefs?: Record<string, unknown> }>(
        { queryKey: ['account', 'console'] },
        (current) =>
          current
            ? {
                ...current,
                prefs: { ...current.prefs, ...patch },
              }
            : current,
      )
      if (persistTimerRef.current !== null) {
        clearTimeout(persistTimerRef.current)
      }
      persistTimerRef.current = setTimeout(() => {
        persistTimerRef.current = null
        updateMutation.mutate(value)
      }, POSTGRES_QUERY_HISTORY_PERSIST_DEBOUNCE_MS)
    },
    [account, databaseId, queryClient, updateMutation],
  )

  useEffect(() => {
    return () => {
      if (persistTimerRef.current !== null) {
        clearTimeout(persistTimerRef.current)
      }
    }
  }, [])

  return { recentQueries, persistRecentQueries }
}

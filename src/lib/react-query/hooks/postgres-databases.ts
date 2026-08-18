import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import {
  infiniteQueryOptions,
  keepPreviousData,
  queryOptions,
  useInfiniteQuery,
  useMutation,
  useQuery,
  useQueryClient,
  type QueryClient,
} from '@tanstack/react-query'
import { Query } from '@appwrite.io/console'
import type { Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import {
  invalidateDatabaseModel,
  refetchProjectDatabaseLists,
} from './databases'
import {
  mapDedicatedDatabaseCredentials,
  type DedicatedDatabaseConnectionList,
  type DedicatedDatabaseCredentials,
} from '@/lib/databases/dedicated-engine'
import { POSTGRES_ACTIVE_CONNECTIONS_SQL } from '@/lib/postgres-metrics-sql'
import { parsePostgresActiveConnections } from '@/lib/postgres-metrics'
import {
  explainPostgresDatabaseQuery,
} from '@/lib/postgres-query-explanation'
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
  buildPostgresTableAutocompleteColumnsSql,
  buildPostgresTableColumnsForRowsSql,
  buildPostgresTableColumnsSql,
  buildPostgresSchemaEnumsSql,
  buildPostgresTableIndexesSql,
  buildPostgresTableInfoSql,
  POSTGRES_SIDEBAR_LIST_PAGE_SIZE,
  type PostgresColumnRow,
  type PostgresListSchemasOptions,
  type PostgresListTablesOptions,
  type PostgresSchemaRow,
  type PostgresTableColumnRow,
  type PostgresSchemaEnumRow,
  type PostgresTableIndexRow,
  type PostgresTableInfoRow,
  type PostgresTableRow,
  sortPostgresTableColumns,
  sortPostgresSchemaEnums,
  sortPostgresTableIndexes,
  postgresRelationSupportsRowCtid,
} from '@/lib/postgres-sql'
import { parsePostgresTableId, postgresTableId, quotePostgresIdentifier } from '@/lib/postgres-database-routes'
import { parsePostgresEnumValues } from '@/lib/postgres-enum-metadata'
import {
  buildPostgresTablePoliciesSql,
  buildPostgresTableRlsStatusSql,
  isPostgresTruthyFlag,
  type PostgresTablePolicyRow,
  type PostgresTableRlsRow,
} from '@/lib/postgres-rls'
import {
  buildPostgresListRolesSql,
  type PostgresRoleRow,
} from '@/lib/postgres-roles'
import {
  buildPostgresVisualizerColumnsBatchSql,
  buildPostgresVisualizerExternalColumnsSql,
  buildPostgresVisualizerForeignKeysSql,
  POSTGRES_VISUALIZER_RELATIONS_BATCH_SIZE,
  type PostgresVisualizerColumnRow,
  type PostgresVisualizerForeignKeyRow,
  type PostgresVisualizerRelationRef,
} from '@/lib/postgres-visualizer-sql'
import {
  buildPostgresCountRowsSql,
  buildPostgresDeleteRowSql,
  buildPostgresInsertRowSql,
  buildSyncPostgresSerialSequencesSql,
  isPostgresDuplicatePrimaryKeyError,
  isPostgresSequenceBackedColumn,
  buildPostgresSelectRowsSql,
  buildPostgresUpdateRowSql,
  POSTGRES_ROW_CTID_COLUMN,
  type PostgresRowIdentity,
} from '@/lib/postgres-row-sql'
import {
  filterPostgresRowCreateValues,
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
  buildPostgresSavedQueriesSortPrefs,
  buildPostgresSelectedSchemaPrefs,
  buildPostgresSidebarPanelPrefs,
  buildPostgresSidebarTablesSortPrefs,
  buildPostgresSqlEditorStatePrefs,
  getPostgresSqlEditorStateKey,
  MAX_SAVED_POSTGRES_QUERIES,
  MAX_SAVED_POSTGRES_QUERY_NAME_LENGTH,
  MAX_SAVED_POSTGRES_QUERY_SQL_CHARS,
  mergePostgresQueryHistoryIntoPrefs,
  mergePostgresSqlEditorStateIntoPrefs,
  parsePostgresQueryHistory,
  parsePostgresSavedQueries,
  parsePostgresSavedQueriesScope,
  parsePostgresSavedQueriesSort,
  parsePostgresSelectedSchema,
  parsePostgresSidebarPanel,
  parsePostgresSidebarTablesSort,
  parsePostgresSqlEditorState,
  POSTGRES_SAVED_QUERIES_DEFAULT_SORT,
  POSTGRES_SIDEBAR_TABLES_DEFAULT_SORT,
  POSTGRES_SIDEBAR_PANEL_DEFAULT,
  resolvePostgresSavedQueriesScope,
  resolvePostgresSelectedSchema,
  type PostgresSavedQueriesSort,
  type PostgresSidebarPanelPreference,
  type PostgresSidebarTablesSort,
  type PersistedPostgresSqlEditorState,
  type PostgresQueryHistoryEntry,
  type SavedPostgresQuery,
  type UserPrefs,
} from '@/lib/user-prefs-keys'
import {
  getConsoleAccountFromCache,
  syncConsoleAccountAfterMutation,
  updateAccountPrefs,
  consoleAccountQueryOptions,
} from './auth'
import { useConsoleImpersonationRevision } from '@/hooks/use-console-impersonation-revision'
import { useConsoleTeam, useUpdateConsoleTeamPrefs } from './teams'
import { DEFAULT_STALE_TIME } from './constants'
import {
  DEDICATED_DATABASE_STATUS_POLL_INTERVAL_MS,
  coerceTrimmedString,
  shouldPollDedicatedDatabaseStatus,
} from '@/lib/databases/dedicated-database-status'
import { requireOperationalDatabase } from '@/lib/databases/dedicated-database-write-lock'
import { matchesNativeEngine } from '@/lib/databases/native-database-engines'
import {
  ensureConsoleSqlApiStatements,
  isSqlApiDdlBlockedError,
} from '@/lib/databases/sql-api-statements'

function isPostgresEngine(engine: string | undefined): boolean {
  return matchesNativeEngine(engine, 'postgres')
}

export { isPostgresEngine }

async function fetchPostgresDatabaseFromList(
  projectId: string,
  databaseId: string,
): Promise<Models.DedicatedDatabase | null> {
  const response = await sdk.forProject(projectId).postgresql.list({
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
      .postgresql.get({ databaseId })
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

export async function executePostgresDatabaseSql(
  projectId: string,
  databaseId: string,
  sql: string,
  timeoutSeconds?: number,
): Promise<Models.DedicatedDatabaseExecution> {
  const run = async () => {
    const execution = await sdk.forProject(projectId).postgresql.createExecution({
      databaseId,
      sql: wrapPostgresSqlForDisplay(sql),
      timeoutSeconds,
    })
    return normalizePostgresExecutionResult(execution)
  }

  try {
    return await run()
  } catch (error) {
    if (!isSqlApiDdlBlockedError(error)) throw error
    await ensureConsoleSqlApiStatements(
      projectId,
      databaseId,
      'postgresql',
    ).catch(() => {
      /* Retry the statement even if the allow-list PATCH is a no-op */
    })
    return await run()
  }
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
  const schemas = rows
    .map((row) => coerceTrimmedString(row.schema_name))
    .filter(Boolean)
  const total = parsePostgresCountTotal(countExecution, schemas.length)

  return {
    schemas,
    total,
    page,
    limit,
    hasMore: offset + schemas.length < total,
  }
}

export async function fetchPostgresTablesPage(
  projectId: string,
  databaseId: string,
  options: {
    schema?: string
    search?: string
    page?: number
    limit?: number
  },
) {
  const schema = options.schema?.trim() || undefined
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

export async function fetchFirstPostgresTable(
  projectId: string,
  databaseId: string,
): Promise<PostgresTableRow | null> {
  const page = await fetchPostgresTablesPage(projectId, databaseId, {
    limit: 1,
    page: 0,
  })
  return page.tables[0] ?? null
}

export async function fetchPostgresTableAutocompleteColumns(
  projectId: string,
  databaseId: string,
  schema: string,
  table: string,
): Promise<PostgresColumnRow[]> {
  const execution = await executePostgresDatabaseSql(
    projectId,
    databaseId,
    buildPostgresTableAutocompleteColumnsSql(schema, table),
  )
  return executionResultRows<PostgresColumnRow>(execution).filter(
    (row) => row.table_schema && row.table_name && row.column_name,
  )
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

export type PostgresTableRowColumnsResult = {
  columns: PostgresTableColumnRow[]
  total: number
  exists: boolean
  supportsRowCtid: boolean
}

type PostgresTableRowColumnSqlRow = PostgresTableColumnRow & {
  rel_kind?: string | null
}

export async function fetchPostgresTableRowColumns(
  projectId: string,
  databaseId: string,
  tableId: string,
): Promise<PostgresTableRowColumnsResult> {
  const { schema, table } = parsePostgresTableId(tableId)
  const execution = await executePostgresDatabaseSql(
    projectId,
    databaseId,
    buildPostgresTableColumnsForRowsSql(schema, table),
  )
  const rows = executionResultRows<PostgresTableRowColumnSqlRow>(execution)
  const relKind = rows[0]?.rel_kind ?? null
  const columns = sortPostgresTableColumns(
    rows.filter((row) => row.column_name),
  )
  return {
    columns,
    total: columns.length,
    exists: relKind != null,
    supportsRowCtid: postgresRelationSupportsRowCtid(relKind),
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

export async function fetchPostgresSchemaEnums(
  projectId: string,
  databaseId: string,
  schema: string,
) {
  const execution = await executePostgresDatabaseSql(
    projectId,
    databaseId,
    buildPostgresSchemaEnumsSql(schema),
  )
  const rows = executionResultRows<PostgresSchemaEnumRow>(execution)
  const enums = sortPostgresSchemaEnums(
    rows
      .filter((row) => row.enum_name)
      .map((row) => ({
        ...row,
        values: parsePostgresEnumValues(row.enum_values),
      })),
  )
  return {
    enums,
    total: enums.length,
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

export async function fetchPostgresTableRls(
  projectId: string,
  databaseId: string,
  tableId: string,
) {
  const { schema, table } = parsePostgresTableId(tableId)
  const execution = await executePostgresDatabaseSql(
    projectId,
    databaseId,
    buildPostgresTableRlsStatusSql(schema, table),
  )
  const rows = executionResultRows<PostgresTableRlsRow>(execution)
  const row = rows[0]
  return {
    rowSecurityEnabled: isPostgresTruthyFlag(row?.row_security_enabled),
    forceRowSecurity: isPostgresTruthyFlag(row?.force_row_security),
  }
}

export async function fetchPostgresTablePolicies(
  projectId: string,
  databaseId: string,
  tableId: string,
) {
  const { schema, table } = parsePostgresTableId(tableId)
  const execution = await executePostgresDatabaseSql(
    projectId,
    databaseId,
    buildPostgresTablePoliciesSql(schema, table),
  )
  const policies = executionResultRows<PostgresTablePolicyRow>(execution)
  return {
    policies,
    total: policies.length,
  }
}

export async function fetchPostgresRoles(
  projectId: string,
  databaseId: string,
) {
  const execution = await executePostgresDatabaseSql(
    projectId,
    databaseId,
    buildPostgresListRolesSql(),
  )
  const roles = executionResultRows<PostgresRoleRow>(execution)
  return {
    roles,
    total: roles.length,
  }
}

export type PostgresVisualizerColumn = {
  name: string
  dataType: string
  udtName: string
  required: boolean
  isPrimaryKey: boolean
}

export type PostgresVisualizerRelation = {
  id: string
  schema: string
  name: string
  tableType: string
  isExternal: boolean
  columns: PostgresVisualizerColumn[]
  columnsLoaded: boolean
}

export type PostgresVisualizerRelationship = {
  from: string
  to: string
  fromColumn: string
  toColumn: string
  constraintName: string
}

function mapPostgresVisualizerColumnRow(
  row: PostgresVisualizerColumnRow,
): PostgresVisualizerColumn {
  return {
    name: row.column_name,
    dataType: row.data_type,
    udtName: row.udt_name,
    required: row.is_nullable !== 'YES',
    isPrimaryKey:
      row.is_primary_key === true || row.is_primary_key === 'true',
  }
}

function groupPostgresVisualizerColumns(
  rows: PostgresVisualizerColumnRow[],
): Map<string, PostgresVisualizerColumn[]> {
  const grouped = new Map<string, PostgresVisualizerColumn[]>()
  for (const row of rows) {
    const key = postgresTableId(row.table_schema, row.table_name)
    const columns = grouped.get(key) ?? []
    columns.push(mapPostgresVisualizerColumnRow(row))
    grouped.set(key, columns)
  }
  return grouped
}

export async function fetchPostgresVisualizerColumnsBatch(
  projectId: string,
  databaseId: string,
  schema: string,
  tableNames: string[],
) {
  if (!schema.trim() || tableNames.length === 0) {
    return new Map<string, PostgresVisualizerColumn[]>()
  }

  const execution = await executePostgresDatabaseSql(
    projectId,
    databaseId,
    buildPostgresVisualizerColumnsBatchSql(schema, tableNames),
  )
  const rows = executionResultRows<PostgresVisualizerColumnRow>(execution)
  return groupPostgresVisualizerColumns(rows)
}

export async function fetchPostgresVisualizerExternalColumns(
  projectId: string,
  databaseId: string,
  relations: PostgresVisualizerRelationRef[],
) {
  if (relations.length === 0) {
    return new Map<string, PostgresVisualizerColumn[]>()
  }

  const execution = await executePostgresDatabaseSql(
    projectId,
    databaseId,
    buildPostgresVisualizerExternalColumnsSql(relations),
  )
  const rows = executionResultRows<PostgresVisualizerColumnRow>(execution)
  return groupPostgresVisualizerColumns(rows)
}

export async function fetchPostgresVisualizerForeignKeys(
  projectId: string,
  databaseId: string,
  schema: string,
) {
  if (!schema.trim()) return [] as PostgresVisualizerForeignKeyRow[]

  const execution = await executePostgresDatabaseSql(
    projectId,
    databaseId,
    buildPostgresVisualizerForeignKeysSql(schema),
  )
  return executionResultRows<PostgresVisualizerForeignKeyRow>(execution).filter(
    (row) =>
      row.source_schema &&
      row.source_table &&
      row.source_column &&
      row.target_schema &&
      row.target_table &&
      row.target_column,
  )
}

export function postgresVisualizerForeignKeysQueryOptions(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  schema: string | null | undefined,
) {
  const normalizedSchema = schema?.trim() || undefined
  return queryOptions({
    queryKey: [
      'postgres-visualizer',
      'foreign-keys',
      'project',
      projectId,
      databaseId,
      normalizedSchema,
    ],
    queryFn: () =>
      fetchPostgresVisualizerForeignKeys(
        projectId!,
        databaseId!,
        normalizedSchema!,
      ),
    enabled: !!projectId && !!databaseId && !!normalizedSchema,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId && databaseId && normalizedSchema ? 5 * 60 * 1000 : 0,
  })
}

export type PostgresTableRowsListParams = {
  search?: string
  filterKeys?: CompactFilterKey[]
  orderBy?: string
  orderDirection?: 'asc' | 'desc'
}

type FetchPostgresTableRowsOptions = {
  tableColumns?: PostgresTableColumnRow[]
  supportsRowCtid?: boolean
}

async function resolvePostgresTableColumnsForRows(
  projectId: string,
  databaseId: string,
  tableId: string,
  tableColumns?: PostgresTableColumnRow[],
) {
  if (tableColumns) {
    return {
      columns: tableColumns,
      total: tableColumns.length,
    }
  }
  return fetchPostgresTableRowColumns(projectId, databaseId, tableId)
}

function buildPostgresRowsWhereClause(
  params: PostgresTableRowsListParams | undefined,
  columns: PostgresTableColumnRow[],
): string | undefined {
  return buildPostgresRowsListWhereClause(
    params?.filterKeys,
    params?.search,
    columns,
  )
}

function buildPostgresRowsOrderClause(
  columns: PostgresTableColumnRow[],
  sortBy?: string,
  orderDirection: 'asc' | 'desc' = 'asc',
  supportsRowCtid = true,
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
  if (supportsRowCtid) {
    return `${quotePostgresIdentifier(POSTGRES_ROW_CTID_COLUMN)} ${direction}`
  }
  const sortableColumns = columns.filter(
    (column) => column.column_name !== POSTGRES_ROW_CTID_COLUMN,
  )
  if (sortableColumns.length === 0) return undefined
  return sortableColumns
    .map((column) => `${quotePostgresIdentifier(column.column_name)} ${direction}`)
    .join(', ')
}

export async function fetchPostgresTableRows(
  projectId: string,
  databaseId: string,
  tableId: string,
  page: number,
  limit: number,
  params?: PostgresTableRowsListParams,
  options?: FetchPostgresTableRowsOptions,
) {
  const columnsResult = await resolvePostgresTableColumnsForRows(
    projectId,
    databaseId,
    tableId,
    options?.tableColumns,
  )
  const supportsRowCtid = options?.supportsRowCtid !== false
  const whereClause = buildPostgresRowsWhereClause(params, columnsResult.columns)
  const orderByClause = buildPostgresRowsOrderClause(
    columnsResult.columns,
    params?.orderBy,
    params?.orderDirection ?? 'asc',
    supportsRowCtid,
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
        includeCtid: supportsRowCtid,
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

export function postgresTableAutocompleteColumnsQueryOptions(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  schema: string | null | undefined,
  table: string | null | undefined,
) {
  const normalizedSchema = schema?.trim() ?? ''
  const normalizedTable = table?.trim() ?? ''
  return queryOptions({
    queryKey: [
      'postgres-autocomplete-columns',
      'project',
      projectId,
      databaseId,
      normalizedSchema,
      normalizedTable,
    ],
    queryFn: () =>
      fetchPostgresTableAutocompleteColumns(
        projectId!,
        databaseId!,
        normalizedSchema,
        normalizedTable,
      ),
    enabled:
      !!projectId &&
      !!databaseId &&
      !!normalizedSchema &&
      !!normalizedTable,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime:
      projectId && databaseId && normalizedSchema && normalizedTable
        ? 5 * 60 * 1000
        : 0,
  })
}

/**
 * List active sessions via postgresql.createExecution + pg_stat_activity.
 * Maps into the legacy DedicatedDatabaseConnectionList shape for callers that
 * still use this query key; the Connections tab prefers the richer
 * PostgresActiveConnectionRow list from postgres-metrics.
 */
export async function fetchPostgresDatabaseConnections(
  projectId: string,
  databaseId: string,
): Promise<DedicatedDatabaseConnectionList> {
  const execution = await executePostgresDatabaseSql(
    projectId,
    databaseId,
    POSTGRES_ACTIVE_CONNECTIONS_SQL,
    30,
  )
  const rows = parsePostgresActiveConnections(execution)
  const connections = rows.map((row) => {
    const username = row.username?.trim() || ''
    return {
      $id: String(row.pid),
      username,
      database: row.database?.trim() || '',
      role: username,
      $createdAt: row.backendStart?.trim() || '',
    }
  })
  return { connections, total: connections.length }
}

export async function fetchPostgresDatabaseCredentials(
  projectId: string,
  databaseId: string,
): Promise<DedicatedDatabaseCredentials> {
  const database = await fetchPostgresDatabase(projectId, databaseId)
  if (!database) {
    throw new Error('Database not found')
  }
  return mapDedicatedDatabaseCredentials(database)
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
    return await sdk.forProject(projectId).postgresql.getPooler({
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

export type UpdatePostgresDatabaseInput = {
  databaseId: string
  name?: string
  status?: string
  specification?: string
  replicas?: number
  syncMode?: string
  networkIdleTimeoutSeconds?: number
  networkIPAllowlist?: string[]
  idleTimeoutMinutes?: number
  pitr?: boolean
  pitrRetentionDays?: number
  storageAutoscaling?: boolean
  storageAutoscalingThresholdPercent?: number
  storageAutoscalingMaxGb?: number
}

export async function updatePostgresDatabase(
  projectId: string,
  input: UpdatePostgresDatabaseInput,
) {
  const { databaseId, ...params } = input
  return sdk.forProject(projectId).postgresql.update({
    databaseId,
    ...params,
  })
}

export async function updatePostgresDatabaseMaintenance(
  projectId: string,
  databaseId: string,
  day: string,
  hourUtc: number,
) {
  return sdk.forProject(projectId).postgresql.updateMaintenance({
    databaseId,
    day,
    hourUtc,
  })
}

export async function deletePostgresDatabase(
  projectId: string,
  databaseId: string,
) {
  return sdk.forProject(projectId).postgresql.delete({ databaseId })
}

export async function resetPostgresDatabaseCredentials(
  projectId: string,
  databaseId: string,
) {
  return sdk.forProject(projectId).postgresql.updateCredentials({ databaseId })
}

export function useUpdatePostgresDatabase(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (input: Omit<UpdatePostgresDatabaseInput, 'databaseId'>) => {
      requireOperationalDatabase(queryClient, projectId!, databaseId!)
      return updatePostgresDatabase(projectId!, {
        databaseId: databaseId!,
        ...input,
      })
    },
    onSuccess: async (database) => {
      if (!projectId || !databaseId) return
      queryClient.setQueryData(
        postgresDatabaseQueryOptions(projectId, databaseId).queryKey,
        database,
      )
      // Keep the shared switcher / databases index in sync (console.listDatabases
      // + dedicated lists). Invalidating dedicated alone left the droplist stale.
      await refetchProjectDatabaseLists(queryClient, projectId)
    },
  })
}

export function useUpdatePostgresDatabaseMaintenance(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({
      day,
      hourUtc,
    }: {
      day: string
      hourUtc: number
    }) => {
      requireOperationalDatabase(queryClient, projectId!, databaseId!)
      return updatePostgresDatabaseMaintenance(projectId!, databaseId!, day, hourUtc)
    },
    onSuccess: async (database) => {
      if (!projectId || !databaseId) return
      queryClient.setQueryData(
        postgresDatabaseQueryOptions(projectId, databaseId).queryKey,
        database,
      )
    },
  })
}

export function useResetPostgresDatabaseCredentials(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: () => {
      requireOperationalDatabase(queryClient, projectId!, databaseId!)
      return resetPostgresDatabaseCredentials(projectId!, databaseId!)
    },
    onSuccess: async (database) => {
      if (!projectId || !databaseId) return
      queryClient.setQueryData(
        postgresDatabaseQueryOptions(projectId, databaseId).queryKey,
        database,
      )
      queryClient.setQueryData(
        postgresDatabaseCredentialsQueryOptions(projectId, databaseId).queryKey,
        mapDedicatedDatabaseCredentials(database),
      )
      await queryClient.invalidateQueries({
        queryKey: ['dedicated-databases', 'project', projectId],
      })
    },
  })
}

export function useDeletePostgresDatabase(
  projectId: string | null | undefined,
) {
  const queryClient = useQueryClient()
  return useMutation({
    // Delete must stay available when the database is failed/locked so users
    // can clean up resources that never became ready.
    mutationFn: (databaseId: string) => {
      return deletePostgresDatabase(projectId!, databaseId)
    },
    onSuccess: async (_data, databaseId) => {
      if (!projectId) return
      invalidateDatabaseModel(projectId, databaseId)
      queryClient.removeQueries({
        queryKey: postgresDatabaseQueryOptions(projectId, databaseId).queryKey,
      })
      await refetchProjectDatabaseLists(queryClient, projectId)
    },
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

export function postgresTableRowColumnsQueryOptions(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  tableId: string | null | undefined,
) {
  return queryOptions({
    queryKey: [
      'postgres-table-row-columns',
      'project',
      projectId,
      databaseId,
      tableId,
    ],
    queryFn: () =>
      fetchPostgresTableRowColumns(projectId!, databaseId!, tableId!),
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

export function postgresSchemaEnumsQueryOptions(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  schema: string | null | undefined,
) {
  return queryOptions({
    queryKey: [
      'postgres-schema-enums',
      'project',
      projectId,
      databaseId,
      schema,
    ],
    queryFn: () =>
      fetchPostgresSchemaEnums(projectId!, databaseId!, schema!),
    enabled: !!projectId && !!databaseId && !!schema,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId && databaseId && schema ? 5 * 60 * 1000 : 0,
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

export function postgresTableRlsQueryOptions(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  tableId: string | null | undefined,
) {
  return queryOptions({
    queryKey: [
      'postgres-table-rls',
      'project',
      projectId,
      databaseId,
      tableId,
    ],
    queryFn: () => fetchPostgresTableRls(projectId!, databaseId!, tableId!),
    enabled: !!projectId && !!databaseId && !!tableId && tableId !== '-',
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId && databaseId && tableId ? 5 * 60 * 1000 : 0,
  })
}

export function postgresTablePoliciesQueryOptions(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  tableId: string | null | undefined,
) {
  return queryOptions({
    queryKey: [
      'postgres-table-policies',
      'project',
      projectId,
      databaseId,
      tableId,
    ],
    queryFn: () =>
      fetchPostgresTablePolicies(projectId!, databaseId!, tableId!),
    enabled: !!projectId && !!databaseId && !!tableId && tableId !== '-',
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId && databaseId && tableId ? 5 * 60 * 1000 : 0,
  })
}

export function postgresRolesQueryOptions(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
) {
  return queryOptions({
    queryKey: ['postgres-roles', 'project', projectId, databaseId],
    queryFn: () => fetchPostgresRoles(projectId!, databaseId!),
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
    queryFn: async ({ client }) => {
      const rowColumns = await client.ensureQueryData(
        postgresTableRowColumnsQueryOptions(projectId!, databaseId!, tableId!),
      )
      return fetchPostgresTableRows(
        projectId!,
        databaseId!,
        tableId!,
        page,
        limit,
        params,
        {
          tableColumns: rowColumns.columns,
          supportsRowCtid: rowColumns.supportsRowCtid,
        },
      )
    },
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
  const { columns } = await fetchPostgresTableRowColumns(
    projectId,
    databaseId,
    tableId,
  )
  const filteredValues = filterPostgresRowCreateValues(values, columns)
  const sql = buildPostgresInsertRowSql(tableId, filteredValues)

  // When serial/identity columns are omitted (left blank), Postgres uses
  // DEFAULT nextval(). If an earlier row used an explicit ID (or data was
  // imported), the sequence can lag behind MAX(id) and hit users_pkey.
  // Sync sequences before insert, and once more on duplicate-key errors.
  const omittedSequenceColumns = columns.filter(
    (column) =>
      isPostgresSequenceBackedColumn(column) &&
      !Object.prototype.hasOwnProperty.call(filteredValues, column.column_name),
  )
  const syncSql =
    omittedSequenceColumns.length > 0
      ? buildSyncPostgresSerialSequencesSql(tableId, omittedSequenceColumns)
      : null

  const runSync = async () => {
    if (!syncSql) return
    await executePostgresDatabaseSql(projectId, databaseId, syncSql)
  }

  await runSync()

  const runInsert = () => executePostgresDatabaseSql(projectId, databaseId, sql)

  try {
    return await runInsert()
  } catch (error) {
    if (!syncSql || !isPostgresDuplicatePrimaryKeyError(error)) {
      throw error
    }
    await runSync()
    return await runInsert()
  }
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

export async function deletePostgresTableRows(
  projectId: string,
  databaseId: string,
  tableId: string,
  identities: PostgresRowIdentity[],
) {
  await Promise.all(
    identities.map((identity) =>
      deletePostgresTableRow(projectId, databaseId, tableId, identity),
    ),
  )
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
    }) => {
      requireOperationalDatabase(queryClient, projectId, databaseId)
      return updatePostgresTableRow(
        projectId,
        databaseId,
        tableId,
        params.identity,
        params.changes,
      )
    },
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
    mutationFn: (values: Record<string, RowCellValue>) => {
      requireOperationalDatabase(queryClient, projectId, databaseId)
      return createPostgresTableRow(projectId, databaseId, tableId, values)
    },
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: ['postgres-table-rows', 'project', projectId, databaseId, tableId],
      })
      await queryClient.refetchQueries({
        queryKey: ['postgres-table-columns', 'project', projectId, databaseId, tableId],
      })
      await queryClient.refetchQueries({
        queryKey: ['postgres-table-row-columns', 'project', projectId, databaseId, tableId],
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
    mutationFn: (identity: PostgresRowIdentity) => {
      requireOperationalDatabase(queryClient, projectId, databaseId)
      return deletePostgresTableRow(projectId, databaseId, tableId, identity)
    },
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: ['postgres-table-rows', 'project', projectId, databaseId, tableId],
      })
    },
  })
}

export function useDeletePostgresTableRows(
  projectId: string,
  databaseId: string,
  tableId: string,
) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (identities: PostgresRowIdentity[]) => {
      requireOperationalDatabase(queryClient, projectId, databaseId)
      return deletePostgresTableRows(projectId, databaseId, tableId, identities)
    },
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
    mutationFn: (edits: PendingPostgresRowCellEdit[]) => {
      requireOperationalDatabase(queryClient, projectId, databaseId)
      return commitPostgresRowEdits(projectId, databaseId, tableId, edits)
    },
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
  const queryClient = useQueryClient()
  const { data, isLoading, error, refetch, isFetching } = useQuery({
    ...postgresDatabaseQueryOptions(projectId, databaseId),
    refetchInterval: (query) =>
      shouldPollDedicatedDatabaseStatus(query.state.data?.status)
        ? DEDICATED_DATABASE_STATUS_POLL_INTERVAL_MS
        : false,
  })

  // Keep list/selector badges in sync when detail polling sees a status change.
  useEffect(() => {
    if (!projectId || !databaseId || !data?.status) return
    const nextStatus = data.status
    queryClient.setQueryData(
      ['dedicated-databases', 'project', projectId],
      (
        prev:
          | { databases: Models.DedicatedDatabase[]; total: number }
          | undefined,
      ) => {
        if (!prev?.databases?.length) return prev
        let changed = false
        const databases = prev.databases.map((db) => {
          if (db.$id !== databaseId || db.status === nextStatus) return db
          changed = true
          return { ...db, status: nextStatus }
        })
        return changed ? { ...prev, databases } : prev
      },
    )
  }, [data?.status, databaseId, projectId, queryClient])

  return { database: data ?? null, isLoading, error, refetch, isFetching }
}

function keepPreviousDataIfQueryPrefixMatches<T>(
  previousData: T | undefined,
  previousQuery: { queryKey: readonly unknown[] } | undefined,
  queryKey: readonly unknown[],
  prefixLength: number,
): T | undefined {
  if (!previousQuery) return undefined
  for (let index = 0; index < prefixLength; index += 1) {
    if (previousQuery.queryKey[index] !== queryKey[index]) return undefined
  }
  return previousData
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
      lastPage?.hasMore ? lastPage.page + 1 : undefined,
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
        schema: schema?.trim() || undefined,
        search: normalizedSearch,
        page: pageParam,
      }),
    initialPageParam: 0,
    getNextPageParam: (lastPage) =>
      lastPage?.hasMore ? lastPage.page + 1 : undefined,
    enabled: !!projectId && !!databaseId && !!schema?.trim(),
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId && databaseId && schema?.trim() ? 5 * 60 * 1000 : 0,
  })
}

export function usePostgresSidebarSchemas(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  search: string,
) {
  const normalizedSearch = search.trim()
  const sidebarQueryOptions = postgresSidebarSchemasInfiniteQueryOptions(
    projectId,
    databaseId,
    normalizedSearch,
  )
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
    ...sidebarQueryOptions,
    // Keep previous pages while searching the same database, never across DBs.
    placeholderData: (previousData, previousQuery) =>
      keepPreviousDataIfQueryPrefixMatches(
        previousData,
        previousQuery,
        sidebarQueryOptions.queryKey,
        5,
      ),
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
  const sidebarQueryOptions = postgresSidebarTablesInfiniteQueryOptions(
    projectId,
    databaseId,
    schema,
    normalizedSearch,
  )
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
    ...sidebarQueryOptions,
    // Keep previous pages while searching the same schema, never across DBs.
    placeholderData: (previousData, previousQuery) =>
      keepPreviousDataIfQueryPrefixMatches(
        previousData,
        previousQuery,
        sidebarQueryOptions.queryKey,
        6,
      ),
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

export function usePostgresSchemaEnums(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  schema: string | null | undefined,
) {
  const { data, isLoading, isFetching, error, refetch } = useQuery(
    postgresSchemaEnumsQueryOptions(projectId, databaseId, schema),
  )
  return {
    enums: data?.enums ?? [],
    total: data?.total ?? 0,
    isLoading,
    isFetching,
    error,
    refetch,
  }
}

export function usePostgresRoles(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
) {
  const { data, isLoading, isFetching, error, refetch } = useQuery(
    postgresRolesQueryOptions(projectId, databaseId),
  )
  return {
    roles: data?.roles ?? [],
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

export function usePostgresTableRls(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  tableId: string | null | undefined,
) {
  const { data, isLoading, isFetching, error, refetch } = useQuery(
    postgresTableRlsQueryOptions(projectId, databaseId, tableId),
  )
  return {
    rowSecurityEnabled: data?.rowSecurityEnabled ?? false,
    forceRowSecurity: data?.forceRowSecurity ?? false,
    isLoading,
    isFetching,
    error,
    refetch,
  }
}

export function usePostgresTablePolicies(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  tableId: string | null | undefined,
) {
  const { data, isLoading, isFetching, error, refetch } = useQuery(
    postgresTablePoliciesQueryOptions(projectId, databaseId, tableId),
  )
  return {
    policies: data?.policies ?? [],
    total: data?.total ?? 0,
    isLoading,
    isFetching,
    error,
    refetch,
  }
}

function buildPostgresVisualizerRelationships(
  foreignKeys: PostgresVisualizerForeignKeyRow[],
): PostgresVisualizerRelationship[] {
  return foreignKeys.map((row) => ({
    from: postgresTableId(row.source_schema, row.source_table),
    to: postgresTableId(row.target_schema, row.target_table),
    fromColumn: row.source_column,
    toColumn: row.target_column,
    constraintName: row.constraint_name,
  }))
}

function collectExternalPostgresVisualizerTargets(
  foreignKeys: PostgresVisualizerForeignKeyRow[],
  loadedRelationIds: Set<string>,
  activeSchema: string,
): PostgresVisualizerRelationRef[] {
  const targets = new Map<string, PostgresVisualizerRelationRef>()

  for (const row of foreignKeys) {
    const targetId = postgresTableId(row.target_schema, row.target_table)
    if (loadedRelationIds.has(targetId)) continue
    if (row.target_schema === activeSchema) continue
    targets.set(targetId, {
      schema: row.target_schema,
      table: row.target_table,
    })
  }

  return Array.from(targets.values())
}

/**
 * Gradually loads schema visualizer data: relations in batches, then columns per batch,
 * plus foreign keys and external FK targets in separate optimized queries.
 */
export function usePostgresSchemaVisualizer(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  schema: string | null | undefined,
) {
  const normalizedSchema = schema?.trim() || undefined
  const [relations, setRelations] = useState<PostgresVisualizerRelation[]>([])
  const [totalRelations, setTotalRelations] = useState(0)
  const [loadedRelations, setLoadedRelations] = useState(0)
  const [isLoadingRelations, setIsLoadingRelations] = useState(false)
  const [isLoadingColumns, setIsLoadingColumns] = useState(false)
  const [isComplete, setIsComplete] = useState(false)
  const [loadError, setLoadError] = useState<Error | null>(null)
  const loadGenerationRef = useRef(0)
  const externalTargetsLoadedRef = useRef<string | null>(null)

  const {
    data: foreignKeys = [],
    isLoading: foreignKeysLoading,
    error: foreignKeysError,
  } = useQuery(
    postgresVisualizerForeignKeysQueryOptions(
      projectId,
      databaseId,
      normalizedSchema,
    ),
  )

  useEffect(() => {
    if (!projectId || !databaseId || !normalizedSchema) {
      setRelations([])
      setTotalRelations(0)
      setLoadedRelations(0)
      setIsLoadingRelations(false)
      setIsLoadingColumns(false)
      setIsComplete(false)
      setLoadError(null)
      return
    }

    const generation = loadGenerationRef.current + 1
    loadGenerationRef.current = generation
    let cancelled = false

    async function loadSchemaVisualizer() {
      setRelations([])
      setTotalRelations(0)
      setLoadedRelations(0)
      setIsComplete(false)
      setLoadError(null)
      setIsLoadingRelations(true)
      setIsLoadingColumns(false)

      const relationMap = new Map<string, PostgresVisualizerRelation>()
      let page = 0
      let total = 0

      try {
        while (!cancelled && loadGenerationRef.current === generation) {
          const tablesPage = await fetchPostgresTablesPage(
            projectId!,
            databaseId!,
            {
              schema: normalizedSchema,
              page,
              limit: POSTGRES_VISUALIZER_RELATIONS_BATCH_SIZE,
            },
          )

          if (page === 0) {
            total = tablesPage.total
            setTotalRelations(total)
          }

          if (tablesPage.tables.length === 0) {
            break
          }

          setIsLoadingColumns(true)

          const tableNames = tablesPage.tables.map((row) => row.table_name)
          const columnsByRelation = await fetchPostgresVisualizerColumnsBatch(
            projectId!,
            databaseId!,
            normalizedSchema!,
            tableNames,
          )

          for (const table of tablesPage.tables) {
            const id = postgresTableId(table.table_schema, table.table_name)
            relationMap.set(id, {
              id,
              schema: table.table_schema,
              name: table.table_name,
              tableType: table.table_type,
              isExternal: false,
              columns: columnsByRelation.get(id) ?? [],
              columnsLoaded: true,
            })
          }

          setRelations(Array.from(relationMap.values()))
          setLoadedRelations(relationMap.size)
          setIsLoadingRelations(false)
          setIsLoadingColumns(false)

          if (!tablesPage.hasMore) {
            break
          }

          page += 1
        }

        if (cancelled || loadGenerationRef.current !== generation) return

        setIsComplete(true)
        setIsLoadingRelations(false)
        setIsLoadingColumns(false)
      } catch (error) {
        if (cancelled || loadGenerationRef.current !== generation) return
        setLoadError(
          error instanceof Error ? error : new Error('Failed to load schema'),
        )
        setIsLoadingRelations(false)
        setIsLoadingColumns(false)
      }
    }

    void loadSchemaVisualizer()

    return () => {
      cancelled = true
    }
  }, [projectId, databaseId, normalizedSchema])

  useEffect(() => {
    externalTargetsLoadedRef.current = null
  }, [normalizedSchema])

  useEffect(() => {
    if (!projectId || !databaseId || !normalizedSchema) return
    if (foreignKeysLoading || foreignKeys.length === 0) return
    if (!isComplete) return
    if (externalTargetsLoadedRef.current === normalizedSchema) return

    const loadedIds = new Set(relations.map((relation) => relation.id))
    const externalTargets = collectExternalPostgresVisualizerTargets(
      foreignKeys,
      loadedIds,
      normalizedSchema,
    )
    if (externalTargets.length === 0) {
      externalTargetsLoadedRef.current = normalizedSchema
      return
    }

    externalTargetsLoadedRef.current = normalizedSchema
    let cancelled = false

    async function loadExternalTargets() {
      try {
        const columnsByRelation = await fetchPostgresVisualizerExternalColumns(
          projectId!,
          databaseId!,
          externalTargets,
        )

        if (cancelled) return

        setRelations((current) => {
          const next = [...current]
          const existingIds = new Set(current.map((relation) => relation.id))

          for (const target of externalTargets) {
            const id = postgresTableId(target.schema, target.table)
            if (existingIds.has(id)) continue

            next.push({
              id,
              schema: target.schema,
              name: target.table,
              tableType: 'BASE TABLE',
              isExternal: true,
              columns: columnsByRelation.get(id) ?? [],
              columnsLoaded: true,
            })
            existingIds.add(id)
          }

          return next
        })
      } catch {
        /* External targets are optional for layout */
      }
    }

    void loadExternalTargets()

    return () => {
      cancelled = true
    }
  }, [
    projectId,
    databaseId,
    normalizedSchema,
    foreignKeys,
    foreignKeysLoading,
    isComplete,
    relations,
  ])

  const relationships = useMemo(
    () => buildPostgresVisualizerRelationships(foreignKeys),
    [foreignKeys],
  )

  const isLoading =
    isLoadingRelations ||
    isLoadingColumns ||
    foreignKeysLoading ||
    (!!normalizedSchema && !isComplete && relations.length === 0 && !loadError)

  return {
    relations,
    relationships,
    totalRelations,
    loadedRelations,
    isLoading,
    isLoadingRelations,
    isLoadingColumns,
    isComplete,
    error: loadError ?? foreignKeysError ?? null,
  }
}

export function useExplainPostgresSql(
  projectId: string,
  databaseId: string,
) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (query: string) => {
      requireOperationalDatabase(queryClient, projectId, databaseId)
      return explainPostgresDatabaseQuery(projectId, databaseId, query)
    },
  })
}

/**
 * Schema-affecting SQL (DDL) must not leave inactive caches in place.
 * Row/column queries use `refetchOnMount: false`, so `invalidateQueries` alone
 * only refreshes active observers and the create-row form keeps stale fields
 * until a full reload.
 */
async function refreshPostgresDatabaseCaches(
  queryClient: QueryClient,
  projectId: string,
  databaseId: string,
) {
  const schemaQueryKeys = [
    ['postgres-schemas', 'project', projectId, databaseId],
    ['postgres-tables', 'project', projectId, databaseId],
    ['postgres-autocomplete-columns', 'project', projectId, databaseId],
    ['postgres-table-rows', 'project', projectId, databaseId],
    ['postgres-table-columns', 'project', projectId, databaseId],
    ['postgres-table-row-columns', 'project', projectId, databaseId],
    ['postgres-table-indexes', 'project', projectId, databaseId],
    ['postgres-schema-enums', 'project', projectId, databaseId],
    ['postgres-table-info', 'project', projectId, databaseId],
    ['postgres-table-rls', 'project', projectId, databaseId],
    ['postgres-table-policies', 'project', projectId, databaseId],
    ['postgres-roles', 'project', projectId, databaseId],
    ['postgres-visualizer', 'project', projectId, databaseId],
  ] as const

  for (const queryKey of schemaQueryKeys) {
    // Drop inactive entries so the next mount cannot serve pre-DDL data.
    queryClient.removeQueries({ queryKey, type: 'inactive' })
  }

  await Promise.all(
    schemaQueryKeys.map((queryKey) =>
      queryClient.invalidateQueries({ queryKey }),
    ),
  )
}

export function useExecutePostgresSql(
  projectId: string,
  databaseId: string,
) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (sql: string) => {
      requireOperationalDatabase(queryClient, projectId, databaseId)
      return executePostgresDatabaseSql(projectId, databaseId, sql)
    },
    onSuccess: () =>
      refreshPostgresDatabaseCaches(queryClient, projectId, databaseId),
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
  const [selectionDatabaseId, setSelectionDatabaseId] = useState(databaseId)
  const initializedDatabaseIdRef = useRef<string | null>(null)

  const accountPrefs = useMemo(() => {
    const cachedPrefs = getConsoleAccountFromCache(queryClient)?.prefs as
      | Record<string, unknown>
      | undefined
    return {
      ...(account?.prefs ?? {}),
      ...(cachedPrefs ?? {}),
    } as Record<string, unknown>
  }, [account?.prefs, queryClient])

  if (selectionDatabaseId !== databaseId) {
    setSelectionDatabaseId(databaseId)
    setSelectedSchemaState(null)
    initializedDatabaseIdRef.current = null
  }

  useEffect(() => {
    if (!databaseId) return
    if (initializedDatabaseIdRef.current === databaseId) return

    const next = resolvePostgresSelectedSchema({
      schemas: knownSchemas,
      persisted: parsePostgresSelectedSchema(accountPrefs, databaseId),
    })
    if (!next) return

    setSelectedSchemaState(next)
    initializedDatabaseIdRef.current = databaseId
  }, [accountPrefs, databaseId, knownSchemas])

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
      if (!databaseId) return

      const currentAccount = getConsoleAccountFromCache(queryClient)
      if (!currentAccount) return

      void updateAccountPrefs({
        ...(currentAccount.prefs ?? {}),
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
    [databaseId, queryClient],
  )

  return { selectedSchema, setSelectedSchema }
}

export function usePostgresSavedQueriesSort(
  databaseId: string | null | undefined,
  account: { prefs?: Record<string, unknown> } | undefined,
) {
  const queryClient = useQueryClient()
  const consoleImpersonationRevision = useConsoleImpersonationRevision()
  const { data: consoleAccount } = useQuery(
    consoleAccountQueryOptions({ revision: consoleImpersonationRevision }),
  )

  const accountPrefs = useMemo(() => {
    const cachedPrefs = getConsoleAccountFromCache(queryClient)?.prefs as
      | Record<string, unknown>
      | undefined
    return {
      ...(account?.prefs ?? {}),
      ...(consoleAccount?.prefs ?? {}),
      ...(cachedPrefs ?? {}),
    } as Record<string, unknown>
  }, [account?.prefs, consoleAccount?.prefs, queryClient])

  const [sort, setSortState] = useState<PostgresSavedQueriesSort>(
    POSTGRES_SAVED_QUERIES_DEFAULT_SORT,
  )
  const initializedDatabaseIdRef = useRef<string | null>(null)

  useEffect(() => {
    if (!databaseId) return
    if (initializedDatabaseIdRef.current === databaseId) return

    setSortState(parsePostgresSavedQueriesSort(accountPrefs, databaseId))
    initializedDatabaseIdRef.current = databaseId
  }, [accountPrefs, databaseId])

  useEffect(() => {
    initializedDatabaseIdRef.current = null
  }, [databaseId])

  const setSort = useCallback(
    (next: PostgresSavedQueriesSort) => {
      setSortState(next)
      if (!databaseId) return

      const currentAccount = getConsoleAccountFromCache(queryClient)
      if (!currentAccount) return

      void updateAccountPrefs({
        ...(currentAccount.prefs ?? {}),
        ...buildPostgresSavedQueriesSortPrefs(databaseId, next),
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
    [databaseId, queryClient],
  )

  return { sort, setSort }
}

export function usePostgresSidebarTablesSort(
  databaseId: string | null | undefined,
  account: { prefs?: Record<string, unknown> } | undefined,
) {
  const queryClient = useQueryClient()
  const consoleImpersonationRevision = useConsoleImpersonationRevision()
  const { data: consoleAccount } = useQuery(
    consoleAccountQueryOptions({ revision: consoleImpersonationRevision }),
  )

  const accountPrefs = useMemo(() => {
    const cachedPrefs = getConsoleAccountFromCache(queryClient)?.prefs as
      | Record<string, unknown>
      | undefined
    return {
      ...(account?.prefs ?? {}),
      ...(consoleAccount?.prefs ?? {}),
      ...(cachedPrefs ?? {}),
    } as Record<string, unknown>
  }, [account?.prefs, consoleAccount?.prefs, queryClient])

  const [sort, setSortState] = useState<PostgresSidebarTablesSort>(
    POSTGRES_SIDEBAR_TABLES_DEFAULT_SORT,
  )
  const initializedDatabaseIdRef = useRef<string | null>(null)

  useEffect(() => {
    if (!databaseId) return
    if (initializedDatabaseIdRef.current === databaseId) return

    setSortState(parsePostgresSidebarTablesSort(accountPrefs, databaseId))
    initializedDatabaseIdRef.current = databaseId
  }, [accountPrefs, databaseId])

  useEffect(() => {
    initializedDatabaseIdRef.current = null
  }, [databaseId])

  const setSort = useCallback(
    (next: PostgresSidebarTablesSort) => {
      setSortState(next)
      if (!databaseId) return

      const currentAccount = getConsoleAccountFromCache(queryClient)
      if (!currentAccount) return

      void updateAccountPrefs({
        ...(currentAccount.prefs ?? {}),
        ...buildPostgresSidebarTablesSortPrefs(databaseId, next),
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
    [databaseId, queryClient],
  )

  return { sort, setSort }
}

export function usePostgresSidebarPanel(
  databaseId: string | null | undefined,
  account: { prefs?: Record<string, unknown> } | undefined,
) {
  const queryClient = useQueryClient()
  const consoleImpersonationRevision = useConsoleImpersonationRevision()
  const { data: consoleAccount } = useQuery(
    consoleAccountQueryOptions({ revision: consoleImpersonationRevision }),
  )

  const accountPrefs = useMemo(() => {
    const cachedPrefs = getConsoleAccountFromCache(queryClient)?.prefs as
      | Record<string, unknown>
      | undefined
    return {
      ...(account?.prefs ?? {}),
      ...(consoleAccount?.prefs ?? {}),
      ...(cachedPrefs ?? {}),
    } as Record<string, unknown>
  }, [account?.prefs, consoleAccount?.prefs, queryClient])

  const [panel, setPanelState] = useState<PostgresSidebarPanelPreference>(
    POSTGRES_SIDEBAR_PANEL_DEFAULT,
  )
  const initializedDatabaseIdRef = useRef<string | null>(null)

  useEffect(() => {
    if (!databaseId) return
    if (initializedDatabaseIdRef.current === databaseId) return

    setPanelState(parsePostgresSidebarPanel(accountPrefs, databaseId))
    initializedDatabaseIdRef.current = databaseId
  }, [accountPrefs, databaseId])

  useEffect(() => {
    initializedDatabaseIdRef.current = null
  }, [databaseId])

  const setPanel = useCallback(
    (next: PostgresSidebarPanelPreference) => {
      setPanelState(next)
      if (!databaseId) return

      const currentAccount = getConsoleAccountFromCache(queryClient)
      if (!currentAccount) return

      void updateAccountPrefs({
        ...(currentAccount.prefs ?? {}),
        ...buildPostgresSidebarPanelPrefs(databaseId, next),
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
    [databaseId, queryClient],
  )

  return { panel, setPanel }
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
      if (!databaseId) return

      const currentAccount = getConsoleAccountFromCache(queryClient)
      if (!currentAccount) return

      void updateAccountPrefs({
        ...(currentAccount.prefs ?? {}),
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
    [databaseId, queryClient],
  )

  return { savedQueryLevel, setSavedQueryLevel }
}

function buildNextPostgresSavedQueriesList(
  current: SavedPostgresQuery[],
  name: string,
  sql: string,
): SavedPostgresQuery[] {
  const trimmedSql = sql.trim()
  const trimmedName = name.trim().slice(0, MAX_SAVED_POSTGRES_QUERY_NAME_LENGTH)
  if (!trimmedName) throw new Error('Name is required')
  if (!trimmedSql) throw new Error('SQL is required')

  const head = current[0]
  if (head && head.name === trimmedName && head.sql === trimmedSql) {
    return current
  }

  if (current.length >= MAX_SAVED_POSTGRES_QUERIES) {
    throw new Error(`Maximum ${MAX_SAVED_POSTGRES_QUERIES} saved queries`)
  }

  return [
    { id: crypto.randomUUID(), name: trimmedName, sql: trimmedSql },
    ...current,
  ].slice(0, MAX_SAVED_POSTGRES_QUERIES)
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
  const consoleImpersonationRevision = useConsoleImpersonationRevision()
  const { data: consoleAccount } = useQuery(
    consoleAccountQueryOptions({ revision: consoleImpersonationRevision }),
  )
  const { data: team } = useConsoleTeam(teamId)
  const updateTeamPrefs = useUpdateConsoleTeamPrefs(teamId)

  const accountPrefs = useMemo(() => {
    const cachedPrefs = getConsoleAccountFromCache(queryClient)?.prefs as
      | Record<string, unknown>
      | undefined
    return {
      ...(account?.prefs ?? {}),
      ...(consoleAccount?.prefs ?? {}),
      ...(cachedPrefs ?? {}),
    } as Record<string, unknown>
  }, [account?.prefs, consoleAccount?.prefs, queryClient])

  const userQueries: SavedPostgresQuery[] =
    databaseId && accountPrefs
      ? parsePostgresSavedQueries(accountPrefs, databaseId)
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
      if (trimmedSql.length > MAX_SAVED_POSTGRES_QUERY_SQL_CHARS) {
        throw new Error('Query is too large to save')
      }
      const current = parsePostgresSavedQueries(currentAccount.prefs, databaseId)
      const next = buildNextPostgresSavedQueriesList(current, name, sql)
      return await updateAccountPrefs({
        ...currentAccount.prefs,
        ...buildPostgresSavedQueriesPrefs(databaseId, next),
      })
    },
    onMutate: async ({ name, sql }) => {
      if (!databaseId) return undefined

      const trimmedSql = sql.trim()
      const trimmedName = name.trim().slice(0, MAX_SAVED_POSTGRES_QUERY_NAME_LENGTH)
      if (!trimmedName || !trimmedSql) return undefined

      await queryClient.cancelQueries({ queryKey: ['account', 'console'] })

      const previousAccounts = queryClient.getQueriesData<Models.User>({
        queryKey: ['account', 'console'],
      })

      queryClient.setQueriesData<Models.User>(
        { queryKey: ['account', 'console'] },
        (current) => {
          if (!current) return current
          const currentList = parsePostgresSavedQueries(current.prefs, databaseId)
          try {
            const next = buildNextPostgresSavedQueriesList(currentList, name, sql)
            return {
              ...current,
              prefs: {
                ...(current.prefs ?? {}),
                ...buildPostgresSavedQueriesPrefs(databaseId, next),
              },
            } as Models.User
          } catch {
            return current
          }
        },
      )

      return { previousAccounts }
    },
    onError: (_error, _variables, context) => {
      if (context?.previousAccounts) {
        for (const [queryKey, data] of context.previousAccounts) {
          queryClient.setQueryData(queryKey, data)
        }
      }
    },
    onSuccess: (updatedAccount) => {
      syncConsoleAccountAfterMutation(queryClient, {
        apiResult: updatedAccount,
      })
    },
  })

  const addTeamMutation = useMutation({
    mutationFn: async ({ name, sql }: { name: string; sql: string }) => {
      if (!databaseId || !teamId) {
        throw new Error('Team or database not available')
      }
      const trimmedSql = sql.trim()
      if (trimmedSql.length > MAX_SAVED_POSTGRES_QUERY_SQL_CHARS) {
        throw new Error('Query is too large to save')
      }
      await updateTeamPrefs.mutateAsync((freshPrefs) => {
        const current = parsePostgresSavedQueries(freshPrefs, databaseId)
        const next = buildNextPostgresSavedQueriesList(current, name, sql)
        return buildPostgresSavedQueriesPrefs(databaseId, next)
      })
    },
    onMutate: async ({ name, sql }) => {
      if (!databaseId || !teamId) return undefined

      const trimmedSql = sql.trim()
      const trimmedName = name.trim().slice(0, MAX_SAVED_POSTGRES_QUERY_NAME_LENGTH)
      if (!trimmedName || !trimmedSql) return undefined

      const teamQueryKey = ['team', 'console', teamId] as const

      await queryClient.cancelQueries({ queryKey: teamQueryKey })

      const previousTeam = queryClient.getQueryData<{
        prefs?: Record<string, unknown>
      }>(teamQueryKey)

      queryClient.setQueryData(teamQueryKey, (current) => {
        if (!current) return current
        const currentList = parsePostgresSavedQueries(
          current.prefs as Record<string, unknown>,
          databaseId,
        )
        try {
          const next = buildNextPostgresSavedQueriesList(currentList, name, sql)
          return {
            ...current,
            prefs: {
              ...(current.prefs ?? {}),
              ...buildPostgresSavedQueriesPrefs(databaseId, next),
            },
          }
        } catch {
          return current
        }
      })

      return { previousTeam }
    },
    onError: (_error, _variables, context) => {
      if (!teamId || context?.previousTeam === undefined) return
      queryClient.setQueryData(['team', 'console', teamId], context.previousTeam)
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
      if (!databaseId || !teamId) {
        throw new Error('Team or database not available')
      }
      await updateTeamPrefs.mutateAsync((freshPrefs) => {
        const current = parsePostgresSavedQueries(freshPrefs, databaseId)
        return buildPostgresSavedQueriesPrefs(
          databaseId,
          current.filter((query) => query.id !== id),
        )
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
  }): Promise<SavedPostgresQuery | undefined> => {
    const trimmedSql = args.sql.trim()
    const trimmedName = args.name.trim()

    if (args.level === 'team' && teamId) {
      await addTeamMutation.mutateAsync({
        name: args.name,
        sql: args.sql,
      })
      const team = queryClient.getQueryData<{
        prefs?: Record<string, unknown>
      }>(['team', 'console', teamId])
      if (!team || !databaseId) return undefined
      const queries = parsePostgresSavedQueries(
        team.prefs as Record<string, unknown>,
        databaseId,
      )
      return (
        queries.find(
          (query) => query.name === trimmedName && query.sql === trimmedSql,
        ) ?? queries[0]
      )
    }

    const updatedAccount = await addUserMutation.mutateAsync({
      name: args.name,
      sql: args.sql,
    })
    if (!databaseId) return undefined
    const queries = parsePostgresSavedQueries(updatedAccount.prefs, databaseId)
    return (
      queries.find(
        (query) => query.name === trimmedName && query.sql === trimmedSql,
      ) ?? queries[0]
    )
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
const POSTGRES_SQL_EDITOR_STATE_PERSIST_DEBOUNCE_MS = 400

/**
 * Persisted PostgreSQL SQL editor tabs for a database
 * (`console.postgresSqlEditorState.<databaseId>`).
 */
export function usePostgresSqlEditorPersistence(
  databaseId: string | null | undefined,
  account: { prefs?: Record<string, unknown> } | undefined,
) {
  const queryClient = useQueryClient()
  const consoleImpersonationRevision = useConsoleImpersonationRevision()
  const { data: consoleAccount } = useQuery(
    consoleAccountQueryOptions({ revision: consoleImpersonationRevision }),
  )

  const accountPrefs = useMemo(() => {
    const cachedPrefs = getConsoleAccountFromCache(queryClient)?.prefs as
      | Record<string, unknown>
      | undefined
    return {
      ...(account?.prefs ?? {}),
      ...(consoleAccount?.prefs ?? {}),
      ...(cachedPrefs ?? {}),
    } as Record<string, unknown>
  }, [account?.prefs, consoleAccount?.prefs, queryClient])

  const persistTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null)

  const parseInitialEditorState = useCallback((): PersistedPostgresSqlEditorState | null => {
    if (!databaseId) return null
    return parsePostgresSqlEditorState(accountPrefs, databaseId)
  }, [accountPrefs, databaseId])

  const updateMutation = useMutation({
    mutationFn: async (value: PersistedPostgresSqlEditorState) => {
      const currentAccount = getConsoleAccountFromCache(queryClient)
      if (!currentAccount || !databaseId) {
        throw new Error('Account or database not available')
      }
      return await updateAccountPrefs(
        mergePostgresSqlEditorStateIntoPrefs(
          (currentAccount.prefs ?? {}) as UserPrefs,
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

  const persistEditorTabState = useCallback(
    (value: PersistedPostgresSqlEditorState) => {
      if (!databaseId) return

      const currentPrefs = (getConsoleAccountFromCache(queryClient)?.prefs ??
        {}) as UserPrefs
      const prefsKey = getPostgresSqlEditorStateKey(databaseId)
      const nextSerialized = buildPostgresSqlEditorStatePrefs(databaseId, value)[
        prefsKey
      ]
      const currentSerialized = currentPrefs[prefsKey]
      const normalizedNext =
        typeof nextSerialized === 'string'
          ? nextSerialized
          : nextSerialized != null
            ? JSON.stringify(nextSerialized)
            : undefined
      const normalizedCurrent =
        typeof currentSerialized === 'string'
          ? currentSerialized
          : currentSerialized != null
            ? JSON.stringify(currentSerialized)
            : undefined
      if (normalizedNext === normalizedCurrent) return

      const patch = mergePostgresSqlEditorStateIntoPrefs(
        currentPrefs,
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
      }, POSTGRES_SQL_EDITOR_STATE_PERSIST_DEBOUNCE_MS)
    },
    [databaseId, queryClient, updateMutation],
  )

  useEffect(() => {
    return () => {
      if (persistTimerRef.current !== null) {
        clearTimeout(persistTimerRef.current)
      }
    }
  }, [])

  return { parseInitialEditorState, persistEditorTabState }
}

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

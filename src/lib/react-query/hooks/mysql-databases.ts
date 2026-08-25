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
import { Query } from '@appwrite.io/console' // pragma: allowlist secret
import type { Models } from '@appwrite.io/console' // pragma: allowlist secret
import { sdk } from '@/lib/appwrite/sdk' // pragma: allowlist secret
import {
  invalidateDatabaseModel,
  refetchProjectDatabaseLists,
} from './databases'
import {
  mapDedicatedDatabaseCredentials,
  type DedicatedDatabaseConnectionList,
  type DedicatedDatabaseCredentials,
} from '@/lib/databases/dedicated-engine'
import { MYSQL_ACTIVE_CONNECTIONS_SQL } from '@/lib/mysql-metrics-sql'
import { parseMysqlActiveConnections } from '@/lib/mysql-metrics'
import {
  explainMysqlDatabaseQuery,
} from '@/lib/mysql-query-explanation'
import {
  getMysqlExecutionColumnNames,
  isMysqlReadQuery,
  mysqlExecutionNeedsJsonWrap,
  normalizeMysqlExecutionResult,
  wrapMysqlSqlForDisplay,
} from '@/lib/mysql-execution-values'
import {
  executionResultRows,
  coerceMysqlStringValue,
  readMysqlRowString,
  buildMysqlListSchemasCountSql,
  buildMysqlListSchemasSql,
  buildMysqlListTablesCountSql,
  buildMysqlListTablesSql,
  buildMysqlTableAutocompleteColumnsSql,
  buildMysqlTableColumnsForRowsSql,
  buildMysqlTableColumnsSql,
  buildMysqlTableIndexesSql,
  buildMysqlTableInfoSql,
  MYSQL_SIDEBAR_LIST_PAGE_SIZE,
  type MysqlColumnRow,
  type MysqlListSchemasOptions,
  type MysqlListTablesOptions,
  type MysqlTableColumnRow,
  type MysqlTableIndexRow,
  type MysqlTableInfoRow,
  type MysqlTableRow,
  sortMysqlTableColumns,
  sortMysqlTableIndexes,
  mysqlRelationSupportsRowCtid,
} from '@/lib/mysql-sql'
import { parseMysqlTableId, mysqlTableId, quoteMysqlIdentifier } from '@/lib/mysql-database-routes'
import {
  buildMysqlTablePoliciesSql,
  buildMysqlTableRlsStatusSql,
  isMysqlTruthyFlag,
  type MysqlTablePolicyRow,
  type MysqlTableRlsRow,
} from '@/lib/mysql-rls'
import {
  buildMysqlListRolesSql,
  buildMysqlListRolesFallbackSql,
  type MysqlRoleRow,
} from '@/lib/mysql-roles'
import {
  buildMysqlVisualizerColumnsBatchSql,
  buildMysqlVisualizerExternalColumnsSql,
  buildMysqlVisualizerForeignKeysSql,
  MYSQL_VISUALIZER_RELATIONS_BATCH_SIZE,
  type MysqlVisualizerColumnRow,
  type MysqlVisualizerForeignKeyRow,
  type MysqlVisualizerRelationRef,
} from '@/lib/mysql-visualizer-sql'
import {
  buildMysqlCountRowsSql,
  buildMysqlDeleteRowSql,
  buildMysqlInsertRowSql,
  buildSyncMysqlSerialSequencesSql,
  isMysqlDuplicatePrimaryKeyError,
  isMysqlSequenceBackedColumn,
  buildMysqlSelectRowsSql,
  buildMysqlUpdateRowSql,
  MYSQL_ROW_CTID_COLUMN,
  type MysqlRowIdentity,
} from '@/lib/mysql-row-sql'
import {
  filterMysqlRowCreateValues,
  groupMysqlEditsByRow,
  type PendingMysqlRowCellEdit,
} from '@/lib/mysql-row-edits'
import type { RowCellValue } from '@/lib/database-row-inline-edits'
import type { CompactFilterKey } from '@/lib/table-filters/types'
import {
  buildMysqlRowsListWhereClause,
} from '@/lib/mysql-row-filters'
import {
  buildMysqlSavedQueriesPrefs,
  buildMysqlSavedQueriesScopePrefs,
  buildMysqlSavedQueriesSortPrefs,
  buildMysqlSelectedSchemaPrefs,
  buildMysqlSidebarPanelPrefs,
  buildMysqlSidebarTablesSortPrefs,
  buildMysqlSqlEditorStatePrefs,
  getMysqlSqlEditorStateKey,
  MAX_SAVED_MYSQL_QUERIES,
  MAX_SAVED_MYSQL_QUERY_NAME_LENGTH,
  MAX_SAVED_MYSQL_QUERY_SQL_CHARS,
  mergeMysqlQueryHistoryIntoPrefs,
  mergeMysqlSqlEditorStateIntoPrefs,
  parseMysqlQueryHistory,
  parseMysqlSavedQueries,
  parseMysqlSavedQueriesScope,
  parseMysqlSavedQueriesSort,
  parseMysqlSelectedSchema,
  parseMysqlSidebarPanel,
  parseMysqlSidebarTablesSort,
  parseMysqlSqlEditorState,
  MYSQL_SAVED_QUERIES_DEFAULT_SORT,
  MYSQL_SIDEBAR_TABLES_DEFAULT_SORT,
  MYSQL_SIDEBAR_PANEL_DEFAULT,
  resolveMysqlSavedQueriesScope,
  resolveMysqlSelectedSchema,
  type MysqlSavedQueriesSort,
  type MysqlSidebarPanelPreference,
  type MysqlSidebarTablesSort,
  type PersistedMysqlSqlEditorState,
  type MysqlQueryHistoryEntry,
  type SavedMysqlQuery,
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
  shouldPollDedicatedDatabaseStatus,
} from '@/lib/databases/dedicated-database-status'
import { requireOperationalDatabase } from '@/lib/databases/dedicated-database-write-lock'
import { matchesNativeEngine } from '@/lib/databases/native-database-engines'
import {
  ensureConsoleSqlApiStatements,
  isSqlApiDdlBlockedError,
} from '@/lib/databases/sql-api-statements'

function isMysqlEngine(engine: string | undefined): boolean {
  return matchesNativeEngine(engine, 'mysql')
}

export { isMysqlEngine }

async function fetchMysqlDatabaseFromList(
  projectId: string,
  databaseId: string,
): Promise<Models.DedicatedDatabase | null> {
  const response = await sdk.forProject(projectId).mysql.list({
    queries: [Query.equal('$id', databaseId), Query.limit(1)],
  })
  return response.databases?.find((db) => db.$id === databaseId) ?? null
}

export async function fetchMysqlDatabase(
  projectId: string,
  databaseId: string,
): Promise<Models.DedicatedDatabase | null> {
  if (!projectId || !databaseId) return null
  try {
    const database = await sdk
      .forProject(projectId)
      .mysql.get({ databaseId })
    if (database?.$id) return database
  } catch {
    /* fall back to list */
  }

  try {
    return await fetchMysqlDatabaseFromList(projectId, databaseId)
  } catch {
    return null
  }
}

export async function executeMysqlDatabaseSql(
  projectId: string,
  databaseId: string,
  sql: string,
  timeoutSeconds?: number,
): Promise<Models.DedicatedDatabaseExecution> {
  const projectSdk = sdk.forProject(projectId)
  const run = (sqlToRun: string) =>
    projectSdk.mysql.createExecution({
      databaseId,
      sql: sqlToRun,
      timeoutSeconds,
    })

  const execute = async () => {
    // First pass: run as-is (most console catalog queries only return strings).
    const execution = await run(wrapMysqlSqlForDisplay(sql))

    // Retry with a JSON_ARRAYAGG wrap when the API could not marshal driver types
    // such as DATETIME / TIMESTAMP / VARCHAR byte buffers (same role as Postgres
    // row_to_json wrapping). Check the raw payload before normalize, which may
    // already decode byte arrays and hide the need to wrap.
    if (isMysqlReadQuery(sql) && mysqlExecutionNeedsJsonWrap(execution)) {
      const columnNames = getMysqlExecutionColumnNames(execution)
      const wrappedSql = wrapMysqlSqlForDisplay(sql, columnNames)
      if (wrappedSql !== sql.trim() && columnNames.length > 0) {
        const wrappedExecution = await run(wrappedSql)
        return normalizeMysqlExecutionResult(wrappedExecution)
      }
    }

    return normalizeMysqlExecutionResult(execution)
  }

  try {
    return await execute()
  } catch (error) {
    if (!isSqlApiDdlBlockedError(error)) throw error
    await ensureConsoleSqlApiStatements(
      projectId,
      databaseId,
      'mysql',
    ).catch(() => {
      /* Retry the statement even if the allow-list PATCH is a no-op */
    })
    return await execute()
  }
}

function parseMysqlCountTotal(
  execution: Models.DedicatedDatabaseExecution,
  fallback = 0,
): number {
  const rows = executionResultRows<{ total?: unknown }>(execution)
  const totalRaw = rows[0]?.total
  if (typeof totalRaw === 'number' && Number.isFinite(totalRaw)) return totalRaw
  const asString = coerceMysqlStringValue(totalRaw) ?? String(totalRaw ?? fallback)
  const parsed = Number.parseInt(asString, 10)
  return Number.isFinite(parsed) ? parsed : fallback
}

export async function fetchMysqlSchemasPage(
  projectId: string,
  databaseId: string,
  options?: Pick<MysqlListSchemasOptions, 'search'> & {
    page?: number
    limit?: number
  },
) {
  const limit = options?.limit ?? MYSQL_SIDEBAR_LIST_PAGE_SIZE
  const page = options?.page ?? 0
  const offset = page * limit
  const search = options?.search?.trim() || undefined

  const [dataExecution, countExecution] = await Promise.all([
    executeMysqlDatabaseSql(
      projectId,
      databaseId,
      buildMysqlListSchemasSql({ search, limit, offset }),
    ),
    executeMysqlDatabaseSql(
      projectId,
      databaseId,
      buildMysqlListSchemasCountSql({ search }),
    ),
  ])

  const rows = executionResultRows<Record<string, unknown>>(dataExecution)
  const schemas = rows
    .map((row) => readMysqlRowString(row, 'schema_name', 'SCHEMA_NAME'))
    .filter((name): name is string => !!name)
  const total = parseMysqlCountTotal(countExecution, schemas.length)

  return {
    schemas,
    total,
    page,
    limit,
    hasMore: offset + schemas.length < total,
  }
}

export async function fetchMysqlTablesPage(
  projectId: string,
  databaseId: string,
  options: {
    schema?: string
    search?: string
    page?: number
    limit?: number
  },
) {
  const schema = coerceMysqlStringValue(options.schema) || undefined
  const limit = options.limit ?? MYSQL_SIDEBAR_LIST_PAGE_SIZE
  const page = options.page ?? 0
  const offset = page * limit
  const search = options.search?.trim() || undefined

  const [dataExecution, countExecution] = await Promise.all([
    executeMysqlDatabaseSql(
      projectId,
      databaseId,
      buildMysqlListTablesSql({ schema, search, limit, offset }),
    ),
    executeMysqlDatabaseSql(
      projectId,
      databaseId,
      buildMysqlListTablesCountSql({ schema, search }),
    ),
  ])

  const rows = executionResultRows<MysqlTableRow>(dataExecution)
  const tables = rows.filter((row) => row.table_schema && row.table_name)
  const total = parseMysqlCountTotal(countExecution, tables.length)

  return {
    tables,
    total,
    page,
    limit,
    hasMore: offset + tables.length < total,
  }
}

export async function fetchFirstMysqlTable(
  projectId: string,
  databaseId: string,
): Promise<MysqlTableRow | null> {
  const page = await fetchMysqlTablesPage(projectId, databaseId, {
    limit: 1,
    page: 0,
  })
  return page.tables[0] ?? null
}

export async function fetchMysqlTableAutocompleteColumns(
  projectId: string,
  databaseId: string,
  schema: string,
  table: string,
): Promise<MysqlColumnRow[]> {
  const execution = await executeMysqlDatabaseSql(
    projectId,
    databaseId,
    buildMysqlTableAutocompleteColumnsSql(schema, table),
  )
  return executionResultRows<MysqlColumnRow>(execution).filter(
    (row) => row.table_schema && row.table_name && row.column_name,
  )
}

export async function fetchMysqlTableColumns(
  projectId: string,
  databaseId: string,
  tableId: string,
) {
  const { schema, table } = parseMysqlTableId(tableId)
  const execution = await executeMysqlDatabaseSql(
    projectId,
    databaseId,
    buildMysqlTableColumnsSql(schema, table),
  )
  const rows = executionResultRows<MysqlTableColumnRow>(execution)
  const columns = sortMysqlTableColumns(
    rows.filter((row) => row.column_name),
  )
  return {
    columns,
    total: columns.length,
  }
}

export type MysqlTableRowColumnsResult = {
  columns: MysqlTableColumnRow[]
  total: number
  exists: boolean
  supportsRowCtid: boolean
}

type MysqlTableRowColumnSqlRow = MysqlTableColumnRow & {
  rel_kind?: string | null
}

export async function fetchMysqlTableRowColumns(
  projectId: string,
  databaseId: string,
  tableId: string,
): Promise<MysqlTableRowColumnsResult> {
  const { schema, table } = parseMysqlTableId(tableId)
  const execution = await executeMysqlDatabaseSql(
    projectId,
    databaseId,
    buildMysqlTableColumnsForRowsSql(schema, table),
  )
  const rows = executionResultRows<MysqlTableRowColumnSqlRow>(execution)
  const relKind = rows[0]?.rel_kind ?? null
  const columns = sortMysqlTableColumns(
    rows.filter((row) => row.column_name),
  )
  return {
    columns,
    total: columns.length,
    exists: relKind != null,
    supportsRowCtid: mysqlRelationSupportsRowCtid(relKind),
  }
}

export async function fetchMysqlTableIndexes(
  projectId: string,
  databaseId: string,
  tableId: string,
) {
  const { schema, table } = parseMysqlTableId(tableId)
  const execution = await executeMysqlDatabaseSql(
    projectId,
    databaseId,
    buildMysqlTableIndexesSql(schema, table),
  )
  const rows = executionResultRows<MysqlTableIndexRow>(execution)
  const indexes = sortMysqlTableIndexes(
    rows.filter((row) => row.index_name),
  )
  return {
    indexes,
    total: indexes.length,
  }
}

export async function fetchMysqlTableInfo(
  projectId: string,
  databaseId: string,
  tableId: string,
) {
  const { schema, table } = parseMysqlTableId(tableId)
  const execution = await executeMysqlDatabaseSql(
    projectId,
    databaseId,
    buildMysqlTableInfoSql(schema, table),
  )
  const rows = executionResultRows<MysqlTableInfoRow>(execution)
  return rows[0] ?? null
}

export async function fetchMysqlTableRls(
  projectId: string,
  databaseId: string,
  tableId: string,
) {
  const { schema, table } = parseMysqlTableId(tableId)
  const execution = await executeMysqlDatabaseSql(
    projectId,
    databaseId,
    buildMysqlTableRlsStatusSql(schema, table),
  )
  const rows = executionResultRows<MysqlTableRlsRow>(execution)
  const row = rows[0]
  return {
    rowSecurityEnabled: isMysqlTruthyFlag(row?.row_security_enabled),
    forceRowSecurity: isMysqlTruthyFlag(row?.force_row_security),
  }
}

export async function fetchMysqlTablePolicies(
  projectId: string,
  databaseId: string,
  tableId: string,
) {
  const { schema, table } = parseMysqlTableId(tableId)
  const execution = await executeMysqlDatabaseSql(
    projectId,
    databaseId,
    buildMysqlTablePoliciesSql(schema, table),
  )
  const policies = executionResultRows<MysqlTablePolicyRow>(execution)
  return {
    policies,
    total: policies.length,
  }
}

export async function fetchMysqlRoles(
  projectId: string,
  databaseId: string,
) {
  try {
    const execution = await executeMysqlDatabaseSql(
      projectId,
      databaseId,
      buildMysqlListRolesSql(),
    )
    const roles = executionResultRows<MysqlRoleRow>(execution)
    return {
      roles,
      total: roles.length,
    }
  } catch {
    const execution = await executeMysqlDatabaseSql(
      projectId,
      databaseId,
      buildMysqlListRolesFallbackSql(),
    )
    const roles = executionResultRows<MysqlRoleRow>(execution)
    return {
      roles,
      total: roles.length,
    }
  }
}

export type MysqlVisualizerColumn = {
  name: string
  dataType: string
  udtName: string
  required: boolean
  isPrimaryKey: boolean
}

export type MysqlVisualizerRelation = {
  id: string
  schema: string
  name: string
  tableType: string
  isExternal: boolean
  columns: MysqlVisualizerColumn[]
  columnsLoaded: boolean
}

export type MysqlVisualizerRelationship = {
  from: string
  to: string
  fromColumn: string
  toColumn: string
  constraintName: string
}

function mapMysqlVisualizerColumnRow(
  row: MysqlVisualizerColumnRow,
): MysqlVisualizerColumn {
  return {
    name: row.column_name,
    dataType: row.data_type,
    udtName: row.udt_name,
    required: row.is_nullable !== 'YES',
    isPrimaryKey:
      row.is_primary_key === true || row.is_primary_key === 'true',
  }
}

function groupMysqlVisualizerColumns(
  rows: MysqlVisualizerColumnRow[],
): Map<string, MysqlVisualizerColumn[]> {
  const grouped = new Map<string, MysqlVisualizerColumn[]>()
  for (const row of rows) {
    const key = mysqlTableId(row.table_schema, row.table_name)
    const columns = grouped.get(key) ?? []
    columns.push(mapMysqlVisualizerColumnRow(row))
    grouped.set(key, columns)
  }
  return grouped
}

export async function fetchMysqlVisualizerColumnsBatch(
  projectId: string,
  databaseId: string,
  schema: string,
  tableNames: string[],
) {
  if (!schema.trim() || tableNames.length === 0) {
    return new Map<string, MysqlVisualizerColumn[]>()
  }

  const execution = await executeMysqlDatabaseSql(
    projectId,
    databaseId,
    buildMysqlVisualizerColumnsBatchSql(schema, tableNames),
  )
  const rows = executionResultRows<MysqlVisualizerColumnRow>(execution)
  return groupMysqlVisualizerColumns(rows)
}

export async function fetchMysqlVisualizerExternalColumns(
  projectId: string,
  databaseId: string,
  relations: MysqlVisualizerRelationRef[],
) {
  if (relations.length === 0) {
    return new Map<string, MysqlVisualizerColumn[]>()
  }

  const execution = await executeMysqlDatabaseSql(
    projectId,
    databaseId,
    buildMysqlVisualizerExternalColumnsSql(relations),
  )
  const rows = executionResultRows<MysqlVisualizerColumnRow>(execution)
  return groupMysqlVisualizerColumns(rows)
}

export async function fetchMysqlVisualizerForeignKeys(
  projectId: string,
  databaseId: string,
  schema: string,
) {
  if (!schema.trim()) return [] as MysqlVisualizerForeignKeyRow[]

  const execution = await executeMysqlDatabaseSql(
    projectId,
    databaseId,
    buildMysqlVisualizerForeignKeysSql(schema),
  )
  return executionResultRows<MysqlVisualizerForeignKeyRow>(execution).filter(
    (row) =>
      row.source_schema &&
      row.source_table &&
      row.source_column &&
      row.target_schema &&
      row.target_table &&
      row.target_column,
  )
}

export function mysqlVisualizerForeignKeysQueryOptions(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  schema: string | null | undefined,
) {
  const normalizedSchema = schema?.trim() || undefined
  return queryOptions({
    queryKey: [
      'mysql-visualizer',
      'foreign-keys',
      'project',
      projectId,
      databaseId,
      normalizedSchema,
    ],
    queryFn: () =>
      fetchMysqlVisualizerForeignKeys(
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

export type MysqlTableRowsListParams = {
  search?: string
  filterKeys?: CompactFilterKey[]
  orderBy?: string
  orderDirection?: 'asc' | 'desc'
}

type FetchMysqlTableRowsOptions = {
  tableColumns?: MysqlTableColumnRow[]
  supportsRowCtid?: boolean
}

async function resolveMysqlTableColumnsForRows(
  projectId: string,
  databaseId: string,
  tableId: string,
  tableColumns?: MysqlTableColumnRow[],
) {
  if (tableColumns) {
    return {
      columns: tableColumns,
      total: tableColumns.length,
    }
  }
  return fetchMysqlTableRowColumns(projectId, databaseId, tableId)
}

function buildMysqlRowsWhereClause(
  params: MysqlTableRowsListParams | undefined,
  columns: MysqlTableColumnRow[],
): string | undefined {
  return buildMysqlRowsListWhereClause(
    params?.filterKeys,
    params?.search,
    columns,
  )
}

function buildMysqlRowsOrderClause(
  columns: MysqlTableColumnRow[],
  sortBy?: string,
  orderDirection: 'asc' | 'desc' = 'asc',
  supportsRowCtid = true,
): string | undefined {
  const direction = orderDirection === 'desc' ? 'DESC' : 'ASC'
  const sortColumn = sortBy?.trim()
  if (sortColumn && columns.some((column) => column.column_name === sortColumn)) {
    return `${quoteMysqlIdentifier(sortColumn)} ${direction}`
  }
  const pkColumns = columns.filter(
    (column) =>
      column.is_primary_key === true || column.is_primary_key === 'true',
  )
  if (pkColumns.length > 0) {
    return pkColumns
      .map((column) => `${quoteMysqlIdentifier(column.column_name)} ${direction}`)
      .join(', ')
  }
  if (supportsRowCtid) {
    return `${quoteMysqlIdentifier(MYSQL_ROW_CTID_COLUMN)} ${direction}`
  }
  const sortableColumns = columns.filter(
    (column) => column.column_name !== MYSQL_ROW_CTID_COLUMN,
  )
  if (sortableColumns.length === 0) return undefined
  return sortableColumns
    .map((column) => `${quoteMysqlIdentifier(column.column_name)} ${direction}`)
    .join(', ')
}

export async function fetchMysqlTableRows(
  projectId: string,
  databaseId: string,
  tableId: string,
  page: number,
  limit: number,
  params?: MysqlTableRowsListParams,
  options?: FetchMysqlTableRowsOptions,
) {
  const columnsResult = await resolveMysqlTableColumnsForRows(
    projectId,
    databaseId,
    tableId,
    options?.tableColumns,
  )
  const supportsRowCtid = options?.supportsRowCtid !== false
  const whereClause = buildMysqlRowsWhereClause(params, columnsResult.columns)
  const orderByClause = buildMysqlRowsOrderClause(
    columnsResult.columns,
    params?.orderBy,
    params?.orderDirection ?? 'asc',
    supportsRowCtid,
  )
  const offset = page * limit

  const [dataExecution, countExecution] = await Promise.all([
    executeMysqlDatabaseSql(
      projectId,
      databaseId,
      buildMysqlSelectRowsSql(tableId, {
        whereClause,
        orderByClause,
        limit,
        offset,
        includeCtid: supportsRowCtid,
      }),
    ),
    executeMysqlDatabaseSql(
      projectId,
      databaseId,
      buildMysqlCountRowsSql(tableId, whereClause),
    ),
  ])

  const rows = executionResultRows<Record<string, unknown>>(dataExecution)
  const total = parseMysqlCountTotal(countExecution, rows.length)

  return {
    rows,
    total,
    columns: dataExecution.columns ?? [],
    tableColumns: columnsResult.columns,
    durationMs: dataExecution.durationMs,
    truncated: dataExecution.truncated,
  }
}

export function mysqlDatabaseQueryOptions(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
) {
  return queryOptions({
    queryKey: ['mysql-database', 'project', projectId, databaseId],
    queryFn: () => fetchMysqlDatabase(projectId!, databaseId!),
    enabled: !!projectId && !!databaseId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId && databaseId ? 5 * 60 * 1000 : 0,
  })
}

export function mysqlTableAutocompleteColumnsQueryOptions(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  schema: string | null | undefined,
  table: string | null | undefined,
) {
  const normalizedSchema = schema?.trim() ?? ''
  const normalizedTable = table?.trim() ?? ''
  return queryOptions({
    queryKey: [
      'mysql-autocomplete-columns',
      'project',
      projectId,
      databaseId,
      normalizedSchema,
      normalizedTable,
    ],
    queryFn: () =>
      fetchMysqlTableAutocompleteColumns(
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
 * List active sessions via mysql.createExecution + information_schema.PROCESSLIST.
 * Maps into the legacy DedicatedDatabaseConnectionList shape for callers that
 * still use this query key; the Connections tab prefers the richer
 * MysqlActiveConnectionRow list from mysql-metrics.
 */
export async function fetchMysqlDatabaseConnections(
  projectId: string,
  databaseId: string,
): Promise<DedicatedDatabaseConnectionList> {
  const execution = await executeMysqlDatabaseSql(
    projectId,
    databaseId,
    MYSQL_ACTIVE_CONNECTIONS_SQL,
    30,
  )
  const rows = parseMysqlActiveConnections(execution)
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

export async function fetchMysqlDatabaseCredentials(
  projectId: string,
  databaseId: string,
): Promise<DedicatedDatabaseCredentials> {
  const database = await fetchMysqlDatabase(projectId, databaseId)
  if (!database) {
    throw new Error('Database not found')
  }
  return mapDedicatedDatabaseCredentials(database)
}

export function mysqlDatabaseConnectionsQueryOptions(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
) {
  return queryOptions({
    queryKey: [
      'mysql-database-connections',
      'project',
      projectId,
      databaseId,
    ],
    queryFn: () =>
      fetchMysqlDatabaseConnections(projectId!, databaseId!),
    enabled: !!projectId && !!databaseId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId && databaseId ? 5 * 60 * 1000 : 0,
  })
}

export function mysqlDatabaseCredentialsQueryOptions(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
) {
  return queryOptions({
    queryKey: [
      'mysql-database-credentials',
      'project',
      projectId,
      databaseId,
    ],
    queryFn: () =>
      fetchMysqlDatabaseCredentials(projectId!, databaseId!),
    enabled: !!projectId && !!databaseId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId && databaseId ? 5 * 60 * 1000 : 0,
  })
}

export async function fetchMysqlDatabasePooler(
  projectId: string,
  databaseId: string,
): Promise<Models.DedicatedDatabasePooler | null> {
  try {
    return await sdk.forProject(projectId).mysql.getPooler({
      databaseId,
    })
  } catch {
    return null
  }
}

export function mysqlDatabasePoolerQueryOptions(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
) {
  return queryOptions({
    queryKey: ['mysql-database-pooler', 'project', projectId, databaseId],
    queryFn: () => fetchMysqlDatabasePooler(projectId!, databaseId!),
    enabled: !!projectId && !!databaseId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId && databaseId ? 5 * 60 * 1000 : 0,
  })
}

export type UpdateMysqlDatabaseInput = {
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

export async function updateMysqlDatabase(
  projectId: string,
  input: UpdateMysqlDatabaseInput,
) {
  const { databaseId, ...params } = input
  return sdk.forProject(projectId).mysql.update({
    databaseId,
    ...params,
  })
}

export async function updateMysqlDatabaseMaintenance(
  projectId: string,
  databaseId: string,
  day: string,
  hourUtc: number,
) {
  return sdk.forProject(projectId).mysql.updateMaintenance({
    databaseId,
    day,
    hourUtc,
  })
}

export async function deleteMysqlDatabase(
  projectId: string,
  databaseId: string,
) {
  return sdk.forProject(projectId).mysql.delete({ databaseId })
}

export async function resetMysqlDatabaseCredentials(
  projectId: string,
  databaseId: string,
) {
  return sdk.forProject(projectId).mysql.updateCredentials({ databaseId })
}

export function useUpdateMysqlDatabase(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (input: Omit<UpdateMysqlDatabaseInput, 'databaseId'>) => {
      requireOperationalDatabase(queryClient, projectId!, databaseId!)
      return updateMysqlDatabase(projectId!, {
        databaseId: databaseId!,
        ...input,
      })
    },
    onSuccess: async (database) => {
      if (!projectId || !databaseId) return
      queryClient.setQueryData(
        mysqlDatabaseQueryOptions(projectId, databaseId).queryKey,
        database,
      )
      // Keep the shared switcher / databases index in sync (console.listDatabases
      // + dedicated lists). Invalidating dedicated alone left the droplist stale.
      await refetchProjectDatabaseLists(queryClient, projectId)
    },
  })
}

export function useUpdateMysqlDatabaseMaintenance(
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
      return updateMysqlDatabaseMaintenance(projectId!, databaseId!, day, hourUtc)
    },
    onSuccess: async (database) => {
      if (!projectId || !databaseId) return
      queryClient.setQueryData(
        mysqlDatabaseQueryOptions(projectId, databaseId).queryKey,
        database,
      )
    },
  })
}

export function useResetMysqlDatabaseCredentials(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: () => {
      requireOperationalDatabase(queryClient, projectId!, databaseId!)
      return resetMysqlDatabaseCredentials(projectId!, databaseId!)
    },
    onSuccess: async (database) => {
      if (!projectId || !databaseId) return
      queryClient.setQueryData(
        mysqlDatabaseQueryOptions(projectId, databaseId).queryKey,
        database,
      )
      queryClient.setQueryData(
        mysqlDatabaseCredentialsQueryOptions(projectId, databaseId).queryKey,
        mapDedicatedDatabaseCredentials(database),
      )
      await queryClient.invalidateQueries({
        queryKey: ['dedicated-databases', 'project', projectId],
      })
    },
  })
}

export function useDeleteMysqlDatabase(
  projectId: string | null | undefined,
) {
  const queryClient = useQueryClient()
  return useMutation({
    // Delete must stay available when the database is failed/locked so users
    // can clean up resources that never became ready.
    mutationFn: (databaseId: string) => {
      return deleteMysqlDatabase(projectId!, databaseId)
    },
    onSuccess: async (_data, databaseId) => {
      if (!projectId) return
      invalidateDatabaseModel(projectId, databaseId)
      queryClient.removeQueries({
        queryKey: mysqlDatabaseQueryOptions(projectId, databaseId).queryKey,
      })
      await refetchProjectDatabaseLists(queryClient, projectId)
    },
  })
}

export function mysqlTableColumnsQueryOptions(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  tableId: string | null | undefined,
) {
  return queryOptions({
    queryKey: [
      'mysql-table-columns',
      'project',
      projectId,
      databaseId,
      tableId,
    ],
    queryFn: () =>
      fetchMysqlTableColumns(projectId!, databaseId!, tableId!),
    enabled: !!projectId && !!databaseId && !!tableId && tableId !== '-',
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId && databaseId && tableId ? 5 * 60 * 1000 : 0,
  })
}

export function mysqlTableRowColumnsQueryOptions(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  tableId: string | null | undefined,
) {
  return queryOptions({
    queryKey: [
      'mysql-table-row-columns',
      'project',
      projectId,
      databaseId,
      tableId,
    ],
    queryFn: () =>
      fetchMysqlTableRowColumns(projectId!, databaseId!, tableId!),
    enabled: !!projectId && !!databaseId && !!tableId && tableId !== '-',
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId && databaseId && tableId ? 5 * 60 * 1000 : 0,
  })
}

export function mysqlTableIndexesQueryOptions(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  tableId: string | null | undefined,
) {
  return queryOptions({
    queryKey: [
      'mysql-table-indexes',
      'project',
      projectId,
      databaseId,
      tableId,
    ],
    queryFn: () =>
      fetchMysqlTableIndexes(projectId!, databaseId!, tableId!),
    enabled: !!projectId && !!databaseId && !!tableId && tableId !== '-',
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId && databaseId && tableId ? 5 * 60 * 1000 : 0,
  })
}

export function mysqlTableInfoQueryOptions(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  tableId: string | null | undefined,
) {
  return queryOptions({
    queryKey: [
      'mysql-table-info',
      'project',
      projectId,
      databaseId,
      tableId,
    ],
    queryFn: () => fetchMysqlTableInfo(projectId!, databaseId!, tableId!),
    enabled: !!projectId && !!databaseId && !!tableId && tableId !== '-',
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId && databaseId && tableId ? 5 * 60 * 1000 : 0,
  })
}

export function mysqlTableRlsQueryOptions(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  tableId: string | null | undefined,
) {
  return queryOptions({
    queryKey: [
      'mysql-table-rls',
      'project',
      projectId,
      databaseId,
      tableId,
    ],
    queryFn: () => fetchMysqlTableRls(projectId!, databaseId!, tableId!),
    enabled: !!projectId && !!databaseId && !!tableId && tableId !== '-',
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId && databaseId && tableId ? 5 * 60 * 1000 : 0,
  })
}

export function mysqlTablePoliciesQueryOptions(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  tableId: string | null | undefined,
) {
  return queryOptions({
    queryKey: [
      'mysql-table-policies',
      'project',
      projectId,
      databaseId,
      tableId,
    ],
    queryFn: () =>
      fetchMysqlTablePolicies(projectId!, databaseId!, tableId!),
    enabled: !!projectId && !!databaseId && !!tableId && tableId !== '-',
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId && databaseId && tableId ? 5 * 60 * 1000 : 0,
  })
}

export function mysqlRolesQueryOptions(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
) {
  return queryOptions({
    queryKey: ['mysql-roles', 'project', projectId, databaseId],
    queryFn: () => fetchMysqlRoles(projectId!, databaseId!),
    enabled: !!projectId && !!databaseId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId && databaseId ? 5 * 60 * 1000 : 0,
  })
}

export function mysqlTableRowsQueryOptions(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  tableId: string | null | undefined,
  page: number = 0,
  limit: number = 25,
  params?: MysqlTableRowsListParams,
) {
  const filterKey = params?.filterKeys?.length
    ? JSON.stringify(params.filterKeys)
    : undefined
  return queryOptions({
    queryKey: [
      'mysql-table-rows',
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
        mysqlTableRowColumnsQueryOptions(projectId!, databaseId!, tableId!),
      )
      return fetchMysqlTableRows(
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

export async function updateMysqlTableRow(
  projectId: string,
  databaseId: string,
  tableId: string,
  identity: MysqlRowIdentity,
  changes: Record<string, RowCellValue>,
) {
  const sql = buildMysqlUpdateRowSql(tableId, identity, changes)
  return executeMysqlDatabaseSql(projectId, databaseId, sql)
}

export async function createMysqlTableRow(
  projectId: string,
  databaseId: string,
  tableId: string,
  values: Record<string, RowCellValue>,
) {
  const { columns } = await fetchMysqlTableRowColumns(
    projectId,
    databaseId,
    tableId,
  )
  const filteredValues = filterMysqlRowCreateValues(values, columns)
  const sql = buildMysqlInsertRowSql(tableId, filteredValues)

  // When serial/identity columns are omitted (left blank), Mysql uses
  // DEFAULT nextval(). If an earlier row used an explicit ID (or data was
  // imported), the sequence can lag behind MAX(id) and hit users_pkey.
  // Sync sequences before insert, and once more on duplicate-key errors.
  const omittedSequenceColumns = columns.filter(
    (column) =>
      isMysqlSequenceBackedColumn(column) &&
      !Object.prototype.hasOwnProperty.call(filteredValues, column.column_name),
  )
  const syncSql =
    omittedSequenceColumns.length > 0
      ? buildSyncMysqlSerialSequencesSql(tableId, omittedSequenceColumns)
      : null

  const runSync = async () => {
    if (!syncSql) return
    await executeMysqlDatabaseSql(projectId, databaseId, syncSql)
  }

  await runSync()

  const runInsert = () => executeMysqlDatabaseSql(projectId, databaseId, sql)

  try {
    return await runInsert()
  } catch (error) {
    if (!syncSql || !isMysqlDuplicatePrimaryKeyError(error)) {
      throw error
    }
    await runSync()
    return await runInsert()
  }
}

export async function deleteMysqlTableRow(
  projectId: string,
  databaseId: string,
  tableId: string,
  identity: MysqlRowIdentity,
) {
  const sql = buildMysqlDeleteRowSql(tableId, identity)
  return executeMysqlDatabaseSql(projectId, databaseId, sql)
}

export async function deleteMysqlTableRows(
  projectId: string,
  databaseId: string,
  tableId: string,
  identities: MysqlRowIdentity[],
) {
  await Promise.all(
    identities.map((identity) =>
      deleteMysqlTableRow(projectId, databaseId, tableId, identity),
    ),
  )
}

export async function commitMysqlRowEdits(
  projectId: string,
  databaseId: string,
  tableId: string,
  edits: PendingMysqlRowCellEdit[],
) {
  const grouped = groupMysqlEditsByRow(edits)
  for (const [, { identity, changes }] of grouped) {
    await updateMysqlTableRow(
      projectId,
      databaseId,
      tableId,
      identity,
      changes,
    )
  }
}

export function useUpdateMysqlTableRow(
  projectId: string,
  databaseId: string,
  tableId: string,
) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (params: {
      identity: MysqlRowIdentity
      changes: Record<string, RowCellValue>
    }) => {
      requireOperationalDatabase(queryClient, projectId, databaseId)
      return updateMysqlTableRow(
        projectId,
        databaseId,
        tableId,
        params.identity,
        params.changes,
      )
    },
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: ['mysql-table-rows', 'project', projectId, databaseId, tableId],
      })
    },
  })
}

export function useCreateMysqlTableRow(
  projectId: string,
  databaseId: string,
  tableId: string,
) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (values: Record<string, RowCellValue>) => {
      requireOperationalDatabase(queryClient, projectId, databaseId)
      return createMysqlTableRow(projectId, databaseId, tableId, values)
    },
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: ['mysql-table-rows', 'project', projectId, databaseId, tableId],
      })
      await queryClient.refetchQueries({
        queryKey: ['mysql-table-columns', 'project', projectId, databaseId, tableId],
      })
      await queryClient.refetchQueries({
        queryKey: ['mysql-table-row-columns', 'project', projectId, databaseId, tableId],
      })
    },
  })
}

export function useDeleteMysqlTableRow(
  projectId: string,
  databaseId: string,
  tableId: string,
) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (identity: MysqlRowIdentity) => {
      requireOperationalDatabase(queryClient, projectId, databaseId)
      return deleteMysqlTableRow(projectId, databaseId, tableId, identity)
    },
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: ['mysql-table-rows', 'project', projectId, databaseId, tableId],
      })
    },
  })
}

export function useDeleteMysqlTableRows(
  projectId: string,
  databaseId: string,
  tableId: string,
) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (identities: MysqlRowIdentity[]) => {
      requireOperationalDatabase(queryClient, projectId, databaseId)
      return deleteMysqlTableRows(projectId, databaseId, tableId, identities)
    },
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: ['mysql-table-rows', 'project', projectId, databaseId, tableId],
      })
    },
  })
}

export function useCommitMysqlRowEdits(
  projectId: string,
  databaseId: string,
  tableId: string,
) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (edits: PendingMysqlRowCellEdit[]) => {
      requireOperationalDatabase(queryClient, projectId, databaseId)
      return commitMysqlRowEdits(projectId, databaseId, tableId, edits)
    },
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: ['mysql-table-rows', 'project', projectId, databaseId, tableId],
      })
    },
  })
}

export function useMysqlDatabase(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
) {
  const queryClient = useQueryClient()
  const { data, isLoading, error, refetch, isFetching } = useQuery({
    ...mysqlDatabaseQueryOptions(projectId, databaseId),
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

export function mysqlSidebarSchemasInfiniteQueryOptions(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  search: string,
) {
  const normalizedSearch = search.trim() || undefined
  return infiniteQueryOptions({
    queryKey: [
      'mysql-schemas',
      'project',
      projectId,
      databaseId,
      'sidebar',
      normalizedSearch,
    ],
    queryFn: ({ pageParam }) =>
      fetchMysqlSchemasPage(projectId!, databaseId!, {
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

export function mysqlSidebarTablesInfiniteQueryOptions(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  schema: string | null | undefined,
  search: string,
) {
  const normalizedSchema = coerceMysqlStringValue(schema) ?? ''
  const normalizedSearch = search.trim() || undefined
  return infiniteQueryOptions({
    queryKey: [
      'mysql-tables',
      'project',
      projectId,
      databaseId,
      'sidebar',
      normalizedSchema || null,
      normalizedSearch,
    ],
    queryFn: ({ pageParam }) =>
      fetchMysqlTablesPage(projectId!, databaseId!, {
        schema: normalizedSchema || undefined,
        search: normalizedSearch,
        page: pageParam,
      }),
    initialPageParam: 0,
    getNextPageParam: (lastPage) =>
      lastPage?.hasMore ? lastPage.page + 1 : undefined,
    enabled: !!projectId && !!databaseId && !!normalizedSchema,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId && databaseId && normalizedSchema ? 5 * 60 * 1000 : 0,
  })
}

export function useMysqlSidebarSchemas(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  search: string,
) {
  const normalizedSearch = search.trim()
  const sidebarQueryOptions = mysqlSidebarSchemasInfiniteQueryOptions(
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
    () =>
      (data?.pages.flatMap((page) => page.schemas) ?? []).filter(
        (schema): schema is string => typeof schema === 'string' && schema.trim().length > 0,
      ),
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

export function useMysqlSidebarTables(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  schema: string | null | undefined,
  search: string,
) {
  const normalizedSearch = search.trim()
  const sidebarQueryOptions = mysqlSidebarTablesInfiniteQueryOptions(
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

export function useMysqlDatabaseConnections(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
) {
  const { data, isLoading, isFetching, error, refetch } = useQuery(
    mysqlDatabaseConnectionsQueryOptions(projectId, databaseId),
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

export function useMysqlDatabaseCredentials(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
) {
  const { data, isLoading, isFetching, error, refetch } = useQuery(
    mysqlDatabaseCredentialsQueryOptions(projectId, databaseId),
  )
  return {
    credentials: data ?? null,
    isLoading,
    isFetching,
    error,
    refetch,
  }
}

export function useMysqlDatabasePooler(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
) {
  const { data, isLoading, isFetching, error, refetch } = useQuery(
    mysqlDatabasePoolerQueryOptions(projectId, databaseId),
  )
  return {
    pooler: data ?? null,
    isLoading,
    isFetching,
    error,
    refetch,
  }
}

export function useMysqlTableRows(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  tableId: string | null | undefined,
  page: number = 0,
  limit: number = 25,
  params?: MysqlTableRowsListParams,
) {
  const { data, isLoading, isFetching, error, refetch } = useQuery(
    mysqlTableRowsQueryOptions(
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

export function useMysqlTableColumns(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  tableId: string | null | undefined,
) {
  const { data, isLoading, isFetching, error, refetch } = useQuery(
    mysqlTableColumnsQueryOptions(projectId, databaseId, tableId),
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

export function useMysqlTableIndexes(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  tableId: string | null | undefined,
) {
  const { data, isLoading, isFetching, error, refetch } = useQuery(
    mysqlTableIndexesQueryOptions(projectId, databaseId, tableId),
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

export function useMysqlRoles(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
) {
  const { data, isLoading, isFetching, error, refetch } = useQuery(
    mysqlRolesQueryOptions(projectId, databaseId),
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

export function useMysqlTableInfo(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  tableId: string | null | undefined,
) {
  const { data, isLoading, isFetching, error, refetch } = useQuery(
    mysqlTableInfoQueryOptions(projectId, databaseId, tableId),
  )
  return {
    tableInfo: data ?? null,
    isLoading,
    isFetching,
    error,
    refetch,
  }
}

export function useMysqlTableRls(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  tableId: string | null | undefined,
) {
  const { data, isLoading, isFetching, error, refetch } = useQuery(
    mysqlTableRlsQueryOptions(projectId, databaseId, tableId),
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

export function useMysqlTablePolicies(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  tableId: string | null | undefined,
) {
  const { data, isLoading, isFetching, error, refetch } = useQuery(
    mysqlTablePoliciesQueryOptions(projectId, databaseId, tableId),
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

function buildMysqlVisualizerRelationships(
  foreignKeys: MysqlVisualizerForeignKeyRow[],
): MysqlVisualizerRelationship[] {
  return foreignKeys.map((row) => ({
    from: mysqlTableId(row.source_schema, row.source_table),
    to: mysqlTableId(row.target_schema, row.target_table),
    fromColumn: row.source_column,
    toColumn: row.target_column,
    constraintName: row.constraint_name,
  }))
}

function collectExternalMysqlVisualizerTargets(
  foreignKeys: MysqlVisualizerForeignKeyRow[],
  loadedRelationIds: Set<string>,
  activeSchema: string,
): MysqlVisualizerRelationRef[] {
  const targets = new Map<string, MysqlVisualizerRelationRef>()

  for (const row of foreignKeys) {
    const targetId = mysqlTableId(row.target_schema, row.target_table)
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
export function useMysqlSchemaVisualizer(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  schema: string | null | undefined,
) {
  const normalizedSchema = schema?.trim() || undefined
  const [relations, setRelations] = useState<MysqlVisualizerRelation[]>([])
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
    mysqlVisualizerForeignKeysQueryOptions(
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

      const relationMap = new Map<string, MysqlVisualizerRelation>()
      let page = 0
      let total = 0

      try {
        while (!cancelled && loadGenerationRef.current === generation) {
          const tablesPage = await fetchMysqlTablesPage(
            projectId!,
            databaseId!,
            {
              schema: normalizedSchema,
              page,
              limit: MYSQL_VISUALIZER_RELATIONS_BATCH_SIZE,
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
          const columnsByRelation = await fetchMysqlVisualizerColumnsBatch(
            projectId!,
            databaseId!,
            normalizedSchema!,
            tableNames,
          )

          for (const table of tablesPage.tables) {
            const id = mysqlTableId(table.table_schema, table.table_name)
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
    const externalTargets = collectExternalMysqlVisualizerTargets(
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
        const columnsByRelation = await fetchMysqlVisualizerExternalColumns(
          projectId!,
          databaseId!,
          externalTargets,
        )

        if (cancelled) return

        setRelations((current) => {
          const next = [...current]
          const existingIds = new Set(current.map((relation) => relation.id))

          for (const target of externalTargets) {
            const id = mysqlTableId(target.schema, target.table)
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
    () => buildMysqlVisualizerRelationships(foreignKeys),
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

export function useExplainMysqlSql(
  projectId: string,
  databaseId: string,
) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (query: string) => {
      requireOperationalDatabase(queryClient, projectId, databaseId)
      return explainMysqlDatabaseQuery(projectId, databaseId, query)
    },
  })
}

/**
 * Schema-affecting SQL (DDL) must not leave inactive caches in place.
 * Row/column queries use `refetchOnMount: false`, so `invalidateQueries` alone
 * only refreshes active observers and the create-row form keeps stale fields
 * until a full reload.
 */
async function refreshMysqlDatabaseCaches(
  queryClient: QueryClient,
  projectId: string,
  databaseId: string,
) {
  const schemaQueryKeys = [
    ['mysql-schemas', 'project', projectId, databaseId],
    ['mysql-tables', 'project', projectId, databaseId],
    ['mysql-autocomplete-columns', 'project', projectId, databaseId],
    ['mysql-table-rows', 'project', projectId, databaseId],
    ['mysql-table-columns', 'project', projectId, databaseId],
    ['mysql-table-row-columns', 'project', projectId, databaseId],
    ['mysql-table-indexes', 'project', projectId, databaseId],
    ['mysql-table-info', 'project', projectId, databaseId],
    ['mysql-table-rls', 'project', projectId, databaseId],
    ['mysql-table-policies', 'project', projectId, databaseId],
    ['mysql-roles', 'project', projectId, databaseId],
    ['mysql-visualizer', 'project', projectId, databaseId],
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

export function useExecuteMysqlSql(
  projectId: string,
  databaseId: string,
) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (sql: string) => {
      requireOperationalDatabase(queryClient, projectId, databaseId)
      return executeMysqlDatabaseSql(projectId, databaseId, sql)
    },
    onSuccess: () =>
      refreshMysqlDatabaseCaches(queryClient, projectId, databaseId),
  })
}

export type MysqlSavedQueryLevel = 'user' | 'team'

export function useMysqlSelectedSchema(
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

    const next = resolveMysqlSelectedSchema({
      schemas: knownSchemas,
      persisted: parseMysqlSelectedSchema(accountPrefs, databaseId),
    })
    if (!next) return

    setSelectedSchemaState(next)
    initializedDatabaseIdRef.current = databaseId
  }, [accountPrefs, databaseId, knownSchemas])

  useEffect(() => {
    if (selectedSchema || knownSchemas.length === 0) return
    setSelectedSchemaState(
      resolveMysqlSelectedSchema({ schemas: knownSchemas, persisted: null }),
    )
  }, [knownSchemas, selectedSchema])

  const setSelectedSchema = useCallback(
    (schema: string) => {
      const trimmed = coerceMysqlStringValue(schema) ?? ''
      if (!trimmed) return
      setSelectedSchemaState(trimmed)
      if (!databaseId) return

      const currentAccount = getConsoleAccountFromCache(queryClient)
      if (!currentAccount) return

      void updateAccountPrefs({
        ...(currentAccount.prefs ?? {}),
        ...buildMysqlSelectedSchemaPrefs(databaseId, trimmed),
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

export function useMysqlSavedQueriesSort(
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

  const [sort, setSortState] = useState<MysqlSavedQueriesSort>(
    MYSQL_SAVED_QUERIES_DEFAULT_SORT,
  )
  const initializedDatabaseIdRef = useRef<string | null>(null)

  useEffect(() => {
    if (!databaseId) return
    if (initializedDatabaseIdRef.current === databaseId) return

    setSortState(parseMysqlSavedQueriesSort(accountPrefs, databaseId))
    initializedDatabaseIdRef.current = databaseId
  }, [accountPrefs, databaseId])

  useEffect(() => {
    initializedDatabaseIdRef.current = null
  }, [databaseId])

  const setSort = useCallback(
    (next: MysqlSavedQueriesSort) => {
      setSortState(next)
      if (!databaseId) return

      const currentAccount = getConsoleAccountFromCache(queryClient)
      if (!currentAccount) return

      void updateAccountPrefs({
        ...(currentAccount.prefs ?? {}),
        ...buildMysqlSavedQueriesSortPrefs(databaseId, next),
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

export function useMysqlSidebarTablesSort(
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

  const [sort, setSortState] = useState<MysqlSidebarTablesSort>(
    MYSQL_SIDEBAR_TABLES_DEFAULT_SORT,
  )
  const initializedDatabaseIdRef = useRef<string | null>(null)

  useEffect(() => {
    if (!databaseId) return
    if (initializedDatabaseIdRef.current === databaseId) return

    setSortState(parseMysqlSidebarTablesSort(accountPrefs, databaseId))
    initializedDatabaseIdRef.current = databaseId
  }, [accountPrefs, databaseId])

  useEffect(() => {
    initializedDatabaseIdRef.current = null
  }, [databaseId])

  const setSort = useCallback(
    (next: MysqlSidebarTablesSort) => {
      setSortState(next)
      if (!databaseId) return

      const currentAccount = getConsoleAccountFromCache(queryClient)
      if (!currentAccount) return

      void updateAccountPrefs({
        ...(currentAccount.prefs ?? {}),
        ...buildMysqlSidebarTablesSortPrefs(databaseId, next),
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

export function useMysqlSidebarPanel(
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

  const [panel, setPanelState] = useState<MysqlSidebarPanelPreference>(
    MYSQL_SIDEBAR_PANEL_DEFAULT,
  )
  const initializedDatabaseIdRef = useRef<string | null>(null)

  useEffect(() => {
    if (!databaseId) return
    if (initializedDatabaseIdRef.current === databaseId) return

    setPanelState(parseMysqlSidebarPanel(accountPrefs, databaseId))
    initializedDatabaseIdRef.current = databaseId
  }, [accountPrefs, databaseId])

  useEffect(() => {
    initializedDatabaseIdRef.current = null
  }, [databaseId])

  const setPanel = useCallback(
    (next: MysqlSidebarPanelPreference) => {
      setPanelState(next)
      if (!databaseId) return

      const currentAccount = getConsoleAccountFromCache(queryClient)
      if (!currentAccount) return

      void updateAccountPrefs({
        ...(currentAccount.prefs ?? {}),
        ...buildMysqlSidebarPanelPrefs(databaseId, next),
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

export function useMysqlSavedQueryScope(
  databaseId: string | null | undefined,
  account: { prefs?: Record<string, unknown> } | undefined,
  teamId: string | null | undefined,
) {
  const queryClient = useQueryClient()
  const { isLoading: teamLoading } = useConsoleTeam(teamId)
  const { userQueries, teamQueries, hasTeamLevel } = useMysqlSavedQueries(
    databaseId,
    account,
    teamId,
  )

  const [savedQueryLevel, setSavedQueryLevelState] =
    useState<MysqlSavedQueryLevel>('user')
  const initializedDatabaseIdRef = useRef<string | null>(null)

  useEffect(() => {
    if (!databaseId) return
    if (initializedDatabaseIdRef.current === databaseId) return
    if (!account) return
    if (teamId && teamLoading) return

    const next = resolveMysqlSavedQueriesScope({
      persisted: parseMysqlSavedQueriesScope(
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
    (level: MysqlSavedQueryLevel) => {
      setSavedQueryLevelState(level)
      if (!databaseId) return

      const currentAccount = getConsoleAccountFromCache(queryClient)
      if (!currentAccount) return

      void updateAccountPrefs({
        ...(currentAccount.prefs ?? {}),
        ...buildMysqlSavedQueriesScopePrefs(databaseId, level),
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

function buildNextMysqlSavedQueriesList(
  current: SavedMysqlQuery[],
  name: string,
  sql: string,
): SavedMysqlQuery[] {
  const trimmedSql = sql.trim()
  const trimmedName = name.trim().slice(0, MAX_SAVED_MYSQL_QUERY_NAME_LENGTH)
  if (!trimmedName) throw new Error('Name is required')
  if (!trimmedSql) throw new Error('SQL is required')

  const head = current[0]
  if (head && head.name === trimmedName && head.sql === trimmedSql) {
    return current
  }

  if (current.length >= MAX_SAVED_MYSQL_QUERIES) {
    throw new Error(`Maximum ${MAX_SAVED_MYSQL_QUERIES} saved queries`)
  }

  return [
    { id: crypto.randomUUID(), name: trimmedName, sql: trimmedSql },
    ...current,
  ].slice(0, MAX_SAVED_MYSQL_QUERIES)
}

/**
 * Saved MySQL queries for a dedicated database (account + team prefs).
 */
export function useMysqlSavedQueries(
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

  const userQueries: SavedMysqlQuery[] =
    databaseId && accountPrefs
      ? parseMysqlSavedQueries(accountPrefs, databaseId)
      : []

  const teamQueries: SavedMysqlQuery[] =
    databaseId && team?.prefs && teamId
      ? parseMysqlSavedQueries(
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
      if (trimmedSql.length > MAX_SAVED_MYSQL_QUERY_SQL_CHARS) {
        throw new Error('Query is too large to save')
      }
      const current = parseMysqlSavedQueries(currentAccount.prefs, databaseId)
      const next = buildNextMysqlSavedQueriesList(current, name, sql)
      return await updateAccountPrefs({
        ...currentAccount.prefs,
        ...buildMysqlSavedQueriesPrefs(databaseId, next),
      })
    },
    onMutate: async ({ name, sql }) => {
      if (!databaseId) return undefined

      const trimmedSql = sql.trim()
      const trimmedName = name.trim().slice(0, MAX_SAVED_MYSQL_QUERY_NAME_LENGTH)
      if (!trimmedName || !trimmedSql) return undefined

      await queryClient.cancelQueries({ queryKey: ['account', 'console'] })

      const previousAccounts = queryClient.getQueriesData<Models.User>({
        queryKey: ['account', 'console'],
      })

      queryClient.setQueriesData<Models.User>(
        { queryKey: ['account', 'console'] },
        (current) => {
          if (!current) return current
          const currentList = parseMysqlSavedQueries(current.prefs, databaseId)
          try {
            const next = buildNextMysqlSavedQueriesList(currentList, name, sql)
            return {
              ...current,
              prefs: {
                ...(current.prefs ?? {}),
                ...buildMysqlSavedQueriesPrefs(databaseId, next),
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
      if (trimmedSql.length > MAX_SAVED_MYSQL_QUERY_SQL_CHARS) {
        throw new Error('Query is too large to save')
      }
      await updateTeamPrefs.mutateAsync((freshPrefs) => {
        const current = parseMysqlSavedQueries(freshPrefs, databaseId)
        const next = buildNextMysqlSavedQueriesList(current, name, sql)
        return buildMysqlSavedQueriesPrefs(databaseId, next)
      })
    },
    onMutate: async ({ name, sql }) => {
      if (!databaseId || !teamId) return undefined

      const trimmedSql = sql.trim()
      const trimmedName = name.trim().slice(0, MAX_SAVED_MYSQL_QUERY_NAME_LENGTH)
      if (!trimmedName || !trimmedSql) return undefined

      const teamQueryKey = ['team', 'console', teamId] as const

      await queryClient.cancelQueries({ queryKey: teamQueryKey })

      const previousTeam = queryClient.getQueryData<{
        prefs?: Record<string, unknown>
      }>(teamQueryKey)

      queryClient.setQueryData(teamQueryKey, (current) => {
        if (!current) return current
        const currentList = parseMysqlSavedQueries(
          current.prefs as Record<string, unknown>,
          databaseId,
        )
        try {
          const next = buildNextMysqlSavedQueriesList(currentList, name, sql)
          return {
            ...current,
            prefs: {
              ...(current.prefs ?? {}),
              ...buildMysqlSavedQueriesPrefs(databaseId, next),
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
      const current = parseMysqlSavedQueries(currentAccount.prefs, databaseId)
      const next = current.filter((query) => query.id !== id)
      return await updateAccountPrefs({
        ...currentAccount.prefs,
        ...buildMysqlSavedQueriesPrefs(databaseId, next),
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
        const current = parseMysqlSavedQueries(freshPrefs, databaseId)
        return buildMysqlSavedQueriesPrefs(
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
    level: MysqlSavedQueryLevel
  }): Promise<SavedMysqlQuery | undefined> => {
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
      const queries = parseMysqlSavedQueries(
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
    const queries = parseMysqlSavedQueries(updatedAccount.prefs, databaseId)
    return (
      queries.find(
        (query) => query.name === trimmedName && query.sql === trimmedSql,
      ) ?? queries[0]
    )
  }

  const deleteSavedQuery = async (
    id: string,
    level: MysqlSavedQueryLevel,
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

const MYSQL_QUERY_HISTORY_PERSIST_DEBOUNCE_MS = 400
const MYSQL_SQL_EDITOR_STATE_PERSIST_DEBOUNCE_MS = 400

/**
 * Persisted MySQL SQL editor tabs for a database
 * (`console.mysqlSqlEditorState.<databaseId>`).
 */
export function useMysqlSqlEditorPersistence(
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

  const parseInitialEditorState = useCallback((): PersistedMysqlSqlEditorState | null => {
    if (!databaseId) return null
    return parseMysqlSqlEditorState(accountPrefs, databaseId)
  }, [accountPrefs, databaseId])

  const updateMutation = useMutation({
    mutationFn: async (value: PersistedMysqlSqlEditorState) => {
      const currentAccount = getConsoleAccountFromCache(queryClient)
      if (!currentAccount || !databaseId) {
        throw new Error('Account or database not available')
      }
      return await updateAccountPrefs(
        mergeMysqlSqlEditorStateIntoPrefs(
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
    (value: PersistedMysqlSqlEditorState) => {
      if (!databaseId) return

      const currentPrefs = (getConsoleAccountFromCache(queryClient)?.prefs ??
        {}) as UserPrefs
      const prefsKey = getMysqlSqlEditorStateKey(databaseId)
      const nextSerialized = buildMysqlSqlEditorStatePrefs(databaseId, value)[
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

      const patch = mergeMysqlSqlEditorStateIntoPrefs(
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
      }, MYSQL_SQL_EDITOR_STATE_PERSIST_DEBOUNCE_MS)
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
 * Persisted MySQL query history for a database
 * (`console.mysqlQueryHistory.<databaseId>`).
 */
export function useMysqlQueryHistory(
  databaseId: string | null | undefined,
  account: { prefs?: Record<string, unknown> } | undefined,
) {
  const queryClient = useQueryClient()
  const recentQueries: MysqlQueryHistoryEntry[] =
    databaseId && account?.prefs
      ? parseMysqlQueryHistory(account.prefs, databaseId)
      : []

  const persistTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null)

  const updateMutation = useMutation({
    mutationFn: async (value: MysqlQueryHistoryEntry[]) => {
      if (!account || !databaseId) {
        throw new Error('Account or database not available')
      }
      return await updateAccountPrefs(
        mergeMysqlQueryHistoryIntoPrefs(
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
    (value: MysqlQueryHistoryEntry[]) => {
      if (!account || !databaseId) return
      const patch = mergeMysqlQueryHistoryIntoPrefs(
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
      }, MYSQL_QUERY_HISTORY_PERSIST_DEBOUNCE_MS)
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

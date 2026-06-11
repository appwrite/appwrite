import { useCallback, useEffect, useRef, useState } from 'react'
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
  normalizePostgresExecutionResult,
  wrapPostgresSqlForDisplay,
} from '@/lib/postgres-execution-values'
import {
  buildPostgresCountSql,
  buildPostgresSelectSql,
  executionResultRows,
  POSTGRES_LIST_COLUMNS_SQL,
  POSTGRES_LIST_SCHEMAS_SQL,
  POSTGRES_LIST_TABLES_SQL,
  type PostgresColumnRow,
  type PostgresSchemaRow,
  type PostgresTableRow,
} from '@/lib/postgres-sql'
import { parsePostgresTableId } from '@/lib/postgres-database-routes'
import {
  buildPostgresSavedQueriesPrefs,
  buildPostgresSavedQueriesScopePrefs,
  MAX_SAVED_POSTGRES_QUERIES,
  MAX_SAVED_POSTGRES_QUERY_NAME_LENGTH,
  MAX_SAVED_POSTGRES_QUERY_SQL_CHARS,
  parsePostgresSavedQueries,
  parsePostgresSavedQueriesScope,
  resolvePostgresSavedQueriesScope,
  type SavedPostgresQuery,
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
        queryKey: ['postgres-columns', 'project', projectId, databaseId],
      })
      void queryClient.invalidateQueries({
        queryKey: ['postgres-table-rows', 'project', projectId, databaseId],
      })
    },
  })
}

export type PostgresSavedQueryLevel = 'user' | 'team'

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

import type { QueryClient } from '@tanstack/react-query'
import { redirect } from '@tanstack/react-router'
import {
  postgresColumnsQueryOptions,
  postgresDatabaseQueryOptions,
  postgresSchemasQueryOptions,
  postgresTableColumnsQueryOptions,
  postgresTableIndexesQueryOptions,
  postgresTableInfoQueryOptions,
  postgresTablesQueryOptions,
  projectQueryOptions,
} from '@/lib/react-query/hooks'
import {
  normalizePostgresTableRouteId,
  postgresNav,
} from '@/lib/postgres-database-routes'

async function ensurePostgresTableExists(
  queryClient: QueryClient,
  projectId: string,
  databaseId: string,
  tableId: string,
) {
  const normalizedTableId = normalizePostgresTableRouteId(tableId)

  let tablesData: { tables?: { table_schema: string; table_name: string }[] } | null =
    null
  try {
    tablesData = (await queryClient.ensureQueryData(
      postgresTablesQueryOptions(projectId, databaseId),
    )) as { tables?: { table_schema: string; table_name: string }[] }
  } catch {
    /* allow navigation; view handles empty/error state */
  }

  const tableExists = tablesData?.tables?.some((table) => {
    const id = `${table.table_schema}.${table.table_name}`
    return id === normalizedTableId
  })

  if (tablesData && !tableExists) {
    throw redirect({
      ...postgresNav({ projectId, databaseId }).sql(),
      replace: true,
    })
  }

  return normalizedTableId
}

export async function prefetchPostgresTableLayoutData(
  queryClient: QueryClient,
  projectId: string,
  databaseId: string,
  tableId: string,
) {
  await queryClient.ensureQueryData(projectQueryOptions(projectId))
  await queryClient.ensureQueryData(
    postgresDatabaseQueryOptions(projectId, databaseId),
  )

  const normalizedTableId = await ensurePostgresTableExists(
    queryClient,
    projectId,
    databaseId,
    tableId,
  )

  await Promise.allSettled([
    queryClient.ensureQueryData(
      postgresSchemasQueryOptions(projectId, databaseId),
    ),
    queryClient.ensureQueryData(
      postgresColumnsQueryOptions(projectId, databaseId),
    ),
  ])

  return normalizedTableId
}

export async function prefetchPostgresTableRouteData(
  queryClient: QueryClient,
  projectId: string,
  databaseId: string,
  tableId: string,
) {
  const normalizedTableId = await prefetchPostgresTableLayoutData(
    queryClient,
    projectId,
    databaseId,
    tableId,
  )

  await Promise.all([
    queryClient.ensureQueryData(
      postgresTableColumnsQueryOptions(
        projectId,
        databaseId,
        normalizedTableId,
      ),
    ),
    queryClient.ensureQueryData(
      postgresTableIndexesQueryOptions(
        projectId,
        databaseId,
        normalizedTableId,
      ),
    ),
    queryClient.ensureQueryData(
      postgresTableInfoQueryOptions(projectId, databaseId, normalizedTableId),
    ),
  ])
}

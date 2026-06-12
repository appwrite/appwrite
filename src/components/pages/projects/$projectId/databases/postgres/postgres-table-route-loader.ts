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
import { postgresNav } from '@/lib/postgres-database-routes'

export async function prefetchPostgresTableRouteData(
  queryClient: QueryClient,
  projectId: string,
  databaseId: string,
  tableId: string,
) {
  await queryClient.ensureQueryData(projectQueryOptions(projectId))
  await queryClient.ensureQueryData(
    postgresDatabaseQueryOptions(projectId, databaseId),
  )

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
    return id === tableId
  })

  if (tablesData && !tableExists) {
    throw redirect({
      ...postgresNav({ projectId, databaseId }).sql(),
      replace: true,
    })
  }

  await Promise.allSettled([
    queryClient.ensureQueryData(
      postgresSchemasQueryOptions(projectId, databaseId),
    ),
    queryClient.ensureQueryData(
      postgresColumnsQueryOptions(projectId, databaseId),
    ),
    queryClient.ensureQueryData(
      postgresTableColumnsQueryOptions(projectId, databaseId, tableId),
    ),
    queryClient.ensureQueryData(
      postgresTableIndexesQueryOptions(projectId, databaseId, tableId),
    ),
    queryClient.ensureQueryData(
      postgresTableInfoQueryOptions(projectId, databaseId, tableId),
    ),
  ])
}

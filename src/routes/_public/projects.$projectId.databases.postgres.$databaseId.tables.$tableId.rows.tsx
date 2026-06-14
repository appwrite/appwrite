import { createFileRoute, redirect } from '@tanstack/react-router'
import type { QueryClient } from '@tanstack/react-query'
import {
  postgresColumnsQueryOptions,
  postgresDatabaseConnectionsQueryOptions,
  postgresDatabaseCredentialsQueryOptions,
  postgresDatabaseQueryOptions,
  postgresSchemasQueryOptions,
  postgresTableRowsQueryOptions,
  postgresTablesQueryOptions,
  projectQueryOptions,
} from '@/lib/react-query/hooks'
import { postgresNav } from '@/lib/postgres-database-routes'
import { ROWS_DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import { pageTitle } from '@/lib/utils/page-title'

const DEFAULT_PAGE = 1

async function prefetchPostgresRouteData(
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
    /* allow navigation; workspace handles empty/error state */
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
      postgresTableRowsQueryOptions(
        projectId,
        databaseId,
        tableId,
        DEFAULT_PAGE - 1,
        ROWS_DEFAULT_PAGE_SIZE,
      ),
    ),
    queryClient.ensureQueryData(
      postgresDatabaseCredentialsQueryOptions(projectId, databaseId),
    ),
  ])
}

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/postgres/$databaseId/tables/$tableId/rows',
)({
  beforeLoad: ({ params }) => {
    if (params.tableId === '-') {
      throw redirect({
        ...postgresNav({
          projectId: params.projectId,
          databaseId: params.databaseId,
        }).sql(),
        replace: true,
      })
    }
  },
  head: () => ({
    meta: [{ title: pageTitle('PostgreSQL', 'Databases') }],
  }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return

    const { projectId, databaseId, tableId } = params
    const { queryClient } = context

    await prefetchPostgresRouteData(
      queryClient,
      projectId,
      databaseId,
      tableId,
    )
  },
  component: PostgresRowsPage,
})

function PostgresRowsPage() {
  return null
}

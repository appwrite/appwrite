import { createFileRoute, redirect } from '@tanstack/react-router'
import { pageTitle } from '@/lib/utils/page-title'
import {
  postgresDatabaseHome,
  postgresNav,
  postgresTableId,
} from '@/lib/postgres-database-routes'
import {
  fetchFirstPostgresTable,
  postgresDatabaseQueryOptions,
  projectQueryOptions,
} from '@/lib/react-query/hooks'

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/postgres/$databaseId/',
)({
  head: () => ({ meta: [{ title: pageTitle('PostgreSQL', 'Databases') }] }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return

    const { projectId, databaseId } = params
    const { queryClient } = context

    await queryClient.ensureQueryData(projectQueryOptions(projectId))
    await queryClient.ensureQueryData(
      postgresDatabaseQueryOptions(projectId, databaseId),
    )

    let firstTable: { table_schema: string; table_name: string } | null = null
    try {
      firstTable = await fetchFirstPostgresTable(projectId, databaseId)
    } catch {
      /* navigate to overview even if SQL metadata is unavailable */
    }

    if (firstTable) {
      throw redirect({
        ...postgresDatabaseHome({
          projectId,
          databaseId,
          tableId: postgresTableId(
            firstTable.table_schema,
            firstTable.table_name,
          ),
        }),
        replace: true,
      })
    }

    throw redirect({
      ...postgresNav({ projectId, databaseId }).sql(),
      replace: true,
    })
  },
})

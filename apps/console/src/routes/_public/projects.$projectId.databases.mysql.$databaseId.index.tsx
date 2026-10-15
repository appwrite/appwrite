import { createFileRoute, redirect } from '@tanstack/react-router'
import { pageTitle } from '@/lib/utils/page-title'
import {
  mysqlDatabaseHome,
  mysqlNav,
  mysqlTableId,
} from '@/lib/mysql-database-routes'
import {
  fetchFirstMysqlTable,
  mysqlDatabaseQueryOptions,
  projectQueryOptions,
} from '@/lib/react-query/hooks'

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/mysql/$databaseId/',
)({
  head: () => ({ meta: [{ title: pageTitle('MySQL', 'Databases') }] }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return

    const { projectId, databaseId } = params
    const { queryClient } = context

    await queryClient.ensureQueryData(projectQueryOptions(projectId))
    await queryClient.ensureQueryData(
      mysqlDatabaseQueryOptions(projectId, databaseId),
    )

    let firstTable: { table_schema: string; table_name: string } | null = null
    try {
      firstTable = await fetchFirstMysqlTable(projectId, databaseId)
    } catch {
      /* navigate to overview even if SQL metadata is unavailable */
    }

    if (firstTable) {
      throw redirect({
        ...mysqlDatabaseHome({
          projectId,
          databaseId,
          tableId: mysqlTableId(
            firstTable.table_schema,
            firstTable.table_name,
          ),
        }),
        replace: true,
      })
    }

    throw redirect({
      ...mysqlNav({ projectId, databaseId }).sql(),
      replace: true,
    })
  },
})

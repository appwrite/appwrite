import { createFileRoute, redirect } from '@tanstack/react-router'
import { MysqlTableLayout } from '@/components/pages/projects/$projectId/databases/mysql/MysqlTableLayout'
import { prefetchMysqlTableLayoutData } from '@/components/pages/projects/$projectId/databases/mysql/mysql-table-route-loader'
import { mysqlNav } from '@/lib/mysql-database-routes'

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/mysql/$databaseId/tables/$tableId',
)({
  beforeLoad: ({ params }) => {
    if (params.tableId === '-') {
      throw redirect({
        ...mysqlNav({
          projectId: params.projectId,
          databaseId: params.databaseId,
        }).sql(),
        replace: true,
      })
    }
  },
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return

    const { projectId, databaseId, tableId } = params
    await prefetchMysqlTableLayoutData(
      context.queryClient,
      projectId,
      databaseId,
      tableId,
    )
  },
  component: MysqlTableLayout,
})

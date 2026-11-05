import { createFileRoute, redirect } from '@tanstack/react-router'
import { MysqlTableSecurityView } from '@/components/pages/projects/$projectId/databases/mysql/MysqlTableSecurityView'
import { prefetchMysqlTableSecurityRouteData } from '@/components/pages/projects/$projectId/databases/mysql/mysql-table-route-loader'
import { normalizeMysqlTableRouteId, mysqlNav } from '@/lib/mysql-database-routes'
import { canAccessTableSecuritySettings } from '@/lib/console-rbac-loader'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/mysql/$databaseId/tables/$tableId/security',
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
  head: () => ({
    meta: [{ title: pageTitle('MySQL', 'Databases') }],
  }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return

    const { projectId, databaseId, tableId } = params
    const { queryClient } = context

    const canAccess = await canAccessTableSecuritySettings(queryClient, projectId)
    if (!canAccess) {
      throw redirect({
        ...mysqlNav({ projectId, databaseId })
          .table({ tableId })
          .rows(),
        replace: true,
      })
    }

    await prefetchMysqlTableSecurityRouteData(
      queryClient,
      projectId,
      databaseId,
      tableId,
    )
  },
  component: MysqlTableSecurityPage,
})

function MysqlTableSecurityPage() {
  const { databaseId, tableId } = Route.useParams()
  const normalizedTableId = normalizeMysqlTableRouteId(tableId)

  return (
    <MysqlTableSecurityView
      databaseId={databaseId}
      tableId={normalizedTableId}
    />
  )
}

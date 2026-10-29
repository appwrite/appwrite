import { createFileRoute, redirect } from '@tanstack/react-router'
import { TableStructureContent } from '@/components/pages/projects/$projectId/databases/mysql/TableStructureView'
import { prefetchMysqlTableRouteData } from '@/components/pages/projects/$projectId/databases/mysql/mysql-table-route-loader'
import { normalizeMysqlTableRouteId, mysqlNav } from '@/lib/mysql-database-routes'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/mysql/$databaseId/tables/$tableId/settings',
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
    await prefetchMysqlTableRouteData(
      context.queryClient,
      projectId,
      databaseId,
      tableId,
      { includeTableInfo: true },
    )
  },
  component: MysqlTableSettingsPage,
})

function MysqlTableSettingsPage() {
  const { databaseId, tableId } = Route.useParams()
  const normalizedTableId = normalizeMysqlTableRouteId(tableId)

  return (
    <TableStructureContent
      databaseId={databaseId}
      tableId={normalizedTableId}
      activeTab="settings"
    />
  )
}

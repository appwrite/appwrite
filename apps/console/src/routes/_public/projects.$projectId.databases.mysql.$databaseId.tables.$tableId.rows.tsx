import { createFileRoute, redirect } from '@tanstack/react-router'
import {
  normalizeMysqlTableRouteId,
  mysqlNav,
} from '@/lib/mysql-database-routes'
import { MysqlTableRowsView } from '@/components/pages/projects/$projectId/databases/mysql/MysqlTableRowsView'
import { prefetchMysqlTableRowsRouteData } from '@/components/pages/projects/$projectId/databases/mysql/mysql-table-route-loader'
import { listSearchSchema } from '@/lib/table-filters'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/mysql/$databaseId/tables/$tableId/rows',
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
  validateSearch: listSearchSchema,
  head: () => ({
    meta: [{ title: pageTitle('MySQL', 'Databases') }],
  }),
  loader: async ({ params, context, search: routeSearch }) => {
    if (typeof window === 'undefined') return

    const { projectId, databaseId, tableId } = params
    await prefetchMysqlTableRowsRouteData(
      context.queryClient,
      projectId,
      databaseId,
      tableId,
      routeSearch as Record<string, unknown> | undefined,
    )
  },
  component: MysqlRowsPage,
})

function MysqlRowsPage() {
  const { databaseId, tableId } = Route.useParams()
  const normalizedTableId = normalizeMysqlTableRouteId(tableId)

  return (
    <MysqlTableRowsView databaseId={databaseId} tableId={normalizedTableId} />
  )
}

import { createFileRoute, redirect } from '@tanstack/react-router'
import {
  normalizePostgresTableRouteId,
  postgresNav,
} from '@/lib/postgres-database-routes'
import { PostgresTableRowsView } from '@/components/pages/projects/$projectId/databases/postgres/PostgresTableRowsView'
import { prefetchPostgresTableRowsRouteData } from '@/components/pages/projects/$projectId/databases/postgres/postgres-table-route-loader'
import { listSearchSchema } from '@/lib/table-filters'
import { pageTitle } from '@/lib/utils/page-title'

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
  validateSearch: listSearchSchema,
  head: () => ({
    meta: [{ title: pageTitle('PostgreSQL', 'Databases') }],
  }),
  loader: async ({ params, context, search: routeSearch }) => {
    if (typeof window === 'undefined') return

    const { projectId, databaseId, tableId } = params
    await prefetchPostgresTableRowsRouteData(
      context.queryClient,
      projectId,
      databaseId,
      tableId,
      routeSearch as Record<string, unknown> | undefined,
    )
  },
  component: PostgresRowsPage,
})

function PostgresRowsPage() {
  const { databaseId, tableId } = Route.useParams()
  const normalizedTableId = normalizePostgresTableRouteId(tableId)

  return (
    <PostgresTableRowsView databaseId={databaseId} tableId={normalizedTableId} />
  )
}

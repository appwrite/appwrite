import { createFileRoute, redirect } from '@tanstack/react-router'
import { TableStructureContent } from '@/components/pages/projects/$projectId/databases/postgres/TableStructureView'
import { prefetchPostgresTableRouteData } from '@/components/pages/projects/$projectId/databases/postgres/postgres-table-route-loader'
import { normalizePostgresTableRouteId, postgresNav } from '@/lib/postgres-database-routes'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/postgres/$databaseId/tables/$tableId/columns',
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
    await prefetchPostgresTableRouteData(
      context.queryClient,
      projectId,
      databaseId,
      tableId,
    )
  },
  component: PostgresColumnsPage,
})

function PostgresColumnsPage() {
  const { databaseId, tableId } = Route.useParams()
  const normalizedTableId = normalizePostgresTableRouteId(tableId)

  return (
    <TableStructureContent
      databaseId={databaseId}
      tableId={normalizedTableId}
      activeTab="columns"
    />
  )
}

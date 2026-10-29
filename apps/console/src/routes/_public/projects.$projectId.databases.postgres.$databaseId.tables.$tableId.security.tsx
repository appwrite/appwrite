import { createFileRoute, redirect } from '@tanstack/react-router'
import { PostgresTableSecurityView } from '@/components/pages/projects/$projectId/databases/postgres/PostgresTableSecurityView'
import { prefetchPostgresTableSecurityRouteData } from '@/components/pages/projects/$projectId/databases/postgres/postgres-table-route-loader'
import { normalizePostgresTableRouteId, postgresNav } from '@/lib/postgres-database-routes'
import { canAccessTableSecuritySettings } from '@/lib/console-rbac-loader'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/postgres/$databaseId/tables/$tableId/security',
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

    const canAccess = await canAccessTableSecuritySettings(queryClient, projectId)
    if (!canAccess) {
      throw redirect({
        ...postgresNav({ projectId, databaseId })
          .table({ tableId })
          .rows(),
        replace: true,
      })
    }

    await prefetchPostgresTableSecurityRouteData(
      queryClient,
      projectId,
      databaseId,
      tableId,
    )
  },
  component: PostgresTableSecurityPage,
})

function PostgresTableSecurityPage() {
  const { databaseId, tableId } = Route.useParams()
  const normalizedTableId = normalizePostgresTableRouteId(tableId)

  return (
    <PostgresTableSecurityView
      databaseId={databaseId}
      tableId={normalizedTableId}
    />
  )
}

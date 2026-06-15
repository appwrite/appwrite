import { createFileRoute, Outlet, redirect } from '@tanstack/react-router'
import { PostgresShell } from '@/components/pages/projects/$projectId/databases/postgres/PostgresShell'
import { prefetchPostgresTableLayoutData } from '@/components/pages/projects/$projectId/databases/postgres/postgres-table-route-loader'
import { postgresNav } from '@/lib/postgres-database-routes'

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/postgres/$databaseId/tables/$tableId',
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
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return

    const { projectId, databaseId, tableId } = params
    await prefetchPostgresTableLayoutData(
      context.queryClient,
      projectId,
      databaseId,
      tableId,
    )
  },
  component: PostgresTableLayout,
})

function PostgresTableLayout() {
  const { databaseId, tableId } = Route.useParams()

  return (
    <PostgresShell databaseId={databaseId} tableId={tableId}>
      <Outlet />
    </PostgresShell>
  )
}

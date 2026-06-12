import { createFileRoute, redirect, Outlet } from '@tanstack/react-router'
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
  component: PostgresTableLayout,
})

function PostgresTableLayout() {
  return <Outlet />
}

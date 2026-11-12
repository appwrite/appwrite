import { createFileRoute, redirect } from '@tanstack/react-router'
import { PostgresTableLayout } from '@/components/pages/projects/$projectId/databases/postgres/PostgresTableLayout'
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

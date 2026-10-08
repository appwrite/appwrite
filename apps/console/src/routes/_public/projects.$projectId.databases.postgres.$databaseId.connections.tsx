import { createFileRoute } from '@tanstack/react-router'
import { PostgresConnectionDetails } from '@/components/pages/projects/$projectId/databases/postgres/PostgresConnectionDetails'
import { prefetchPostgresShellData } from '@/components/pages/projects/$projectId/databases/postgres/postgres-tab-route-loader'
import { postgresActiveConnectionsQueryOptions } from '@/lib/react-query/hooks'
import { POSTGRES_DATABASE_TAB_LABELS } from '@/lib/postgres-database-routes'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/postgres/$databaseId/connections',
)({
  head: () => ({
    meta: [
      {
        title: pageTitle(POSTGRES_DATABASE_TAB_LABELS.connections, 'Databases'),
      },
    ],
  }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return { database: null }
    const { projectId, databaseId } = params
    const database = await prefetchPostgresShellData(
      context.queryClient,
      projectId,
      databaseId,
    )
    await context.queryClient.ensureQueryData(
      postgresActiveConnectionsQueryOptions(projectId, databaseId),
    )
    return database
  },
  component: PostgresConnectionsPage,
})

function PostgresConnectionsPage() {
  const { projectId, databaseId } = Route.useParams()
  return (
    <PostgresConnectionDetails
      projectId={projectId}
      databaseId={databaseId}
    />
  )
}

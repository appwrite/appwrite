import { createFileRoute } from '@tanstack/react-router'
import { PostgresShell } from '@/components/pages/projects/$projectId/databases/postgres/PostgresShell'
import { PostgresConnect } from '@/components/pages/projects/$projectId/databases/postgres/PostgresConnect'
import { prefetchPostgresShellData } from '@/components/pages/projects/$projectId/databases/postgres/postgres-tab-route-loader'
import {
  postgresDatabaseConnectionsQueryOptions,
  postgresDatabaseCredentialsQueryOptions,
} from '@/lib/react-query/hooks'
import { POSTGRES_DATABASE_TAB_LABELS } from '@/lib/postgres-database-routes'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/postgres/$databaseId/connect',
)({
  head: () => ({
    meta: [
      {
        title: pageTitle(POSTGRES_DATABASE_TAB_LABELS.connect, 'Databases'),
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
    await Promise.all([
      context.queryClient.ensureQueryData(
        postgresDatabaseConnectionsQueryOptions(projectId, databaseId),
      ),
      context.queryClient.ensureQueryData(
        postgresDatabaseCredentialsQueryOptions(projectId, databaseId),
      ),
    ])
    return database
  },
  component: PostgresConnectPage,
})

function PostgresConnectPage() {
  const { projectId, databaseId } = Route.useParams()
  return (
    <PostgresShell databaseId={databaseId} databaseTab="connect">
      <PostgresConnect projectId={projectId} databaseId={databaseId} />
    </PostgresShell>
  )
}

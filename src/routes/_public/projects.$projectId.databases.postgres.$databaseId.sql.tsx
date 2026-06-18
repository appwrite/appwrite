import { createFileRoute } from '@tanstack/react-router'
import { prefetchPostgresShellData } from '@/components/pages/projects/$projectId/databases/postgres/postgres-tab-route-loader'
import {
  postgresDatabaseConnectionsQueryOptions,
  postgresDatabaseCredentialsQueryOptions,
} from '@/lib/react-query/hooks'
import { POSTGRES_DATABASE_TAB_LABELS } from '@/lib/postgres-database-routes'
import { PostgresSqlWorkbench } from '@/components/pages/projects/$projectId/databases/postgres/Workspace'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/postgres/$databaseId/sql',
)({
  head: () => ({
    meta: [
      {
        title: pageTitle(POSTGRES_DATABASE_TAB_LABELS.sql, 'Databases'),
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
  component: PostgresSqlEditorPage,
})

function PostgresSqlEditorPage() {
  const { databaseId } = Route.useParams()

  return <PostgresSqlWorkbench databaseId={databaseId} />
}

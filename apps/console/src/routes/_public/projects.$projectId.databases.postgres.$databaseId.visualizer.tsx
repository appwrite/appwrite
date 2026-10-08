import { createFileRoute } from '@tanstack/react-router'
import { PostgresSchemaVisualizer } from '@/components/pages/projects/$projectId/databases/postgres/SchemaVisualizer'
import { prefetchPostgresShellData } from '@/components/pages/projects/$projectId/databases/postgres/postgres-tab-route-loader'
import { POSTGRES_DATABASE_TAB_LABELS } from '@/lib/postgres-database-routes'
import { postgresSidebarSchemasInfiniteQueryOptions } from '@/lib/react-query/hooks/postgres-databases'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/postgres/$databaseId/visualizer',
)({
  head: () => ({
    meta: [
      {
        title: pageTitle(POSTGRES_DATABASE_TAB_LABELS.visualizer, 'Databases'),
      },
    ],
  }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return { database: null }

    const { projectId, databaseId } = params
    const { queryClient } = context

    const shellData = await prefetchPostgresShellData(
      queryClient,
      projectId,
      databaseId,
    )

    await queryClient
      .prefetchInfiniteQuery(
        postgresSidebarSchemasInfiniteQueryOptions(projectId, databaseId, ''),
      )
      .catch(() => {
        /* optional prefetch */
      })

    return shellData
  },
  component: PostgresVisualizerPage,
})

function PostgresVisualizerPage() {
  const { databaseId } = Route.useParams()
  return <PostgresSchemaVisualizer databaseId={databaseId} />
}

import { createFileRoute } from '@tanstack/react-router'
import { PostgresShell } from '@/components/pages/projects/$projectId/databases/postgres/PostgresShell'
import { TabPlaceholder } from '@/components/pages/projects/$projectId/databases/postgres/TabPlaceholder'
import { prefetchPostgresShellData } from '@/components/pages/projects/$projectId/databases/postgres/postgres-tab-route-loader'
import { POSTGRES_DATABASE_TAB_LABELS } from '@/lib/postgres-database-routes'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/postgres/$databaseId/visualizer',
)({
  head: ({ loaderData }) => ({
    meta: [
      {
        title: pageTitle(
          loaderData?.database?.name
            ? `${POSTGRES_DATABASE_TAB_LABELS.visualizer} · ${loaderData.database.name}`
            : POSTGRES_DATABASE_TAB_LABELS.visualizer,
          'Databases',
        ),
      },
    ],
  }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return
    return prefetchPostgresShellData(
      context.queryClient,
      params.projectId,
      params.databaseId,
    )
  },
  component: PostgresVisualizerPage,
})

function PostgresVisualizerPage() {
  const { databaseId } = Route.useParams()
  return (
    <PostgresShell databaseId={databaseId} databaseTab="visualizer">
      <TabPlaceholder tab="visualizer" />
    </PostgresShell>
  )
}

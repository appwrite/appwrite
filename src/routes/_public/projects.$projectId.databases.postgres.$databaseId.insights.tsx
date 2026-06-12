import { createFileRoute, redirect } from '@tanstack/react-router'
import { PostgresShell } from '@/components/pages/projects/$projectId/databases/postgres/PostgresShell'
import { TabPlaceholder } from '@/components/pages/projects/$projectId/databases/postgres/TabPlaceholder'
import { prefetchPostgresShellData } from '@/components/pages/projects/$projectId/databases/postgres/postgres-tab-route-loader'
import {
  POSTGRES_DATABASE_TAB_LABELS,
  postgresNav,
} from '@/lib/postgres-database-routes'
import { pageTitle } from '@/lib/utils/page-title'
import { getActiveProfileFeatures } from '@/lib/console-profiles'

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/postgres/$databaseId/insights',
)({
  beforeLoad: ({ params }) => {
    if (!getActiveProfileFeatures().databaseInsights) {
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
    meta: [
      {
        title: pageTitle(POSTGRES_DATABASE_TAB_LABELS.insights, 'Databases'),
      },
    ],
  }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return { database: null }
    return prefetchPostgresShellData(
      context.queryClient,
      params.projectId,
      params.databaseId,
    )
  },
  component: PostgresInsightsPage,
})

function PostgresInsightsPage() {
  const { databaseId } = Route.useParams()
  return (
    <PostgresShell databaseId={databaseId} databaseTab="insights">
      <TabPlaceholder tab="insights" />
    </PostgresShell>
  )
}

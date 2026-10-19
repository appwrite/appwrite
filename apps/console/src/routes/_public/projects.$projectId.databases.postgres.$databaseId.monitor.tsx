import { createFileRoute, redirect } from '@tanstack/react-router'
import { View as PostgresMonitorView } from '@/components/pages/projects/$projectId/databases/postgres/Monitor'
import { prefetchPostgresShellData } from '@/components/pages/projects/$projectId/databases/postgres/postgres-tab-route-loader'
import {
  POSTGRES_DATABASE_TAB_LABELS,
  postgresNav,
} from '@/lib/postgres-database-routes'
import { pageTitle } from '@/lib/utils/page-title'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import {
  postgresConnectionAppsQueryOptions,
  postgresConnectionStatesQueryOptions,
  postgresMetricsSnapshotQueryOptions,
  postgresTableActivityQueryOptions,
} from '@/lib/react-query/hooks/postgres-metrics'

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/postgres/$databaseId/monitor',
)({
  beforeLoad: ({ params }) => {
    if (!getActiveProfileFeatures().usageStats) {
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
        title: pageTitle(POSTGRES_DATABASE_TAB_LABELS.monitor, 'Databases'),
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
    await Promise.all([
      queryClient.ensureQueryData(
        postgresMetricsSnapshotQueryOptions(projectId, databaseId),
      ),
      queryClient.ensureQueryData(
        postgresConnectionStatesQueryOptions(projectId, databaseId),
      ),
      queryClient.ensureQueryData(
        postgresConnectionAppsQueryOptions(projectId, databaseId),
      ),
      queryClient.ensureQueryData(
        postgresTableActivityQueryOptions(projectId, databaseId),
      ),
    ]).catch(() => {
      /* Monitor still renders with per-query error states */
    })
    return shellData
  },
  component: PostgresMonitorPage,
})

function PostgresMonitorPage() {
  const { projectId, databaseId } = Route.useParams()
  return <PostgresMonitorView projectId={projectId} databaseId={databaseId} />
}

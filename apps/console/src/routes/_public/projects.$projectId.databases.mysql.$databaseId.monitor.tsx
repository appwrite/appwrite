import { createFileRoute, redirect } from '@tanstack/react-router'
import { View as MysqlMonitorView } from '@/components/pages/projects/$projectId/databases/mysql/Monitor'
import { prefetchMysqlShellData } from '@/components/pages/projects/$projectId/databases/mysql/mysql-tab-route-loader'
import {
  MYSQL_DATABASE_TAB_LABELS,
  mysqlNav,
} from '@/lib/mysql-database-routes'
import { pageTitle } from '@/lib/utils/page-title'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import {
  mysqlConnectionAppsQueryOptions,
  mysqlConnectionStatesQueryOptions,
  mysqlMetricsSnapshotQueryOptions,
  mysqlTableActivityQueryOptions,
} from '@/lib/react-query/hooks/mysql-metrics'

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/mysql/$databaseId/monitor',
)({
  beforeLoad: ({ params }) => {
    if (!getActiveProfileFeatures().usageStats) {
      throw redirect({
        ...mysqlNav({
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
        title: pageTitle(MYSQL_DATABASE_TAB_LABELS.monitor, 'Databases'),
      },
    ],
  }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return { database: null }
    const { projectId, databaseId } = params
    const { queryClient } = context
    const shellData = await prefetchMysqlShellData(
      queryClient,
      projectId,
      databaseId,
    )
    await Promise.all([
      queryClient.ensureQueryData(
        mysqlMetricsSnapshotQueryOptions(projectId, databaseId),
      ),
      queryClient.ensureQueryData(
        mysqlConnectionStatesQueryOptions(projectId, databaseId),
      ),
      queryClient.ensureQueryData(
        mysqlConnectionAppsQueryOptions(projectId, databaseId),
      ),
      queryClient.ensureQueryData(
        mysqlTableActivityQueryOptions(projectId, databaseId),
      ),
    ]).catch(() => {
      /* Monitor still renders with per-query error states */
    })
    return shellData
  },
  component: MysqlMonitorPage,
})

function MysqlMonitorPage() {
  const { projectId, databaseId } = Route.useParams()
  return <MysqlMonitorView projectId={projectId} databaseId={databaseId} />
}

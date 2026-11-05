import { createFileRoute, redirect } from '@tanstack/react-router'
import { View as MysqlBackupsView } from '@/components/pages/projects/$projectId/databases/mysql/Backups'
import { prefetchMysqlShellData } from '@/components/pages/projects/$projectId/databases/mysql/mysql-tab-route-loader'
import {
  MYSQL_DATABASE_TAB_LABELS,
  mysqlNav,
} from '@/lib/mysql-database-routes'
import { pageTitle } from '@/lib/utils/page-title'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import {
  MYSQL_BACKUPS_PAGE_SIZE,
  mysqlBackupPoliciesQueryOptions,
  mysqlBackupsQueryOptions,
} from '@/lib/react-query/hooks'

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/mysql/$databaseId/backups',
)({
  beforeLoad: ({ params }) => {
    if (!getActiveProfileFeatures().databaseBackups) {
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
        title: pageTitle(MYSQL_DATABASE_TAB_LABELS.backups, 'Databases'),
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
        mysqlBackupPoliciesQueryOptions(projectId, databaseId),
      ),
      queryClient.ensureQueryData(
        mysqlBackupsQueryOptions(
          projectId,
          databaseId,
          0,
          MYSQL_BACKUPS_PAGE_SIZE,
        ),
      ),
    ]).catch(() => {
      /* Backups still render with per-query error states */
    })
    return shellData
  },
  component: MysqlBackupsPage,
})

function MysqlBackupsPage() {
  const { projectId, databaseId } = Route.useParams()
  return <MysqlBackupsView projectId={projectId} databaseId={databaseId} />
}

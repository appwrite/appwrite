import { createFileRoute, redirect } from '@tanstack/react-router'
import { MysqlDatabaseSettingsLayout } from '@/components/pages/projects/$projectId/databases/mysql/settings/MysqlDatabaseSettingsLayout'
import { prefetchMysqlShellData } from '@/components/pages/projects/$projectId/databases/mysql/mysql-tab-route-loader'
import { MYSQL_DATABASE_TAB_LABELS } from '@/lib/mysql-database-routes'
import {
  databaseSpecificationsQueryOptions,
  MYSQL_DATABASE_SPECS_SOURCE,
} from '@/lib/react-query/hooks'
import { canAccessMysqlDatabaseSettings } from '@/lib/console-rbac-loader'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/mysql/$databaseId/settings',
)({
  head: () => ({
    meta: [
      {
        title: pageTitle(MYSQL_DATABASE_TAB_LABELS.settings, 'Databases'),
      },
    ],
  }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return { database: null }

    const { projectId, databaseId } = params
    const { queryClient } = context

    const canAccess = await canAccessMysqlDatabaseSettings(
      queryClient,
      projectId,
    )
    if (!canAccess) {
      throw redirect({
        to: '/projects/$projectId/databases/mysql/$databaseId',
        params: { projectId, databaseId },
        replace: true,
      })
    }

    const shellData = await prefetchMysqlShellData(
      queryClient,
      projectId,
      databaseId,
    )

    await queryClient.ensureQueryData(
      databaseSpecificationsQueryOptions(
        projectId,
        MYSQL_DATABASE_SPECS_SOURCE,
      ),
    )

    return shellData
  },
  component: MysqlDatabaseSettingsLayout,
})

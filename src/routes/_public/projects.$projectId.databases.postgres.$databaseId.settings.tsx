import { createFileRoute, redirect } from '@tanstack/react-router'
import { PostgresDatabaseSettingsLayout } from '@/components/pages/projects/$projectId/databases/postgres/settings/PostgresDatabaseSettingsLayout'
import { prefetchPostgresShellData } from '@/components/pages/projects/$projectId/databases/postgres/postgres-tab-route-loader'
import { POSTGRES_DATABASE_TAB_LABELS } from '@/lib/postgres-database-routes'
import {
  databaseSpecificationsQueryOptions,
  postgresDatabasePoolerQueryOptions,
} from '@/lib/react-query/hooks'
import { canAccessPostgresDatabaseSettings } from '@/lib/console-rbac-loader'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/postgres/$databaseId/settings',
)({
  head: () => ({
    meta: [
      {
        title: pageTitle(POSTGRES_DATABASE_TAB_LABELS.settings, 'Databases'),
      },
    ],
  }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return { database: null }

    const { projectId, databaseId } = params
    const { queryClient } = context

    const canAccess = await canAccessPostgresDatabaseSettings(
      queryClient,
      projectId,
    )
    if (!canAccess) {
      throw redirect({
        to: '/projects/$projectId/databases/postgres/$databaseId',
        params: { projectId, databaseId },
        replace: true,
      })
    }

    const shellData = await prefetchPostgresShellData(
      queryClient,
      projectId,
      databaseId,
    )

    await Promise.all([
      queryClient.ensureQueryData(databaseSpecificationsQueryOptions(projectId)),
      queryClient.ensureQueryData(
        postgresDatabasePoolerQueryOptions(projectId, databaseId),
      ),
    ])

    return shellData
  },
  component: PostgresDatabaseSettingsLayout,
})

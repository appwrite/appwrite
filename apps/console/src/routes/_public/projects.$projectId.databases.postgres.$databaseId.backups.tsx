import { createFileRoute, redirect } from '@tanstack/react-router'
import { View as PostgresBackupsView } from '@/components/pages/projects/$projectId/databases/postgres/Backups'
import { prefetchPostgresShellData } from '@/components/pages/projects/$projectId/databases/postgres/postgres-tab-route-loader'
import {
  POSTGRES_DATABASE_TAB_LABELS,
  postgresNav,
} from '@/lib/postgres-database-routes'
import { pageTitle } from '@/lib/utils/page-title'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import {
  POSTGRES_BACKUPS_PAGE_SIZE,
  postgresBackupPoliciesQueryOptions,
  postgresBackupsQueryOptions,
} from '@/lib/react-query/hooks'

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/postgres/$databaseId/backups',
)({
  beforeLoad: ({ params }) => {
    if (!getActiveProfileFeatures().databaseBackups) {
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
        title: pageTitle(POSTGRES_DATABASE_TAB_LABELS.backups, 'Databases'),
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
        postgresBackupPoliciesQueryOptions(projectId, databaseId),
      ),
      queryClient.ensureQueryData(
        postgresBackupsQueryOptions(
          projectId,
          databaseId,
          0,
          POSTGRES_BACKUPS_PAGE_SIZE,
        ),
      ),
    ]).catch(() => {
      /* Backups still render with per-query error states */
    })
    return shellData
  },
  component: PostgresBackupsPage,
})

function PostgresBackupsPage() {
  const { projectId, databaseId } = Route.useParams()
  return <PostgresBackupsView projectId={projectId} databaseId={databaseId} />
}

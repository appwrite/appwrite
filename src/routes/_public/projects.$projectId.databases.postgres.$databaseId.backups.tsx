import { createFileRoute, redirect } from '@tanstack/react-router'
import { PostgresShell } from '@/components/pages/projects/$projectId/databases/postgres/PostgresShell'
import { TabPlaceholder } from '@/components/pages/projects/$projectId/databases/postgres/TabPlaceholder'
import { prefetchPostgresShellData } from '@/components/pages/projects/$projectId/databases/postgres/postgres-tab-route-loader'
import { POSTGRES_DATABASE_TAB_LABELS } from '@/lib/postgres-database-routes'
import { pageTitle } from '@/lib/utils/page-title'
import { getActiveProfileFeatures } from '@/lib/console-profiles'

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/postgres/$databaseId/backups',
)({
  beforeLoad: ({ params }) => {
    if (!getActiveProfileFeatures().databaseBackups) {
      throw redirect({
        to: '/projects/$projectId/databases/postgres/$databaseId/tables/$tableId/rows',
        params: {
          projectId: params.projectId,
          databaseId: params.databaseId,
          tableId: '-',
        },
        replace: true,
      })
    }
  },
  head: ({ loaderData }) => ({
    meta: [
      {
        title: pageTitle(
          loaderData?.database?.name
            ? `${POSTGRES_DATABASE_TAB_LABELS.backups} · ${loaderData.database.name}`
            : POSTGRES_DATABASE_TAB_LABELS.backups,
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
  component: PostgresBackupsPage,
})

function PostgresBackupsPage() {
  const { databaseId } = Route.useParams()
  return (
    <PostgresShell databaseId={databaseId} databaseTab="backups">
      <TabPlaceholder tab="backups" />
    </PostgresShell>
  )
}

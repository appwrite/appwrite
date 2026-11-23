import { createFileRoute } from '@tanstack/react-router'
import { PostgresRolesView } from '@/components/pages/projects/$projectId/databases/postgres/PostgresRolesView'
import { prefetchPostgresShellData } from '@/components/pages/projects/$projectId/databases/postgres/postgres-tab-route-loader'
import { POSTGRES_DATABASE_TAB_LABELS } from '@/lib/postgres-database-routes'
import { postgresRolesQueryOptions } from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/postgres/$databaseId/roles',
)({
  head: () => ({
    meta: [
      {
        title: pageTitle(POSTGRES_DATABASE_TAB_LABELS.roles, 'Databases'),
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

    await queryClient.ensureQueryData(
      postgresRolesQueryOptions(projectId, databaseId),
    )

    return shellData
  },
  component: PostgresRolesPage,
})

function PostgresRolesPage() {
  const { databaseId } = Route.useParams()
  return <PostgresRolesView databaseId={databaseId} />
}

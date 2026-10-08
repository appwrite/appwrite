import { createFileRoute } from '@tanstack/react-router'
import { PostgresSchemaEnums } from '@/components/pages/projects/$projectId/databases/postgres/SchemaEnums'
import { prefetchPostgresShellData } from '@/components/pages/projects/$projectId/databases/postgres/postgres-tab-route-loader'
import { POSTGRES_DATABASE_TAB_LABELS } from '@/lib/postgres-database-routes'
import { postgresSidebarSchemasInfiniteQueryOptions } from '@/lib/react-query/hooks/postgres-databases'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/postgres/$databaseId/enums',
)({
  head: () => ({
    meta: [
      {
        title: pageTitle(POSTGRES_DATABASE_TAB_LABELS.enums, 'Databases'),
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

    await queryClient
      .prefetchInfiniteQuery(
        postgresSidebarSchemasInfiniteQueryOptions(projectId, databaseId, ''),
      )
      .catch(() => {
        /* optional prefetch */
      })

    return shellData
  },
  component: PostgresEnumsPage,
})

function PostgresEnumsPage() {
  const { databaseId } = Route.useParams()
  return <PostgresSchemaEnums databaseId={databaseId} />
}

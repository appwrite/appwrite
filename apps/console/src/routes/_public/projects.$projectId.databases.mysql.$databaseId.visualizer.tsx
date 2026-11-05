import { createFileRoute } from '@tanstack/react-router'
import { MysqlSchemaVisualizer } from '@/components/pages/projects/$projectId/databases/mysql/SchemaVisualizer'
import { prefetchMysqlShellData } from '@/components/pages/projects/$projectId/databases/mysql/mysql-tab-route-loader'
import { MYSQL_DATABASE_TAB_LABELS } from '@/lib/mysql-database-routes'
import { mysqlSidebarSchemasInfiniteQueryOptions } from '@/lib/react-query/hooks/mysql-databases'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/mysql/$databaseId/visualizer',
)({
  head: () => ({
    meta: [
      {
        title: pageTitle(MYSQL_DATABASE_TAB_LABELS.visualizer, 'Databases'),
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

    await queryClient
      .prefetchInfiniteQuery(
        mysqlSidebarSchemasInfiniteQueryOptions(projectId, databaseId, ''),
      )
      .catch(() => {
        /* optional prefetch */
      })

    return shellData
  },
  component: MysqlVisualizerPage,
})

function MysqlVisualizerPage() {
  const { databaseId } = Route.useParams()
  return <MysqlSchemaVisualizer databaseId={databaseId} />
}

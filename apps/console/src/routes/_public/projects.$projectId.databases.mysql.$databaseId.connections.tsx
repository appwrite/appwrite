import { createFileRoute } from '@tanstack/react-router'
import { MysqlConnectionDetails } from '@/components/pages/projects/$projectId/databases/mysql/MysqlConnectionDetails'
import { prefetchMysqlShellData } from '@/components/pages/projects/$projectId/databases/mysql/mysql-tab-route-loader'
import { mysqlActiveConnectionsQueryOptions } from '@/lib/react-query/hooks'
import { MYSQL_DATABASE_TAB_LABELS } from '@/lib/mysql-database-routes'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/mysql/$databaseId/connections',
)({
  head: () => ({
    meta: [
      {
        title: pageTitle(MYSQL_DATABASE_TAB_LABELS.connections, 'Databases'),
      },
    ],
  }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return { database: null }
    const { projectId, databaseId } = params
    const database = await prefetchMysqlShellData(
      context.queryClient,
      projectId,
      databaseId,
    )
    await context.queryClient.ensureQueryData(
      mysqlActiveConnectionsQueryOptions(projectId, databaseId),
    )
    return database
  },
  component: MysqlConnectionsPage,
})

function MysqlConnectionsPage() {
  const { projectId, databaseId } = Route.useParams()
  return (
    <MysqlConnectionDetails
      projectId={projectId}
      databaseId={databaseId}
    />
  )
}

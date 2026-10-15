import { createFileRoute } from '@tanstack/react-router'
import { prefetchMysqlShellData } from '@/components/pages/projects/$projectId/databases/mysql/mysql-tab-route-loader'
import { mysqlDatabaseCredentialsQueryOptions } from '@/lib/react-query/hooks'
import { MYSQL_DATABASE_TAB_LABELS } from '@/lib/mysql-database-routes'
import { MysqlSqlWorkbench } from '@/components/pages/projects/$projectId/databases/mysql/Workspace'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/mysql/$databaseId/sql',
)({
  head: () => ({
    meta: [
      {
        title: pageTitle(MYSQL_DATABASE_TAB_LABELS.sql, 'Databases'),
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
    // Credentials come from mysqlql.get() inline connection fields.
    // Active sessions use createExecution + pg_stat_activity (Connections tab).
    await context.queryClient.ensureQueryData(
      mysqlDatabaseCredentialsQueryOptions(projectId, databaseId),
    )
    return database
  },
  component: MysqlSqlEditorPage,
})

function MysqlSqlEditorPage() {
  const { databaseId } = Route.useParams()

  return <MysqlSqlWorkbench databaseId={databaseId} />
}

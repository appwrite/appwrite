import { createFileRoute } from '@tanstack/react-router'
import { MysqlRolesView } from '@/components/pages/projects/$projectId/databases/mysql/MysqlRolesView'
import { prefetchMysqlShellData } from '@/components/pages/projects/$projectId/databases/mysql/mysql-tab-route-loader'
import { MYSQL_DATABASE_TAB_LABELS } from '@/lib/mysql-database-routes'
import { mysqlRolesQueryOptions } from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/mysql/$databaseId/roles',
)({
  head: () => ({
    meta: [
      {
        title: pageTitle(MYSQL_DATABASE_TAB_LABELS.roles, 'Databases'),
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

    await queryClient.ensureQueryData(
      mysqlRolesQueryOptions(projectId, databaseId),
    )

    return shellData
  },
  component: MysqlRolesPage,
})

function MysqlRolesPage() {
  const { databaseId } = Route.useParams()
  return <MysqlRolesView databaseId={databaseId} />
}

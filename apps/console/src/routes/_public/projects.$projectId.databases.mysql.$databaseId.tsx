import { createFileRoute, Outlet, redirect } from '@tanstack/react-router'
import { pageTitle } from '@/lib/utils/page-title'
import { isCloudProfile } from '@/lib/console-profiles'
import {
  mysqlDatabaseQueryOptions,
  projectQueryOptions,
  isMysqlEngine,
} from '@/lib/react-query/hooks'
import { throwRedirectIfDedicatedDatabaseProvisioning } from '@/lib/databases/dedicated-database-provisioning-access'
import { mysqlNav } from '@/lib/mysql-database-routes'
import { DatabaseTypeUnavailable } from '@/components/pages/projects/$projectId/databases/_components/DatabaseTypeUnavailable'
import { MysqlSidebarProvider } from '@/components/pages/projects/$projectId/databases/mysql/_components/MysqlSidebarContext'
import { MysqlShell } from '@/components/pages/projects/$projectId/databases/mysql/MysqlShell'

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/mysql/$databaseId',
)({
  head: () => ({ meta: [{ title: pageTitle('MySQL', 'Databases') }] }),
  beforeLoad: async ({ params, context, location }) => {
    if (typeof window === 'undefined') return

    const { projectId, databaseId } = params
    const { queryClient } = context

    if (!isCloudProfile()) {
      return
    }

    await queryClient.ensureQueryData(projectQueryOptions(projectId))
    const database = await queryClient.ensureQueryData(
      mysqlDatabaseQueryOptions(projectId, databaseId),
    )

    if (!database) {
      throw redirect({
        to: '/projects/$projectId/databases',
        params: { projectId },
        replace: true,
      })
    }

    if (!isMysqlEngine(database.engine)) {
      throw redirect({
        to: '/projects/$projectId/databases',
        params: { projectId },
        replace: true,
      })
    }

    throwRedirectIfDedicatedDatabaseProvisioning(
      database.status,
      location.pathname,
      mysqlNav({ projectId, databaseId }).sql(),
    )
  },
  component: MysqlDatabaseLayout,
})

function MysqlDatabaseLayout() {
  const { projectId, databaseId } = Route.useParams()

  if (!isCloudProfile()) {
    return <DatabaseTypeUnavailable projectId={projectId} />
  }

  return (
    <MysqlSidebarProvider key={databaseId} databaseId={databaseId}>
      <MysqlShell>
        <Outlet />
      </MysqlShell>
    </MysqlSidebarProvider>
  )
}

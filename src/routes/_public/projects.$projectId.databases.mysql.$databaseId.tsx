import { createFileRoute, Outlet, redirect } from '@tanstack/react-router'
import { pageTitle } from '@/lib/utils/page-title'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import {
  mysqlDatabaseQueryOptions,
  projectQueryOptions,
  isMysqlEngine,
} from '@/lib/react-query/hooks'
import { ensureConsoleSqlApiStatements } from '@/lib/databases/sql-api-statements'
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

    if (!getActiveProfileFeatures().nativeDbsMySQL) {
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

    try {
      const { database: updated, updated: didUpdate } =
        await ensureConsoleSqlApiStatements(
          projectId,
          databaseId,
          'mysql',
          database,
        )
      if (didUpdate && updated) {
        queryClient.setQueryData(
          mysqlDatabaseQueryOptions(projectId, databaseId).queryKey,
          updated,
        )
      }
    } catch {
      /* First DDL statement retries if the allow-list PATCH fails here */
    }
  },
  component: MysqlDatabaseLayout,
})

function MysqlDatabaseLayout() {
  const { projectId, databaseId } = Route.useParams()

  if (!getActiveProfileFeatures().nativeDbsMySQL) {
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

import { createFileRoute, Outlet, redirect } from '@tanstack/react-router'
import { pageTitle } from '@/lib/utils/page-title'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import {
  postgresDatabaseQueryOptions,
  projectQueryOptions,
  isPostgresEngine,
} from '@/lib/react-query/hooks'
import { ensureConsoleSqlApiStatements } from '@/lib/databases/sql-api-statements'
import { throwRedirectIfDedicatedDatabaseProvisioning } from '@/lib/databases/dedicated-database-provisioning-access'
import { postgresNav } from '@/lib/postgres-database-routes'
import { DatabaseTypeUnavailable } from '@/components/pages/projects/$projectId/databases/_components/DatabaseTypeUnavailable'
import { PostgresSidebarProvider } from '@/components/pages/projects/$projectId/databases/postgres/_components/PostgresSidebarContext'
import { PostgresShell } from '@/components/pages/projects/$projectId/databases/postgres/PostgresShell'

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/postgres/$databaseId',
)({
  head: () => ({ meta: [{ title: pageTitle('PostgreSQL', 'Databases') }] }),
  beforeLoad: async ({ params, context, location }) => {
    if (typeof window === 'undefined') return

    const { projectId, databaseId } = params
    const { queryClient } = context

    if (!getActiveProfileFeatures().nativeDbsPostgres) {
      return
    }

    await queryClient.ensureQueryData(projectQueryOptions(projectId))
    const database = await queryClient.ensureQueryData(
      postgresDatabaseQueryOptions(projectId, databaseId),
    )

    if (!database) {
      throw redirect({
        to: '/projects/$projectId/databases',
        params: { projectId },
        replace: true,
      })
    }

    if (!isPostgresEngine(database.engine)) {
      throw redirect({
        to: '/projects/$projectId/databases',
        params: { projectId },
        replace: true,
      })
    }

    throwRedirectIfDedicatedDatabaseProvisioning(
      database.status,
      location.pathname,
      postgresNav({ projectId, databaseId }).sql(),
    )

    try {
      const { database: updated, updated: didUpdate } =
        await ensureConsoleSqlApiStatements(
          projectId,
          databaseId,
          'postgresql',
          database,
        )
      if (didUpdate && updated) {
        queryClient.setQueryData(
          postgresDatabaseQueryOptions(projectId, databaseId).queryKey,
          updated,
        )
      }
    } catch {
      /* First DDL statement retries if the allow-list PATCH fails here */
    }
  },
  component: PostgresDatabaseLayout,
})

function PostgresDatabaseLayout() {
  const { projectId, databaseId } = Route.useParams()

  if (!getActiveProfileFeatures().nativeDbsPostgres) {
    return <DatabaseTypeUnavailable projectId={projectId} />
  }

  return (
    <PostgresSidebarProvider key={databaseId} databaseId={databaseId}>
      <PostgresShell>
        <Outlet />
      </PostgresShell>
    </PostgresSidebarProvider>
  )
}

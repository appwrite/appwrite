import { createFileRoute, Outlet, redirect } from '@tanstack/react-router'
import { pageTitle } from '@/lib/utils/page-title'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import {
  postgresDatabaseQueryOptions,
  projectQueryOptions,
  isPostgresEngine,
} from '@/lib/react-query/hooks'
import { DatabaseTypeUnavailable } from '@/components/pages/projects/$projectId/databases/_components/DatabaseTypeUnavailable'
import { PostgresSidebarProvider } from '@/components/pages/projects/$projectId/databases/postgres/_components/PostgresSidebarContext'
import { PostgresShell } from '@/components/pages/projects/$projectId/databases/postgres/PostgresShell'

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/postgres/$databaseId',
)({
  head: () => ({ meta: [{ title: pageTitle('PostgreSQL', 'Databases') }] }),
  beforeLoad: async ({ params, context }) => {
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

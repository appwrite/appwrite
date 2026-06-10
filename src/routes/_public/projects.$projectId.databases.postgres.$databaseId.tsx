import { createFileRoute, Outlet, redirect } from '@tanstack/react-router'
import { pageTitle } from '@/lib/utils/page-title'
import {
  postgresDatabaseQueryOptions,
  projectQueryOptions,
  isPostgresEngine,
} from '@/lib/react-query/hooks'

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/postgres/$databaseId',
)({
  head: () => ({ meta: [{ title: pageTitle('PostgreSQL', 'Databases') }] }),
  beforeLoad: async ({ params, context }) => {
    if (typeof window === 'undefined') return

    const { projectId, databaseId } = params
    const { queryClient } = context

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
  return <Outlet />
}

import { createFileRoute, Outlet, redirect } from '@tanstack/react-router'
import { pageTitle } from '@/lib/utils/page-title'
import {
  isDatabaseRouteKind,
  databaseRouteKindFromApiType,
} from '@/lib/database-routes'
import type { DatabaseType } from '@appwrite.io/console'
import {
  databaseQueryOptions,
  projectQueryOptions,
} from '@/lib/react-query/hooks'

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/$dbKind/$databaseId',
)({
  head: () => ({ meta: [{ title: pageTitle('Databases') }] }),
  beforeLoad: async ({ params, context }) => {
    if (typeof window === 'undefined') return
    const { projectId, dbKind, databaseId } = params
    if (!isDatabaseRouteKind(dbKind)) {
      throw redirect({
        to: '/projects/$projectId/databases',
        params: { projectId },
        replace: true,
      })
    }
    const { queryClient } = context
    await queryClient.ensureQueryData(projectQueryOptions(projectId))
    const db = await queryClient.ensureQueryData(
      databaseQueryOptions(projectId, databaseId),
    )
    const expected = databaseRouteKindFromApiType(
      (db as { databaseType?: DatabaseType } | null)?.databaseType,
    )
    if (dbKind !== expected) {
      throw redirect({
        to: '/projects/$projectId/databases/$dbKind/$databaseId',
        params: { projectId, dbKind: expected, databaseId },
        replace: true,
      })
    }
  },
  component: DatabaseKindLayout,
})

function DatabaseKindLayout() {
  return <Outlet />
}

import { createFileRoute, Outlet, redirect } from '@tanstack/react-router'
import { pageTitle } from '@/lib/utils/page-title'
import { isDatabaseRouteKind } from '@/lib/database-routes'
import { throwRedirectPostgresDbKind } from '@/lib/database-route-redirects'
import {
  productRouteKindQueryOptions,
  projectQueryOptions,
  resolveProductRouteKindForDatabase,
  seedDatabaseProductRouteKind,
} from '@/lib/react-query/hooks'

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/$dbKind/$databaseId',
)({
  head: () => ({ meta: [{ title: pageTitle('Databases') }] }),
  beforeLoad: async ({ params, context }) => {
    if (typeof window === 'undefined') return
    const { projectId, dbKind, databaseId } = params
    throwRedirectPostgresDbKind(dbKind, { projectId, databaseId })
    if (!isDatabaseRouteKind(dbKind)) {
      throw redirect({
        to: '/projects/$projectId/databases',
        params: { projectId },
        replace: true,
      })
    }

    // Pin product API routing before any get/probe so VectorsDB never hits DocumentsDB.
    seedDatabaseProductRouteKind(projectId, databaseId, dbKind)

    const { queryClient } = context
    await queryClient.ensureQueryData(projectQueryOptions(projectId))

    const routeKindKey = productRouteKindQueryOptions(projectId, databaseId)
      .queryKey
    let expected = queryClient.getQueryData<
      Awaited<ReturnType<typeof resolveProductRouteKindForDatabase>>
    >(routeKindKey)

    if (expected !== dbKind) {
      const resolved = await resolveProductRouteKindForDatabase(
        projectId,
        databaseId,
        dbKind,
      )
      if (resolved != null) {
        expected = resolved
        queryClient.setQueryData(routeKindKey, resolved)
      }
    }

    if (expected == null) {
      expected = await resolveProductRouteKindForDatabase(
        projectId,
        databaseId,
        dbKind,
      )
      if (expected != null) {
        queryClient.setQueryData(routeKindKey, expected)
      }
    }
    if (!expected) {
      throw redirect({
        to: '/projects/$projectId/databases',
        params: { projectId },
        replace: true,
      })
    }
    if (dbKind !== expected) {
      throw redirect({
        to: '/projects/$projectId/databases/$dbKind/$databaseId',
        params: { projectId, dbKind: expected, databaseId },
        replace: true,
      })
    }

    // Re-seed after resolution in case cache was cleared during validation.
    seedDatabaseProductRouteKind(projectId, databaseId, dbKind)
  },
  component: DatabaseKindLayout,
})

function DatabaseKindLayout() {
  return <Outlet />
}

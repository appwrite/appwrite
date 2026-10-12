import { createFileRoute, Outlet, redirect } from '@tanstack/react-router'
import {
  projectQueryOptions,
  databaseQueryOptions,
  tablesQueryOptions,
} from '@/lib/react-query/hooks'
import { DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import { pageTitle } from '@/lib/utils/page-title'
import {
  type DatabaseRouteKind,
  usesCollectionsPath,
} from '@/lib/database-routes'

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/$dbKind/$databaseId/collections',
)({
  head: () => ({ meta: [{ title: pageTitle('Databases', 'Collections') }] }),
  loader: async ({ params, context }) => {
    // Only run on client side (SDK requires browser environment)
    if (typeof window === 'undefined') {
      return
    }

    const { projectId, dbKind, databaseId } = params
    const { queryClient } = context

    if (!projectId || !databaseId) return

    if (!usesCollectionsPath(dbKind as DatabaseRouteKind)) {
      throw redirect({
        to: '/projects/$projectId/databases/$dbKind/$databaseId/tables/$tableId/rows',
        params: { projectId, dbKind, databaseId, tableId: '-' },
        replace: true,
      })
    }

    // Fetch project first so setProjectRegion runs and project-scoped calls use the correct regional endpoint
    await queryClient.ensureQueryData(projectQueryOptions(projectId))

    // Fetch critical data before rendering to prevent layout shifts
    await Promise.all([
      // Fetch database - blocks navigation until ready
      queryClient.ensureQueryData(databaseQueryOptions(projectId, databaseId, dbKind as DatabaseRouteKind)),
      // Fetch first page of tables - blocks navigation until ready
      queryClient.ensureQueryData(
        tablesQueryOptions(
          projectId,
          databaseId,
          dbKind as DatabaseRouteKind,
          0,
          DEFAULT_PAGE_SIZE,
        ),
      ),
    ])
  },
  component: CollectionsLayout,
})

function CollectionsLayout() {
  return <Outlet />
}

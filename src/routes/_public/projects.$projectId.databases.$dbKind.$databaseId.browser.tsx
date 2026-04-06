import { createFileRoute, redirect } from '@tanstack/react-router'
import {
  projectQueryOptions,
  databaseQueryOptions,
  tablesQueryOptions,
} from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'
import { dbNavLink, type DatabaseRouteKind } from '@/lib/database-routes'

const TABLES_PER_PAGE = 100

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/$dbKind/$databaseId/browser',
)({
  head: () => ({ meta: [{ title: pageTitle('Database', 'Databases') }] }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return

    const { projectId, dbKind, databaseId } = params
    const { queryClient } = context

    if (!projectId || !databaseId) return

    await queryClient.ensureQueryData(projectQueryOptions(projectId))
    await queryClient.ensureQueryData(
      databaseQueryOptions(projectId, databaseId),
    )
    const tablesData = await queryClient.ensureQueryData(
      tablesQueryOptions(projectId, databaseId, 0, TABLES_PER_PAGE, undefined),
    )
    const sorted = [...(tablesData.tables || [])].sort((a, b) =>
      (a.name?.toLowerCase() || '').localeCompare(b.name?.toLowerCase() || ''),
    )
    const resourceId = sorted[0]?.$id ?? '-'
    const nav = dbNavLink(dbKind as DatabaseRouteKind)

    throw redirect({
      ...nav.dataGrid({
        projectId,
        dbKind: dbKind as DatabaseRouteKind,
        databaseId,
        resourceId,
      }),
      replace: true,
    })
  },
})

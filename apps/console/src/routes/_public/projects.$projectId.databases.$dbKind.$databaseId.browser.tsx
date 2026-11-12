import { createFileRoute, redirect } from '@tanstack/react-router'
import {
  projectQueryOptions,
  databaseQueryOptions,
  tablesQueryOptions,
} from '@/lib/react-query/hooks'
import { ROWS_DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import { pageTitle } from '@/lib/utils/page-title'
import { dbNavLink, type DatabaseRouteKind } from '@/lib/database-routes'

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
      databaseQueryOptions(projectId, databaseId, dbKind as DatabaseRouteKind),
    )
    const tablesData = await queryClient.ensureQueryData(
      tablesQueryOptions(
        projectId,
        databaseId,
        dbKind as DatabaseRouteKind,
        0,
        ROWS_DEFAULT_PAGE_SIZE,
        undefined,
        'asc',
        '$createdAt',
      ),
    )
    const resourceId =
      (tablesData.tables?.[0] as { $id?: string } | undefined)?.$id ?? '-'
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

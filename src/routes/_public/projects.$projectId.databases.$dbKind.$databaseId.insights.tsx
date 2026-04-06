import { createFileRoute, redirect } from '@tanstack/react-router'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import {
  projectQueryOptions,
  databaseQueryOptions,
  tablesQueryOptions,
} from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'

const TABLES_PER_PAGE = 100

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/$dbKind/$databaseId/insights',
)({
  beforeLoad: ({ params }) => {
    if (!getActiveProfileFeatures().databaseInsights) {
      throw redirect({
        to: '/projects/$projectId/databases/$dbKind/$databaseId/',
        params: {
          projectId: params.projectId,
          dbKind: params.dbKind,
          databaseId: params.databaseId,
        },
        replace: true,
      })
    }
  },
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
    const tableId = sorted[0]?.$id ?? '-'

    throw redirect({
      to: '/projects/$projectId/databases/$dbKind/$databaseId/tables/$tableId/insights',
      params: { projectId, dbKind, databaseId, tableId },
      replace: true,
    })
  },
})

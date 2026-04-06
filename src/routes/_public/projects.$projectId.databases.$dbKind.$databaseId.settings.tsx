import { createFileRoute, redirect } from '@tanstack/react-router'
import {
  projectQueryOptions,
  databaseQueryOptions,
  tablesQueryOptions,
} from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'
import { canAccessDatabaseSecuritySettings } from '@/lib/console-rbac-loader'

const TABLES_PER_PAGE = 100

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/$dbKind/$databaseId/settings',
)({
  head: () => ({ meta: [{ title: pageTitle('Databases', 'Settings') }] }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return

    const { projectId, dbKind, databaseId } = params
    const { queryClient } = context

    if (!projectId || !databaseId) return

    const canAccess = await canAccessDatabaseSecuritySettings(
      queryClient,
      projectId,
    )
    if (!canAccess) {
      throw redirect({
        to: '/projects/$projectId/databases/$dbKind/$databaseId/',
        params: { projectId, dbKind, databaseId },
        replace: true,
      })
    }

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
      to: '/projects/$projectId/databases/$dbKind/$databaseId/tables/$tableId/db-settings',
      params: { projectId, dbKind, databaseId, tableId },
      replace: true,
    })
  },
})

import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/databases/settings/Specification'
import {
  databaseQueryOptions,
  databaseSpecificationsQueryOptions,
  dedicatedDatabaseSourceFromRouteKind,
} from '@/lib/react-query/hooks'
import {
  isDatabaseRouteKind,
  type DatabaseRouteKind,
} from '@/lib/database-routes'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/$dbKind/$databaseId/settings/specification',
)({
  head: () => ({ meta: [{ title: pageTitle('Specification', 'Databases') }] }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return
    const { projectId, databaseId, dbKind: rawDbKind } = params
    const { queryClient } = context
    if (!projectId || !databaseId) return

    const dbKind = (
      isDatabaseRouteKind(rawDbKind ?? '') ? rawDbKind : 'tablesdb'
    ) as DatabaseRouteKind

    await Promise.all([
      queryClient.ensureQueryData(
        databaseQueryOptions(projectId, databaseId, dbKind),
      ),
      queryClient.ensureQueryData(
        databaseSpecificationsQueryOptions(
          projectId,
          dedicatedDatabaseSourceFromRouteKind(dbKind),
        ),
      ),
    ])
  },
  component: View,
})

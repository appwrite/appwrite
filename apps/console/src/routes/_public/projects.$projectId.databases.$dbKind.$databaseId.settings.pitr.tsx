import { createFileRoute, redirect } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/databases/settings/Pitr'
import { hasDedicatedSpecification } from '@/lib/databases/database-compute'
import {
  databaseQueryOptions,
  databaseSpecificationsQueryOptions,
  dedicatedDatabaseSourceFromRouteKind,
  projectQueryOptions,
} from '@/lib/react-query/hooks'
import {
  isCloudDedicatedDatabasesEnabled,
  isDatabaseRouteKind,
  type DatabaseRouteKind,
} from '@/lib/database-routes'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/$dbKind/$databaseId/settings/pitr',
)({
  head: () => ({
    meta: [{ title: pageTitle('Point-in-time recovery', 'Databases') }],
  }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return

    const { projectId, databaseId, dbKind: rawDbKind } = params
    const { queryClient } = context
    if (!projectId || !databaseId) return

    const dbKind = (
      isDatabaseRouteKind(rawDbKind ?? '') ? rawDbKind : 'tablesdb'
    ) as DatabaseRouteKind

    const [, database] = await Promise.all([
      queryClient.ensureQueryData(projectQueryOptions(projectId)),
      queryClient.ensureQueryData(
        databaseQueryOptions(projectId, databaseId, dbKind),
      ),
    ])

    if (
      !isCloudDedicatedDatabasesEnabled() ||
      !hasDedicatedSpecification(
        (database as { specification?: string | null } | null)?.specification,
      )
    ) {
      throw redirect({
        to: '/projects/$projectId/databases/$dbKind/$databaseId/settings',
        params: { projectId, dbKind, databaseId },
        replace: true,
      })
    }

    await queryClient.ensureQueryData(
      databaseSpecificationsQueryOptions(
        projectId,
        dedicatedDatabaseSourceFromRouteKind(dbKind),
      ),
    )
  },
  component: View,
})

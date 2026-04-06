import { createFileRoute, redirect } from '@tanstack/react-router'
import {
  databaseRouteKindFromApiType,
  DATABASE_HOME_TO,
} from '@/lib/database-routes'
import type { DatabaseType } from '@appwrite.io/console'
import {
  databaseQueryOptions,
  projectQueryOptions,
} from '@/lib/react-query/hooks'

/**
 * Legacy path without product segment: /projects/:projectId/databases/:databaseId
 * Redirects to /projects/:projectId/databases/:dbKind/:databaseId
 */
export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/$databaseId',
)({
  beforeLoad: async ({ params, context }) => {
    if (typeof window === 'undefined') return
    const { projectId, databaseId } = params
    const { queryClient } = context
    await queryClient.ensureQueryData(projectQueryOptions(projectId))
    const db = await queryClient.ensureQueryData(
      databaseQueryOptions(projectId, databaseId),
    )
    const dbKind = databaseRouteKindFromApiType(
      (db as { databaseType?: DatabaseType } | null)?.databaseType,
    )
    throw redirect({
      to: DATABASE_HOME_TO,
      params: { projectId, dbKind, databaseId },
      replace: true,
    })
  },
})

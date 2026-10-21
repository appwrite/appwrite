import { createFileRoute, redirect } from '@tanstack/react-router'
import { DATABASE_HOME_TO } from '@/lib/database-routes'
import {
  productRouteKindQueryOptions,
  projectQueryOptions,
} from '@/lib/react-query/hooks'

/**
 * Legacy path without product segment: /projects/:projectId/databases/:databaseId
 * Redirects to /projects/:projectId/databases/:dbKind/:databaseId when the
 * product kind is already known from cache/list seeding. Never probes other
 * product APIs by ID.
 */
export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/$databaseId',
)({
  beforeLoad: async ({ params, context }) => {
    if (typeof window === 'undefined') return
    const { projectId, databaseId } = params
    const { queryClient } = context
    await queryClient.ensureQueryData(projectQueryOptions(projectId))
    const dbKind = await queryClient.ensureQueryData(
      productRouteKindQueryOptions(projectId, databaseId),
    )
    if (!dbKind) {
      throw redirect({
        to: '/projects/$projectId/databases',
        params: { projectId },
        replace: true,
      })
    }
    throw redirect({
      to: DATABASE_HOME_TO,
      params: { projectId, dbKind, databaseId },
      replace: true,
    })
  },
})

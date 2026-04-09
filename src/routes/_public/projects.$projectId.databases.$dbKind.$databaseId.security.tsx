import { createFileRoute, redirect } from '@tanstack/react-router'
import { projectQueryOptions, databaseQueryOptions } from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'
import { canAccessDatabaseSecuritySettings } from '@/lib/console-rbac-loader'

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/$dbKind/$databaseId/security',
)({
  head: () => ({ meta: [{ title: pageTitle('Database', 'Databases') }] }),
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
    throw redirect({
      to: '/projects/$projectId/databases/$dbKind/$databaseId/db-security',
      params: { projectId, dbKind, databaseId },
      replace: true,
    })
  },
})

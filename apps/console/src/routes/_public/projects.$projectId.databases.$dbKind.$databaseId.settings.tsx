import { type DatabaseRouteKind } from '@/lib/database-routes'
import { createFileRoute, redirect } from '@tanstack/react-router'
import { Workspace } from '@/components/pages/projects/$projectId/databases/View'
import { DatabaseSettingsLayout } from '@/components/pages/projects/$projectId/databases/settings/DatabaseSettingsLayout'
import {
  projectQueryOptions,
  databaseQueryOptions,
} from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'
import { canAccessDatabaseSecuritySettings } from '@/lib/console-rbac-loader'

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/$dbKind/$databaseId/settings',
)({
  head: ({ loaderData }) => ({
    meta: [
      {
        title: pageTitle(loaderData?.database?.name ?? 'Database', 'Databases'),
      },
    ],
  }),
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
      databaseQueryOptions(projectId, databaseId, dbKind as DatabaseRouteKind),
    )

    const database = queryClient.getQueryData<{ name?: string }>(
      databaseQueryOptions(projectId, databaseId, dbKind as DatabaseRouteKind)
        .queryKey,
    )
    return { database }
  },
  component: DatabaseSettingsPage,
})

function DatabaseSettingsPage() {
  const { databaseId } = Route.useParams()
  return (
    <Workspace
      databaseId={databaseId}
      tableId="-"
      activeTab="rows"
      databaseTab="settings"
    >
      <DatabaseSettingsLayout />
    </Workspace>
  )
}

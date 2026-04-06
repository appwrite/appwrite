import { createFileRoute, redirect } from '@tanstack/react-router'
import { TableView } from '@/components/pages/projects/$projectId/databases/View'
import {
  projectQueryOptions,
  databaseQueryOptions,
  tablesQueryOptions,
} from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'
import { canAccessDatabaseSecuritySettings } from '@/lib/console-rbac-loader'
import { throwRedirectTablesDbFromCollectionsChild } from '@/lib/database-route-redirects'

const TABLES_PER_PAGE = 100

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/$dbKind/$databaseId/collections/$collectionId/db-settings',
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

    const { projectId, dbKind, databaseId, collectionId } = params
    const { queryClient } = context

    if (!projectId || !databaseId) return

    throwRedirectTablesDbFromCollectionsChild(dbKind, 'db-settings', {
      projectId,
      dbKind,
      databaseId,
      collectionId,
    })

    const canAccess = await canAccessDatabaseSecuritySettings(
      queryClient,
      projectId,
    )
    if (!canAccess) {
      throw redirect({
        to: '/projects/$projectId/databases/$dbKind/$databaseId/collections/$collectionId/documents',
        params: { projectId, dbKind, databaseId, collectionId },
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

    if (collectionId !== '-') {
      const tableExists = (tablesData.tables || []).some(
        (t: { $id: string }) => t.$id === collectionId,
      )
      if (!tableExists) {
        const first = [...(tablesData.tables || [])].sort((a, b) =>
          (a.name?.toLowerCase() || '').localeCompare(
            b.name?.toLowerCase() || '',
          ),
        )[0]
        throw redirect({
          to: '/projects/$projectId/databases/$dbKind/$databaseId/collections/$collectionId/db-settings',
          params: {
            projectId,
            databaseId,
            collectionId: first?.$id ?? '-',
          },
          replace: true,
        })
      }
    }

    const database = queryClient.getQueryData<{ name?: string }>(
      databaseQueryOptions(projectId, databaseId).queryKey,
    )
    return { database }
  },
  component: DbSettingsPage,
})

function DbSettingsPage() {
  const { databaseId, collectionId } = Route.useParams()
  return (
    <TableView
      databaseId={databaseId}
      collectionId={collectionId}
      activeTab="rows"
      databaseTab="db-settings"
    />
  )
}

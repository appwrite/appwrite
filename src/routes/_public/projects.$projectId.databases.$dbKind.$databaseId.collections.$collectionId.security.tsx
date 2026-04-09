import { createFileRoute, redirect } from '@tanstack/react-router'
import { TableView } from '@/components/pages/projects/$projectId/databases/View'
import {
  projectQueryOptions,
  tablesQueryOptions,
  databaseQueryOptions,
  tableColumnsQueryOptions,
  tableIndexesQueryOptions,
  tableQueryOptions,
} from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'
import { canAccessTableSecuritySettings } from '@/lib/console-rbac-loader'
import { throwRedirectTablesDbFromCollectionsChild } from '@/lib/database-route-redirects'

const TABLES_PER_PAGE = 100

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/$dbKind/$databaseId/collections/$collectionId/security',
)({
  head: ({ loaderData }) => ({
    meta: [
      {
        title: pageTitle(loaderData?.database?.name ?? 'Database', 'Databases'),
      },
    ],
  }),
  loader: async ({ params, context }) => {
    // Only run on client side (SDK requires browser environment)
    if (typeof window === 'undefined') {
      return
    }

    const { projectId, dbKind, databaseId, collectionId } = params
    const { queryClient } = context

    if (!projectId || !databaseId || !collectionId) {
      return
    }

    throwRedirectTablesDbFromCollectionsChild(dbKind, 'security', {
      projectId,
      dbKind,
      databaseId,
      collectionId,
    })

    const canAccess = await canAccessTableSecuritySettings(
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

    // Fetch project first so setProjectRegion runs and project-scoped calls use the correct regional endpoint
    await queryClient.ensureQueryData(projectQueryOptions(projectId))

    // Fetch critical data before rendering to prevent layout shifts
    await Promise.all([
      // Fetch tables list - blocks navigation until ready
      queryClient.ensureQueryData(
        tablesQueryOptions(projectId, databaseId, 0, TABLES_PER_PAGE),
      ),
      // Fetch database - blocks navigation until ready
      queryClient.ensureQueryData(databaseQueryOptions(projectId, databaseId)),
      // Fetch columns - blocks navigation until ready
      queryClient.ensureQueryData(
        tableColumnsQueryOptions(projectId, databaseId, collectionId),
      ),
      // Prefetch indexes (optional data)
      queryClient.prefetchQuery(
        tableIndexesQueryOptions(projectId, databaseId, collectionId),
      ),
      // Fetch table - blocks navigation until ready
      queryClient.ensureQueryData(
        tableQueryOptions(projectId, databaseId, collectionId),
      ),
    ])
    const database = queryClient.getQueryData<{ name?: string }>(
      databaseQueryOptions(projectId, databaseId).queryKey,
    )
    return { database }
  },
  component: SecurityPage,
})

function SecurityPage() {
  const { databaseId, collectionId } = Route.useParams()

  return (
    <TableView databaseId={databaseId} tableId={collectionId} activeTab="security" />
  )
}

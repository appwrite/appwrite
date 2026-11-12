import { type DatabaseRouteKind } from '@/lib/database-routes'
import { createFileRoute, redirect } from '@tanstack/react-router'
import { Workspace } from '@/components/pages/projects/$projectId/databases/View'
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
import { throwRedirectCollectionsDbFromTablesChild } from '@/lib/database-route-redirects'

const TABLES_PER_PAGE = 100

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/$dbKind/$databaseId/tables/$tableId/security',
)({
  head: ({ loaderData }) => ({
    meta: [
      {
        title: pageTitle(
          (
            loaderData as
              | {
                  database?: { name?: string }
                  table?: { name?: string }
                }
              | undefined
          )?.table?.name ??
            (loaderData as { database?: { name?: string } } | undefined)
              ?.database?.name ??
            'Database',
          'Databases',
        ),
      },
    ],
  }),
  loader: async ({ params, context }) => {
    // Only run on client side (SDK requires browser environment)
    if (typeof window === 'undefined') {
      return
    }

    const { projectId, dbKind, databaseId, tableId } = params
    const { queryClient } = context

    if (!projectId || !databaseId || !tableId) {
      return
    }

    throwRedirectCollectionsDbFromTablesChild(dbKind, 'security', {
      projectId,
      dbKind,
      databaseId,
      tableId,
    })

    const canAccess = await canAccessTableSecuritySettings(
      queryClient,
      projectId,
    )
    if (!canAccess) {
      throw redirect({
        to: '/projects/$projectId/databases/$dbKind/$databaseId/tables/$tableId/rows',
        params: { projectId, dbKind, databaseId, tableId },
        replace: true,
      })
    }

    // Fetch project first so setProjectRegion runs and project-scoped calls use the correct regional endpoint
    await queryClient.ensureQueryData(projectQueryOptions(projectId))

    // Fetch critical data before rendering to prevent layout shifts
    await Promise.all([
      // Fetch tables list - blocks navigation until ready
      queryClient.ensureQueryData(
        tablesQueryOptions(
          projectId,
          databaseId,
          dbKind as DatabaseRouteKind,
          0,
          TABLES_PER_PAGE,
        ),
      ),
      // Fetch database - blocks navigation until ready
      queryClient.ensureQueryData(databaseQueryOptions(projectId, databaseId, dbKind as DatabaseRouteKind)),
      // Fetch columns - blocks navigation until ready
      queryClient.ensureQueryData(
        tableColumnsQueryOptions(
          projectId,
          databaseId,
          dbKind as DatabaseRouteKind,
          tableId,
        ),
      ),
      // Prefetch indexes (optional data)
      queryClient.prefetchQuery(
        tableIndexesQueryOptions(
          projectId,
          databaseId,
          dbKind as DatabaseRouteKind,
          tableId,
        ),
      ),
      // Fetch table - blocks navigation until ready
      queryClient.ensureQueryData(
        tableQueryOptions(
          projectId,
          databaseId,
          dbKind as DatabaseRouteKind,
          tableId,
        ),
      ),
    ])
    const database = queryClient.getQueryData<{ name?: string }>(
      databaseQueryOptions(projectId, databaseId, dbKind as DatabaseRouteKind).queryKey,
    )
    const table = queryClient.getQueryData<{ name?: string }>(
      tableQueryOptions(
        projectId,
        databaseId,
        dbKind as DatabaseRouteKind,
        tableId,
      ).queryKey,
    )
    return { database, table }
  },
  component: SecurityPage,
})

function SecurityPage() {
  const { databaseId, tableId } = Route.useParams()

  return (
    <Workspace databaseId={databaseId} tableId={tableId} activeTab="security" />
  )
}

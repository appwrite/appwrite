import { createFileRoute, redirect } from '@tanstack/react-router'
import { TableView } from '@/components/pages/projects/$projectId/databases/View'
import {
  projectQueryOptions,
  databaseQueryOptions,
  tablesQueryOptions,
} from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'
import { canAccessDatabaseSecuritySettings } from '@/lib/console-rbac-loader'
import { throwRedirectCollectionsDbFromTablesChild } from '@/lib/database-route-redirects'

const TABLES_PER_PAGE = 100

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/$dbKind/$databaseId/tables/$tableId/db-security',
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

    const { projectId, dbKind, databaseId, tableId } = params
    const { queryClient } = context

    if (!projectId || !databaseId) return

    throwRedirectCollectionsDbFromTablesChild(dbKind, 'db-security', {
      projectId,
      dbKind,
      databaseId,
      tableId,
    })

    const canAccess = await canAccessDatabaseSecuritySettings(
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

    await queryClient.ensureQueryData(projectQueryOptions(projectId))
    await queryClient.ensureQueryData(
      databaseQueryOptions(projectId, databaseId),
    )
    const tablesData = await queryClient.ensureQueryData(
      tablesQueryOptions(projectId, databaseId, 0, TABLES_PER_PAGE, undefined),
    )

    if (tableId !== '-') {
      const tableExists = (tablesData.tables || []).some(
        (t: { $id: string }) => t.$id === tableId,
      )
      if (!tableExists) {
        const first = [...(tablesData.tables || [])].sort((a, b) =>
          (a.name?.toLowerCase() || '').localeCompare(
            b.name?.toLowerCase() || '',
          ),
        )[0]
        throw redirect({
          to: '/projects/$projectId/databases/$dbKind/$databaseId/tables/$tableId/db-security',
          params: {
            projectId,
            databaseId,
            tableId: first?.$id ?? '-',
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
  component: DbSecurityPage,
})

function DbSecurityPage() {
  const { databaseId, tableId } = Route.useParams()
  return (
    <TableView
      databaseId={databaseId}
      tableId={tableId}
      activeTab="rows"
      databaseTab="db-security"
    />
  )
}

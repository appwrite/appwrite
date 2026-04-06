import { createFileRoute, redirect } from '@tanstack/react-router'
import { TableView } from '@/components/pages/projects/$projectId/databases/View'
import {
  projectQueryOptions,
  databaseQueryOptions,
  tablesQueryOptions,
  databaseCsvMigrationsQueryOptions,
} from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'
import { throwRedirectCollectionsDbFromTablesChild } from '@/lib/database-route-redirects'

const TABLES_PER_PAGE = 500

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/$dbKind/$databaseId/tables/$tableId/export-import',
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

    throwRedirectCollectionsDbFromTablesChild(dbKind, 'export-import', {
      projectId,
      dbKind,
      databaseId,
      tableId,
    })

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
          to: '/projects/$projectId/databases/$dbKind/$databaseId/tables/$tableId/export-import',
          params: {
            projectId,
            databaseId,
            tableId: first?.$id ?? '-',
          },
          replace: true,
        })
      }
    }

    const tableIds = (tablesData.tables || []).map(
      (t: { $id: string }) => t.$id,
    )
    if (tableIds.length > 0) {
      await queryClient
        .ensureQueryData(
          databaseCsvMigrationsQueryOptions(projectId, databaseId, tableIds),
        )
        .catch(() => {})
    }

    const database = queryClient.getQueryData<{ name?: string }>(
      databaseQueryOptions(projectId, databaseId).queryKey,
    )
    return { database }
  },
  component: ExportImportPage,
})

function ExportImportPage() {
  const { databaseId, tableId } = Route.useParams()
  return (
    <TableView
      databaseId={databaseId}
      tableId={tableId}
      activeTab="rows"
      databaseTab="export-import"
    />
  )
}

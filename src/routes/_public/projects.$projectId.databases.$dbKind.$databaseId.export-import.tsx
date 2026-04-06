import { createFileRoute } from '@tanstack/react-router'
import { TableView } from '@/components/pages/projects/$projectId/databases/View'
import {
  projectQueryOptions,
  databaseQueryOptions,
  tablesQueryOptions,
  databaseCsvMigrationsQueryOptions,
} from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'

const TABLES_PER_PAGE = 500

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/$dbKind/$databaseId/export-import',
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

    const { projectId, databaseId } = params
    const { queryClient } = context

    if (!projectId || !databaseId) return

    await queryClient.ensureQueryData(projectQueryOptions(projectId))
    await queryClient.ensureQueryData(
      databaseQueryOptions(projectId, databaseId),
    )
    const tablesData = await queryClient.ensureQueryData(
      tablesQueryOptions(projectId, databaseId, 0, TABLES_PER_PAGE, undefined),
    )

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
  const { databaseId } = Route.useParams()
  return (
    <TableView
      databaseId={databaseId}
      tableId="-"
      activeTab="rows"
      databaseTab="export-import"
    />
  )
}

import { type DatabaseRouteKind } from '@/lib/database-routes'
import { createFileRoute } from '@tanstack/react-router'
import { Workspace } from '@/components/pages/projects/$projectId/databases/View'
import {
  projectQueryOptions,
  databaseQueryOptions,
  tablesQueryOptions,
  databaseCsvMigrationsQueryOptions,
} from '@/lib/react-query/hooks'
import { listSearchSchema } from '@/lib/table-filters'
import { pageTitle } from '@/lib/utils/page-title'
import { TABLE_WORKSPACE_TABLES_LIST_LIMIT } from '@/lib/react-query/hooks/constants'

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/$dbKind/$databaseId/export-import',
)({
  validateSearch: listSearchSchema,
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

    await queryClient.ensureQueryData(projectQueryOptions(projectId))
    await queryClient.ensureQueryData(
      databaseQueryOptions(projectId, databaseId, dbKind as DatabaseRouteKind),
    )
    const tablesData = await queryClient.ensureQueryData(
      tablesQueryOptions(
        projectId,
        databaseId,
        dbKind as DatabaseRouteKind,
        0,
        TABLE_WORKSPACE_TABLES_LIST_LIMIT,
        undefined,
      ),
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
      databaseQueryOptions(projectId, databaseId, dbKind as DatabaseRouteKind).queryKey,
    )
    return { database }
  },
  component: ExportImportPage,
})

function ExportImportPage() {
  const { databaseId } = Route.useParams()
  return (
    <Workspace
      databaseId={databaseId}
      tableId="-"
      activeTab="rows"
      databaseTab="export-import"
    />
  )
}

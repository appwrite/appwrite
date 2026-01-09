import { createFileRoute } from '@tanstack/react-router'
import { TableView } from '@/components/pages/projects/$projectId/databases/View'
import { fetchProjectTables, fetchProjectDatabase, fetchProjectTableColumns, fetchProjectTable } from '@/lib/react-query/hooks'

const TABLES_PER_PAGE = 100

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/$databaseId/tables/$tableId/security',
)({
  loader: async ({ params, context }) => {
    // Only run on client side (SDK requires browser environment)
    if (typeof window === 'undefined') {
      return
    }

    const { projectId, databaseId, tableId } = params
    const { queryClient } = context

    if (!projectId || !databaseId || !tableId) {
      return
    }

    // Resolve all required data before rendering to avoid intermediate empty states
    await Promise.all([
      queryClient.ensureQueryData({
        queryKey: ['tables', 'project', projectId, databaseId, 0, TABLES_PER_PAGE, undefined],
        queryFn: () => fetchProjectTables(projectId, databaseId, 0, TABLES_PER_PAGE, undefined),
        staleTime: 30 * 1000, // 30 seconds
      }),
      queryClient.ensureQueryData({
        queryKey: ['database', 'project', projectId, databaseId],
        queryFn: () => fetchProjectDatabase(projectId, databaseId),
        staleTime: 30 * 1000,
      }),
      queryClient.ensureQueryData({
        queryKey: ['columns', 'project', projectId, databaseId, tableId],
        queryFn: () => fetchProjectTableColumns(projectId, databaseId, tableId),
        staleTime: 30 * 1000, // 30 seconds
      }),
      queryClient.ensureQueryData({
        queryKey: ['table', 'project', projectId, databaseId, tableId],
        queryFn: () => fetchProjectTable(projectId, databaseId, tableId),
        staleTime: 30 * 1000, // 30 seconds
      }),
    ])
  },
  component: SecurityPage,
})

function SecurityPage() {
  const { databaseId, tableId } = Route.useParams()

  return (
    <TableView databaseId={databaseId} tableId={tableId} activeTab="security" />
  )
}

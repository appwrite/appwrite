import { createFileRoute } from '@tanstack/react-router'
import { TableView } from '@/components/pages/projects/$projectId/databases/View'
import {
  fetchProjectTables,
  fetchProjectDatabase,
  fetchProjectTableColumns,
  fetchProjectTableIndexes,
  fetchProjectTable,
} from '@/lib/react-query/hooks'

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

    // Fetch critical data before rendering to prevent layout shifts
    await Promise.all([
      // Fetch tables list - blocks navigation until ready
      queryClient.fetchQuery({
        queryKey: [
          'tables',
          'project',
          projectId,
          databaseId,
          0,
          TABLES_PER_PAGE,
          undefined,
        ],
        queryFn: () =>
          fetchProjectTables(
            projectId,
            databaseId,
            0,
            TABLES_PER_PAGE,
            undefined,
          ),
        staleTime: 30 * 1000, // 30 seconds
      }),
      // Fetch database - blocks navigation until ready
      queryClient.fetchQuery({
        queryKey: ['database', 'project', projectId, databaseId],
        queryFn: () => fetchProjectDatabase(projectId, databaseId),
        staleTime: 30 * 1000,
      }),
      // Fetch columns - blocks navigation until ready
      queryClient.fetchQuery({
        queryKey: ['columns', 'project', projectId, databaseId, tableId],
        queryFn: () => fetchProjectTableColumns(projectId, databaseId, tableId),
        staleTime: 30 * 1000, // 30 seconds
      }),
      // Prefetch indexes (optional data)
      queryClient.prefetchQuery({
        queryKey: ['indexes', 'project', projectId, databaseId, tableId],
        queryFn: () => fetchProjectTableIndexes(projectId, databaseId, tableId),
        staleTime: 30 * 1000, // 30 seconds
      }),
      // Fetch table - blocks navigation until ready
      queryClient.fetchQuery({
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

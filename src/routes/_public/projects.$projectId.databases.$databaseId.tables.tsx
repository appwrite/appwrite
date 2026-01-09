import { createFileRoute, Outlet, useLocation } from '@tanstack/react-router'
import { DatabaseOverview } from '@/components/pages/projects/$projectId/databases/View'
import { fetchProjectDatabase, fetchProjectTables } from '@/lib/react-query/hooks'

const TABLES_PER_PAGE = 25

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/$databaseId/tables',
)({
  loader: async ({ params, context }) => {
    // Only run on client side (SDK requires browser environment)
    if (typeof window === 'undefined') {
      return
    }

    const { projectId, databaseId } = params
    const { queryClient } = context

    // Prefetch database and tables
    if (projectId && databaseId) {
      await queryClient.prefetchQuery({
        queryKey: ['database', 'project', projectId, databaseId],
        queryFn: () => fetchProjectDatabase(projectId, databaseId),
        staleTime: 30 * 1000, // 30 seconds
      })

      // Prefetch tables for the database (initial page, no search)
      await queryClient.prefetchQuery({
        queryKey: ['tables', 'project', projectId, databaseId, 0, TABLES_PER_PAGE, undefined],
        queryFn: () => fetchProjectTables(projectId, databaseId, 0, TABLES_PER_PAGE, undefined),
        staleTime: 30 * 1000, // 30 seconds
      })
    }
  },
  component: TablesLayout,
})

// Layout that shows overview when no tableId, or renders child routes
function TablesLayout() {
  const { databaseId } = Route.useParams()
  const location = useLocation()
  
  // Check if we're on a child route (has /tables/$tableId in path)
  const pathParts = location.pathname.split('/')
  const tableIdIndex = pathParts.indexOf('tables') + 1
  const hasTableId = tableIdIndex > 0 && tableIdIndex < pathParts.length && pathParts[tableIdIndex] !== ''
  
  // If we're on a child route (like /tables/$tableId/rows), render outlet
  if (hasTableId) {
    return <Outlet />
  }
  
  // Otherwise, show the tables overview
  return <DatabaseOverview databaseId={databaseId} activeTab="tables" />
}

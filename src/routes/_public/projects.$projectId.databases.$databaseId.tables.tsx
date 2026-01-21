import { createFileRoute, Outlet, useLocation, useNavigate } from '@tanstack/react-router'
import { useEffect } from 'react'
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

// Layout: when path is exactly /tables (no $tableId), redirect to database index; otherwise render child routes
function TablesLayout() {
  const { projectId, databaseId } = Route.useParams()
  const location = useLocation()
  const navigate = useNavigate()

  // Check if we're on a child route (has /tables/$tableId in path)
  const pathParts = location.pathname.split('/')
  const tableIdIndex = pathParts.indexOf('tables') + 1
  const hasTableId = tableIdIndex > 0 && tableIdIndex < pathParts.length && pathParts[tableIdIndex] !== ''

  // If exactly /tables with no $tableId, redirect to database index (main tables view)
  useEffect(() => {
    if (!hasTableId) {
      navigate({
        to: '/projects/$projectId/databases/$databaseId/',
        params: { projectId, databaseId },
        replace: true,
      })
    }
  }, [hasTableId, navigate, projectId, databaseId])

  if (!hasTableId) {
    return null
  }

  return <Outlet />
}

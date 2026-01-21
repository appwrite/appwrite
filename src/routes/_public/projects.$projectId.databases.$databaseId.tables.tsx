import { createFileRoute, Outlet } from '@tanstack/react-router'
import {
  fetchProjectDatabase,
  fetchProjectTables,
} from '@/lib/react-query/hooks'

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

    // Ensure database and tables are loaded before rendering to prevent layout shifts
    if (projectId && databaseId) {
      await Promise.all([
        queryClient.ensureQueryData({
          queryKey: ['database', 'project', projectId, databaseId],
          queryFn: () => fetchProjectDatabase(projectId, databaseId),
          staleTime: 30 * 1000, // 30 seconds
        }),
        queryClient.ensureQueryData({
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
      ])
    }
  },
  component: TablesLayout,
})

// Layout component that just renders child routes
function TablesLayout() {
  return <Outlet />
}

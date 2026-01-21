import { createFileRoute } from '@tanstack/react-router'
import { DatabaseOverview } from '@/components/pages/projects/$projectId/databases/View'
import { fetchProjectDatabase, fetchProjectTables } from '@/lib/react-query/hooks'

const TABLES_PER_PAGE = 25

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/$databaseId/',
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

      await queryClient.prefetchQuery({
        queryKey: ['tables', 'project', projectId, databaseId, 0, TABLES_PER_PAGE, undefined],
        queryFn: () => fetchProjectTables(projectId, databaseId, 0, TABLES_PER_PAGE, undefined),
        staleTime: 30 * 1000, // 30 seconds
      })
    }
  },
  component: DatabaseIndexPage,
})

function DatabaseIndexPage() {
  const { databaseId } = Route.useParams()
  return <DatabaseOverview databaseId={databaseId} activeTab="tables" />
}

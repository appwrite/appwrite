import { createFileRoute } from '@tanstack/react-router'
import { DatabaseOverview } from '@/components/pages/projects/$projectId/databases/View'
import { fetchProjectDatabase } from '@/lib/react-query/hooks'

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/$databaseId/insights',
)({
  loader: async ({ params, context }) => {
    // Only run on client side (SDK requires browser environment)
    if (typeof window === 'undefined') {
      return
    }

    const { projectId, databaseId } = params
    const { queryClient } = context

    // Prefetch database
    if (projectId && databaseId) {
      await queryClient.prefetchQuery({
        queryKey: ['database', 'project', projectId, databaseId],
        queryFn: () => fetchProjectDatabase(projectId, databaseId),
        staleTime: 30 * 1000, // 30 seconds
      })
    }
  },
  component: DatabaseOverviewInsights,
})

function DatabaseOverviewInsights() {
  const { databaseId } = Route.useParams()
  return <DatabaseOverview databaseId={databaseId} activeTab="insights" />
}



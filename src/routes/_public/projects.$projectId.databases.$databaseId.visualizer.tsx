import { createFileRoute } from '@tanstack/react-router'
import { DatabaseOverview } from '@/components/pages/projects/$projectId/databases/View'
import {
  fetchProjectDatabase,
  fetchAllProjectTablesForVisualizer,
} from '@/lib/react-query/hooks'

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/$databaseId/visualizer',
)({
  loader: async ({ params, context }) => {
    // Only run on client side (SDK requires browser environment)
    if (typeof window === 'undefined') {
      return
    }

    const { projectId, databaseId } = params
    const { queryClient } = context

    // Fetch critical data before rendering to prevent layout shifts
    // fetchQuery blocks navigation until ready
    if (projectId && databaseId) {
      await Promise.all([
        queryClient.fetchQuery({
          queryKey: ['database', 'project', projectId, databaseId],
          queryFn: () => fetchProjectDatabase(projectId, databaseId),
          staleTime: 30 * 1000, // 30 seconds
        }),
        queryClient.fetchQuery({
          queryKey: ['tables', 'visualizer', 'project', projectId, databaseId],
          queryFn: () =>
            fetchAllProjectTablesForVisualizer(projectId, databaseId),
          staleTime: 30 * 1000, // 30 seconds
        }),
      ])
    }
  },
  component: VisualizerPage,
})

function VisualizerPage() {
  const { databaseId } = Route.useParams()
  return <DatabaseOverview databaseId={databaseId} activeTab="visualizer" />
}

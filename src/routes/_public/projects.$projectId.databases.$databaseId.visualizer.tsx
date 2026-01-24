import { createFileRoute } from '@tanstack/react-router'
import { DatabaseOverview } from '@/components/pages/projects/$projectId/databases/View'
import {
  databaseQueryOptions,
  allTablesForVisualizerQueryOptions,
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
    // Uses ensureQueryData with queryOptions to prevent duplicate API calls
    if (projectId && databaseId) {
      await Promise.all([
        queryClient.ensureQueryData(
          databaseQueryOptions(projectId, databaseId),
        ),
        queryClient.ensureQueryData(
          allTablesForVisualizerQueryOptions(projectId, databaseId),
        ),
      ])
    }
  },
  component: VisualizerPage,
})

function VisualizerPage() {
  const { databaseId } = Route.useParams()
  return <DatabaseOverview databaseId={databaseId} activeTab="visualizer" />
}

import { createFileRoute } from '@tanstack/react-router'
import { DatabaseOverview } from '@/components/pages/projects/$projectId/databases/View'
import { fetchProjectDatabase } from '@/lib/react-query/hooks'

export const Route = createFileRoute(
  '/_public/projects/$projectId/databases/$databaseId/security',
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
      await queryClient.fetchQuery({
        queryKey: ['database', 'project', projectId, databaseId],
        queryFn: () => fetchProjectDatabase(projectId, databaseId),
        staleTime: 30 * 1000, // 30 seconds
      })
    }
  },
  component: DatabaseOverviewSecurity,
})

function DatabaseOverviewSecurity() {
  const { databaseId } = Route.useParams()
  return <DatabaseOverview databaseId={databaseId} activeTab="security" />
}

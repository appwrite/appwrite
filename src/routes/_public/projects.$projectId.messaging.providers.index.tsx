import { createFileRoute } from '@tanstack/react-router'
import { MessagingView } from '@/components/pages/projects/$projectId/messaging/View'
import {
  fetchProjectProviders,
  fetchProject,
  fetchOrganizationPlan,
} from '@/lib/react-query/hooks'

const PROVIDERS_PER_PAGE = 25

export const Route = createFileRoute(
  '/_public/projects/$projectId/messaging/providers/',
)({
  pendingComponent: () => (
    <div className="flex h-full items-center justify-center">
      <div className="text-muted-foreground">Loading providers...</div>
    </div>
  ),
  loader: async ({ params, context }) => {
    // Only run on client side (SDK requires browser environment)
    if (typeof window === 'undefined') {
      return
    }

    const { projectId } = params
    const { queryClient } = context

    if (projectId) {
      // Fetch project data (needed for header/sidebar) - blocks navigation
      const projectData = await queryClient.fetchQuery({
        queryKey: ['project', projectId],
        queryFn: () => fetchProject(projectId),
        staleTime: 5 * 60 * 1000, // 5 minutes
      })

      // Fetch critical data before rendering to prevent layout shifts
      // fetchQuery blocks navigation and respects staleTime (uses cached data if fresh)
      await Promise.all([
        // Fetch first page of providers - blocks navigation until ready
        queryClient.fetchQuery({
          queryKey: [
            'providers',
            'project',
            projectId,
            0,
            PROVIDERS_PER_PAGE,
            '',
          ],
          queryFn: () =>
            fetchProjectProviders(projectId, 0, PROVIDERS_PER_PAGE, ''),
          staleTime: 30 * 1000, // 30 seconds - uses cached data if fresh
        }),
        // Fetch organization plan if we have a teamId - CRITICAL for limit checking
        projectData?.teamId
          ? queryClient.fetchQuery({
              queryKey: ['organization', 'plan', projectData.teamId],
              queryFn: () => fetchOrganizationPlan(projectData.teamId),
              staleTime: 5 * 60 * 1000, // 5 minutes - uses cached data if fresh
            })
          : Promise.resolve(),
      ])
    }
  },
  component: ProvidersIndexPage,
})

function ProvidersIndexPage() {
  return <MessagingView />
}

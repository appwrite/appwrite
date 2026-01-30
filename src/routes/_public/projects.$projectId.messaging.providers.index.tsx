import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/messaging/View'
import {
  providersQueryOptions,
  fetchProject,
  organizationPlanQueryOptions,
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
      const projectData = await queryClient.ensureQueryData({
        queryKey: ['project', projectId],
        queryFn: () => fetchProject(projectId),
        staleTime: 5 * 60 * 1000, // 5 minutes
      })

      // Fetch critical data before rendering to prevent layout shifts
      // ensureQueryData blocks navigation and uses cache if fresh, fetches if stale/missing
      await Promise.all([
        // Fetch first page of providers - blocks navigation until ready
        queryClient.ensureQueryData(
          providersQueryOptions(projectId, 0, PROVIDERS_PER_PAGE, ''),
        ),
        // Fetch organization plan if we have a teamId - CRITICAL for limit checking
        projectData?.teamId
          ? queryClient.ensureQueryData(
              organizationPlanQueryOptions(projectData.teamId),
            )
          : Promise.resolve(),
      ])
    }
  },
  component: ProvidersIndexPage,
})

function ProvidersIndexPage() {
  return <View />
}

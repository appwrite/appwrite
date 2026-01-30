import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/sites/View'
import {
  sitesQueryOptions,
  fetchProject,
  organizationPlanQueryOptions,
} from '@/lib/react-query/hooks'
import { DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'

export const Route = createFileRoute('/_public/projects/$projectId/sites/')({
  pendingComponent: () => (
    <div className="flex h-full items-center justify-center">
      <div className="text-muted-foreground">Loading sites...</div>
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
        // Fetch first page of sites - blocks navigation until ready
        queryClient.ensureQueryData(
          sitesQueryOptions(projectId, 0, DEFAULT_PAGE_SIZE, ''),
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
  component: SitesIndexPage,
})

function SitesIndexPage() {
  const { projectId } = Route.useParams()
  return <View key={`sites-${projectId}-index`} />
}

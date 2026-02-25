import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/functions/View'
import {
  functionsQueryOptions,
  fetchProject,
  organizationPlanQueryOptions,
} from '@/lib/react-query/hooks'
import { DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/_public/projects/$projectId/functions/')(
  {
    head: () => ({ meta: [{ title: pageTitle('Functions') }] }),
    pendingComponent: () => (
      <div className="flex h-full items-center justify-center">
        <div className="text-muted-foreground">Loading functions...</div>
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
          // Fetch first page of functions - blocks navigation until ready
          queryClient.ensureQueryData(
            functionsQueryOptions(projectId, 0, DEFAULT_PAGE_SIZE, undefined),
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
    component: FunctionsIndexPage,
  },
)

function FunctionsIndexPage() {
  const { projectId } = Route.useParams()
  return <View key={`functions-${projectId}-index`} />
}

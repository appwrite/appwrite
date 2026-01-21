import { createFileRoute } from '@tanstack/react-router'
import { FunctionsView } from '@/components/pages/projects/$projectId/functions/View'
import {
  fetchProjectFunctions,
  fetchProject,
  fetchOrganizationPlan,
} from '@/lib/react-query/hooks'

const FUNCTIONS_PER_PAGE = 25

export const Route = createFileRoute('/_public/projects/$projectId/functions/')(
  {
    loader: async ({ params, context }) => {
      // Only run on client side (SDK requires browser environment)
      if (typeof window === 'undefined') {
        return
      }

      const { projectId } = params
      const { queryClient } = context

      if (projectId) {
        // Ensure project is loaded to get teamId
        const projectData = await queryClient.ensureQueryData({
          queryKey: ['project', projectId],
          queryFn: () => fetchProject(projectId),
          staleTime: 5 * 60 * 1000, // 5 minutes
        })

        // Ensure functions are loaded before rendering to prevent layout shifts
        await Promise.all([
          queryClient.ensureQueryData({
            queryKey: [
              'functions',
              'project',
              projectId,
              0,
              FUNCTIONS_PER_PAGE,
              undefined,
            ],
            queryFn: () =>
              fetchProjectFunctions(projectId, 0, FUNCTIONS_PER_PAGE, undefined),
            staleTime: 30 * 1000, // 30 seconds
          }),
          // Prefetch organization plan if we have a teamId (optional, for limit checking)
          projectData?.teamId
            ? queryClient.prefetchQuery({
                queryKey: ['organization', 'plan', projectData.teamId],
                queryFn: () => fetchOrganizationPlan(projectData.teamId),
                staleTime: 5 * 60 * 1000, // 5 minutes
              })
            : Promise.resolve(),
          // Prefetch total count for limit checking (optional)
          queryClient.prefetchQuery({
            queryKey: ['functions', 'project', projectId, 'total'],
            queryFn: () => fetchProjectFunctions(projectId, 0, 1, ''),
            staleTime: 30 * 1000, // 30 seconds
          }),
        ])
      }
    },
    component: FunctionsIndexPage,
  },
)

function FunctionsIndexPage() {
  const { projectId } = Route.useParams()
  return <FunctionsView key={`functions-${projectId}-index`} />
}

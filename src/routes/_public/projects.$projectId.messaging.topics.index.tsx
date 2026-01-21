import { createFileRoute } from '@tanstack/react-router'
import { MessagingView } from '@/components/pages/projects/$projectId/messaging/View'
import {
  fetchProjectTopics,
  fetchProject,
  fetchOrganizationPlan,
} from '@/lib/react-query/hooks'

const TOPICS_PER_PAGE = 25

export const Route = createFileRoute(
  '/_public/projects/$projectId/messaging/topics/',
)({
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

      // Ensure topics are loaded before rendering to prevent layout shifts
      await Promise.all([
        queryClient.ensureQueryData({
          queryKey: ['topics', 'project', projectId, 0, TOPICS_PER_PAGE, ''],
          queryFn: () => fetchProjectTopics(projectId, 0, TOPICS_PER_PAGE, ''),
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
          queryKey: ['topics', 'project', projectId, 'total'],
          queryFn: () => fetchProjectTopics(projectId, 0, 1, ''),
          staleTime: 30 * 1000, // 30 seconds
        }),
      ])
    }
  },
  component: TopicsIndexPage,
})

function TopicsIndexPage() {
  return <MessagingView />
}

import { createFileRoute } from '@tanstack/react-router'
import { MessagingView } from '@/components/pages/projects/$projectId/messaging/View'
import { fetchProjectMessages, fetchProject, fetchOrganizationPlan } from '@/lib/react-query/hooks'

const MESSAGES_PER_PAGE = 25

export const Route = createFileRoute('/_public/projects/$projectId/messaging/')({
  loader: async ({ params, context }) => {
    // Only run on client side (SDK requires browser environment)
    if (typeof window === 'undefined') {
      return
    }

    const { projectId } = params
    const { queryClient } = context

    if (projectId) {
      // Prefetch project to get teamId (needed for plan limits)
      const projectData = await queryClient.fetchQuery({
        queryKey: ['project', projectId],
        queryFn: () => fetchProject(projectId),
        staleTime: 5 * 60 * 1000, // 5 minutes
      })

      // Prefetch organization plan if we have a teamId (for limit checking)
      if (projectData?.teamId) {
        await queryClient.prefetchQuery({
          queryKey: ['organization', 'plan', projectData.teamId],
          queryFn: () => fetchOrganizationPlan(projectData.teamId),
          staleTime: 5 * 60 * 1000, // 5 minutes
        })
      }

      // Prefetch initial page of messages (page 0, no search)
      await queryClient.ensureQueryData({
        queryKey: ['messages', 'project', projectId, 0, MESSAGES_PER_PAGE, ''],
        queryFn: () => fetchProjectMessages(projectId, 0, MESSAGES_PER_PAGE, ''),
        staleTime: 30 * 1000, // 30 seconds
      })

      // Prefetch total count for limit checking (separate from search query)
      await queryClient.prefetchQuery({
        queryKey: ['messages', 'project', projectId, 'total'],
        queryFn: () => fetchProjectMessages(projectId, 0, 1, ''),
        staleTime: 30 * 1000, // 30 seconds
      })
    }
  },
  component: MessagingIndexPage,
})

function MessagingIndexPage() {
  return <MessagingView />
}

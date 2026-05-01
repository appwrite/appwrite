import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/messaging/View'
import {
  topicsQueryOptions,
  messagesQueryOptions,
  providersQueryOptions,
  fetchProject,
  organizationPlanQueryOptions,
} from '@/lib/react-query/hooks'
import { DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/messaging/topics/',
)({
  head: () => ({ meta: [{ title: pageTitle('Topics', 'Messaging') }] }),
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
        queryClient.ensureQueryData(
          topicsQueryOptions(projectId, 0, DEFAULT_PAGE_SIZE, ''),
        ),
        queryClient.ensureQueryData(
          messagesQueryOptions(projectId, 0, DEFAULT_PAGE_SIZE, ''),
        ),
        queryClient.ensureQueryData(
          providersQueryOptions(projectId, 0, DEFAULT_PAGE_SIZE, ''),
        ),
        projectData?.teamId
          ? queryClient.ensureQueryData(
              organizationPlanQueryOptions(projectData.teamId),
            )
          : Promise.resolve(),
      ])
    }
  },
  component: TopicsIndexPage,
})

function TopicsIndexPage() {
  return <View />
}

import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/messaging/topics/$topicId/View'
import { fetchTopic, fetchTopicSubscribers } from '@/lib/react-query/hooks'

const SUBSCRIBERS_PER_PAGE = 25

export const Route = createFileRoute(
  '/_public/projects/$projectId/messaging/topics/$topicId/',
)({
  loader: async ({ params, context }) => {
    // Only run on client side (SDK requires browser environment)
    if (typeof window === 'undefined') {
      return
    }

    const { projectId, topicId } = params
    const { queryClient } = context

    if (projectId && topicId) {
      // Fetch critical data before rendering to prevent layout shifts
      // fetchQuery blocks navigation until ready
      await Promise.all([
        queryClient.fetchQuery({
          queryKey: ['topic', 'project', projectId, topicId],
          queryFn: () => fetchTopic(projectId, topicId),
          staleTime: 30 * 1000,
        }),
        queryClient.fetchQuery({
          queryKey: [
            'subscribers',
            'project',
            projectId,
            'topic',
            topicId,
            0,
            SUBSCRIBERS_PER_PAGE,
            '',
          ],
          queryFn: () =>
            fetchTopicSubscribers(
              projectId,
              topicId,
              0,
              SUBSCRIBERS_PER_PAGE,
              '',
            ),
          staleTime: 30 * 1000,
        }),
      ])
    }
  },
  component: TopicDetailPage,
})

function TopicDetailPage() {
  return <View />
}

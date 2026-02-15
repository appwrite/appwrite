import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/messaging/TopicSettings'
import { fetchTopic } from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/messaging/topics/$topicId/settings',
)({
  head: ({ loaderData }) => ({
    meta: [
      {
        title: pageTitle(
          loaderData?.topic?.name ?? 'Topic',
          'Messaging',
        ),
      },
    ],
  }),
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
      await queryClient.fetchQuery({
        queryKey: ['topic', 'project', projectId, topicId],
        queryFn: () => fetchTopic(projectId, topicId),
        staleTime: 30 * 1000,
      })
    }
  },
  component: TopicSettingsPage,
})

function TopicSettingsPage() {
  return <View />
}

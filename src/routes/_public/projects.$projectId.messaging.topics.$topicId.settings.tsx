import { createFileRoute, redirect } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/messaging/TopicSettings'
import { fetchTopic } from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'
import { canAccessTopicSettings } from '@/lib/console-rbac-loader'

export const Route = createFileRoute(
  '/_public/projects/$projectId/messaging/topics/$topicId/settings',
)({
  head: ({ loaderData }) => ({
    meta: [
      {
        title: pageTitle(loaderData?.topic?.name ?? 'Topic', 'Messaging'),
      },
    ],
  }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return

    const { projectId, topicId } = params
    const { queryClient } = context

    if (!projectId || !topicId) return

    const canAccess = await canAccessTopicSettings(queryClient, projectId)
    if (!canAccess) {
      throw redirect({
        to: '/projects/$projectId/messaging/topics/$topicId',
        params: { projectId, topicId },
        replace: true,
      })
    }

    await queryClient.fetchQuery({
      queryKey: ['topic', 'project', projectId, topicId],
      queryFn: () => fetchTopic(projectId, topicId),
      staleTime: 30 * 1000,
    })
  },
  component: TopicSettingsPage,
})

function TopicSettingsPage() {
  return <View />
}

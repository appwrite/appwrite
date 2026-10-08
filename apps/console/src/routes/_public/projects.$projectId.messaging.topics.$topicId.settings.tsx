import { createFileRoute, redirect } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/messaging/TopicSettings'
import { topicQueryOptions } from '@/lib/react-query/hooks/messaging'
import type { Models } from '@appwrite.io/console'
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

    const topicOpts = topicQueryOptions(projectId, topicId)
    await queryClient.ensureQueryData(topicOpts)
    const topic = queryClient.getQueryData<Models.Topic>(topicOpts.queryKey)
    return { topic }
  },
  component: TopicSettingsPage,
})

function TopicSettingsPage() {
  const data = Route.useLoaderData()
  return <View initialTopic={data?.topic} />
}

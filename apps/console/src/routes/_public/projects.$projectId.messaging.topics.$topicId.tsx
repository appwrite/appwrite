import { createFileRoute, Outlet } from '@tanstack/react-router'
import { topicQueryOptions } from '@/lib/react-query/hooks'
import type { Models } from '@appwrite.io/console'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/messaging/topics/$topicId',
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
    await queryClient.ensureQueryData(topicQueryOptions(projectId, topicId))
    const topic = queryClient.getQueryData<Models.Topic>(
      topicQueryOptions(projectId, topicId).queryKey,
    )
    return { topic }
  },
  component: TopicLayout,
})

function TopicLayout() {
  return <Outlet />
}

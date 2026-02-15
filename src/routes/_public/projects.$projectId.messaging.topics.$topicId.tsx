import { createFileRoute, Outlet } from '@tanstack/react-router'
import { fetchTopic } from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/messaging/topics/$topicId',
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
    if (typeof window === 'undefined') return
    const { projectId, topicId } = params
    const { queryClient } = context
    if (!projectId || !topicId) return
    await queryClient.ensureQueryData({
      queryKey: ['topic', 'project', projectId, topicId],
      queryFn: () => fetchTopic(projectId, topicId),
      staleTime: 30 * 1000,
    })
    const topic = queryClient.getQueryData<{ name?: string }>([
      'topic',
      'project',
      projectId,
      topicId,
    ])
    return { topic }
  },
  component: TopicLayout,
})

function TopicLayout() {
  return <Outlet />
}

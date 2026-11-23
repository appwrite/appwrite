import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/messaging/View'
import {
  messagesQueryOptions,
  providersQueryOptions,
  topicsQueryOptions,
} from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/messaging/topics/',
)({
  head: () => ({ meta: [{ title: pageTitle('Topics', 'Messaging') }] }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return

    const { queryClient } = context
    const { projectId } = params
    const activeList = queryClient.ensureQueryData(
      topicsQueryOptions(projectId),
    )
    void queryClient.prefetchQuery(messagesQueryOptions(projectId))
    void queryClient.prefetchQuery(providersQueryOptions(projectId))
    await activeList
  },
  component: TopicsIndexPage,
})

function TopicsIndexPage() {
  return <View />
}

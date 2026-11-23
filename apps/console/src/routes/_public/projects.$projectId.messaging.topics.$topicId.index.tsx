import { createFileRoute } from '@tanstack/react-router'
import { Route as TopicParentRoute } from './projects.$projectId.messaging.topics.$topicId'
import { View } from '@/components/pages/projects/$projectId/messaging/topics/$topicId/View'
import { topicQueryOptions, topicSubscribersQueryOptions } from '@/lib/react-query/hooks'
import { DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import type { Models } from '@appwrite.io/console'

import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/messaging/topics/$topicId/',
)({
  head: ({ loaderData }) => ({
    meta: [
      {
        title: pageTitle(loaderData?.topic?.name ?? 'Topic', 'Messaging'),
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
      const subscribersOpts = topicSubscribersQueryOptions(
        projectId,
        topicId,
        0,
        DEFAULT_PAGE_SIZE,
        '',
      )
      await Promise.all([
        queryClient.ensureQueryData(topicQueryOptions(projectId, topicId)),
        queryClient.ensureQueryData(subscribersOpts),
      ])
      const topic = queryClient.getQueryData<Models.Topic>(
        topicQueryOptions(projectId, topicId).queryKey,
      )
      const subscriberList = queryClient.getQueryData<Models.SubscriberList>(
        subscribersOpts.queryKey,
      )
      return {
        topic,
        initialSubscribers:
          subscriberList != null
            ? {
                subscribers: subscriberList.subscribers ?? [],
                total: subscriberList.total ?? 0,
              }
            : undefined,
      }
    }
  },
  component: TopicDetailPage,
})

function TopicDetailPage() {
  const loaderData = Route.useLoaderData()
  const parentData = TopicParentRoute.useLoaderData()
  const initialTopic = loaderData?.topic ?? parentData?.topic
  return (
    <View
      initialSubscribers={loaderData?.initialSubscribers}
      initialTopic={initialTopic}
    />
  )
}

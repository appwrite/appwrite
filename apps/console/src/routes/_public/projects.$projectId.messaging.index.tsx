import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/messaging/View'
import {
  messagesQueryOptions,
  providersQueryOptions,
  topicsQueryOptions,
} from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/_public/projects/$projectId/messaging/')(
  {
    head: () => ({ meta: [{ title: pageTitle('Messaging') }] }),
    loader: async ({ params, context }) => {
      if (typeof window === 'undefined') return

      const { queryClient } = context
      const { projectId } = params
      const activeList = queryClient.ensureQueryData(
        messagesQueryOptions(projectId),
      )
      void queryClient.prefetchQuery(topicsQueryOptions(projectId))
      void queryClient.prefetchQuery(providersQueryOptions(projectId))
      await activeList
    },
    component: MessagingIndexPage,
  },
)

function MessagingIndexPage() {
  return <View />
}

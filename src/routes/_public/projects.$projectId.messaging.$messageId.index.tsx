import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/messaging/$messageId/View'
import { prefetchMessageDetailData } from '@/lib/react-query/hooks'
import type { Models } from '@appwrite.io/console'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/messaging/$messageId/',
)({
  head: ({ loaderData }) => ({
    meta: [
      {
        title: pageTitle(loaderData?.messageTitle ?? 'Message', 'Messaging'),
      },
    ],
  }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') {
      return
    }

    const { projectId, messageId } = params
    const { queryClient } = context

    if (projectId && messageId) {
      await prefetchMessageDetailData(queryClient, projectId, messageId)
      const message = queryClient.getQueryData<Models.Message>([
        'message',
        'project',
        projectId,
        messageId,
      ])
      const messageTitle =
        message?.providerType === 'email' && message.data?.subject
          ? String(message.data.subject)
          : message?.providerType === 'sms' && message.data?.content
            ? String(message.data.content).slice(0, 80)
            : message?.providerType === 'push' && message.data?.title
              ? String(message.data.title)
              : undefined
      return { messageTitle }
    }
  },
  component: MessageDetailPage,
})

function MessageDetailPage() {
  return <View />
}

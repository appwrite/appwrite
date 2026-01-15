import { createFileRoute } from '@tanstack/react-router'
import { MessageDetailView } from '@/components/pages/projects/$projectId/messaging/$messageId/View'
import { fetchMessage } from '@/lib/react-query/hooks'

export const Route = createFileRoute(
  '/_public/projects/$projectId/messaging/$messageId/',
)({
  loader: async ({ params, context }) => {
    // Only run on client side (SDK requires browser environment)
    if (typeof window === 'undefined') {
      return
    }

    const { projectId, messageId } = params
    const { queryClient } = context

    if (projectId && messageId) {
      await queryClient.prefetchQuery({
        queryKey: ['message', 'project', projectId, messageId],
        queryFn: () => fetchMessage(projectId, messageId),
        staleTime: 30 * 1000, // 30 seconds
      })
    }
  },
  component: MessageDetailPage,
})

function MessageDetailPage() {
  return <MessageDetailView />
}

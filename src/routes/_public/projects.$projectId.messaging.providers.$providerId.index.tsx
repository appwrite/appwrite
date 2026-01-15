import { createFileRoute } from '@tanstack/react-router'
import { ProviderDetailView } from '@/components/pages/projects/$projectId/messaging/providers/$providerId/View'
import { fetchProvider } from '@/lib/react-query/hooks'

export const Route = createFileRoute(
  '/_public/projects/$projectId/messaging/providers/$providerId/',
)({
  loader: async ({ params, context }) => {
    // Only run on client side (SDK requires browser environment)
    if (typeof window === 'undefined') {
      return
    }

    const { projectId, providerId } = params
    const { queryClient } = context

    if (projectId && providerId) {
      await queryClient.prefetchQuery({
        queryKey: ['provider', 'project', projectId, providerId],
        queryFn: () => fetchProvider(projectId, providerId),
        staleTime: 30 * 1000,
      })
    }
  },
  component: ProviderDetailPage,
})

function ProviderDetailPage() {
  return <ProviderDetailView />
}

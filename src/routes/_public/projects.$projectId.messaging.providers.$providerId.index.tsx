import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/messaging/providers/$providerId/View'
import { fetchProvider } from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/messaging/providers/$providerId/',
)({
  head: ({ loaderData }) => ({
    meta: [
      {
        title: pageTitle(loaderData?.provider?.name ?? 'Provider', 'Messaging'),
      },
    ],
  }),
  loader: async ({ params, context }) => {
    // Only run on client side (SDK requires browser environment)
    if (typeof window === 'undefined') {
      return
    }

    const { projectId, providerId } = params
    const { queryClient } = context

    if (projectId && providerId) {
      // Fetch critical data before rendering to prevent layout shifts
      // fetchQuery blocks navigation until ready
      await queryClient.fetchQuery({
        queryKey: ['provider', 'project', projectId, providerId],
        queryFn: () => fetchProvider(projectId, providerId),
        staleTime: 30 * 1000,
      })
      const provider = queryClient.getQueryData<{ name?: string }>([
        'provider',
        'project',
        projectId,
        providerId,
      ])
      return { provider }
    }
  },
  component: ProviderDetailPage,
})

function ProviderDetailPage() {
  return <View />
}

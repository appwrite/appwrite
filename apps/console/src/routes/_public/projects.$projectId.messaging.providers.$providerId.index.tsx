import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/messaging/providers/$providerId/View'
import { providerQueryOptions } from '@/lib/react-query/hooks'
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
      await queryClient.ensureQueryData(
        providerQueryOptions(projectId, providerId),
      )
      const provider = queryClient.getQueryData<{ name?: string }>(
        providerQueryOptions(projectId, providerId).queryKey,
      )
      return { provider }
    }
  },
  component: ProviderDetailPage,
})

function ProviderDetailPage() {
  const data = Route.useLoaderData()
  return <View initialProvider={data?.provider} />
}

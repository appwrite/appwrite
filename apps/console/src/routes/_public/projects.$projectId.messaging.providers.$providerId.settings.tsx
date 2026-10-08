import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/messaging/providers/$providerId/View'
import { providerQueryOptions } from '@/lib/react-query/hooks'
import type { Models } from '@appwrite.io/console'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/messaging/providers/$providerId/settings',
)({
  head: ({ loaderData }) => ({
    meta: [
      {
        title: pageTitle(loaderData?.provider?.name ?? 'Provider', 'Messaging'),
      },
    ],
  }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') {
      return { provider: undefined }
    }

    const { projectId, providerId } = params
    const { queryClient } = context

    if (!projectId || !providerId) {
      return { provider: undefined }
    }

    const providerOpts = providerQueryOptions(projectId, providerId)
    await queryClient.ensureQueryData(providerOpts)

    const provider = queryClient.getQueryData<Models.Provider>(
      providerOpts.queryKey,
    )
    return { provider }
  },
  component: ProviderSettingsPage,
})

function ProviderSettingsPage() {
  const data = Route.useLoaderData()
  return <View initialProvider={data?.provider} />
}

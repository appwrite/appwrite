import { createFileRoute, Outlet } from '@tanstack/react-router'
import { providerQueryOptions } from '@/lib/react-query/hooks'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/projects/$projectId/messaging/providers/$providerId',
)({
  head: ({ loaderData }) => ({
    meta: [
      {
        title: pageTitle(loaderData?.provider?.name ?? 'Provider', 'Messaging'),
      },
    ],
  }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return
    const { projectId, providerId } = params
    const { queryClient } = context
    if (!projectId || !providerId) return
    await queryClient.ensureQueryData(
      providerQueryOptions(projectId, providerId),
    )
    const provider = queryClient.getQueryData<{ name?: string }>(
      providerQueryOptions(projectId, providerId).queryKey,
    )
    return { provider }
  },
  component: ProviderLayout,
})

function ProviderLayout() {
  return <Outlet />
}

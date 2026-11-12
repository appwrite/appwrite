import { View } from '@/components/pages/organizations/$orgId/apps/$appId/installations/View'
import { createFileRoute } from '@tanstack/react-router'
import { organizationAppInstallationsInfiniteQueryOptions } from '@/lib/react-query/hooks'

export const Route = createFileRoute(
  '/_public/organizations/$orgId/apps/$appId/installations',
)({
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return
    const { appId } = params
    const { queryClient } = context
    if (!appId) return

    // Blocks navigation until the first page is cached, so the previous page
    // stays visible instead of a loading state.
    await queryClient
      .ensureInfiniteQueryData(
        organizationAppInstallationsInfiniteQueryOptions(appId),
      )
      .catch(() => undefined)
  },
  component: OrgAppInstallationsPage,
})

function OrgAppInstallationsPage() {
  return <View />
}

import { View } from '@/components/pages/organizations/$orgId/apps/$appId/keys/View'
import { createFileRoute } from '@tanstack/react-router'
import { organizationAppKeysInfiniteQueryOptions } from '@/lib/react-query/hooks'

export const Route = createFileRoute(
  '/_public/organizations/$orgId/apps/$appId/keys',
)({
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return
    const { appId } = params
    const { queryClient } = context
    if (!appId) return

    // Blocks navigation until the first page is cached, so the previous page
    // stays visible instead of a loading state.
    await queryClient
      .ensureInfiniteQueryData(organizationAppKeysInfiniteQueryOptions(appId))
      .catch(() => undefined)
  },
  component: OrgAppKeysPage,
})

function OrgAppKeysPage() {
  return <View />
}

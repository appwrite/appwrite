import { View } from '@/components/pages/organizations/$orgId/domains/$domainId/View'
import { createFileRoute } from '@tanstack/react-router'
import { fetchDomain, fetchOrganizations } from '@/lib/react-query/hooks'

const STALE_TIME = 30 * 1000

export const Route = createFileRoute(
  '/_public/organizations/$orgId/domains/$domainId/settings',
)({
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return

    const { domainId } = params
    const { queryClient } = context

    if (!domainId) return

    // Same pattern as bucket settings: fetchQuery with exact keys matching hooks. Blocks navigation until ready.
    await Promise.all([
      queryClient.fetchQuery({
        queryKey: ['domain', domainId],
        queryFn: () => fetchDomain(domainId),
        staleTime: STALE_TIME,
      }),
      queryClient.fetchQuery({
        queryKey: ['organizations', 'console'],
        queryFn: fetchOrganizations,
        staleTime: STALE_TIME,
      }),
    ])
  },
  component: DomainDetailPage,
})

function DomainDetailPage() {
  const { domainId } = Route.useParams()
  return <View key={`domain-${domainId}-settings`} />
}

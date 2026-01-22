import { DomainDetailView } from '@/components/pages/organizations/$orgId/domains/$domainId/View'
import { createFileRoute } from '@tanstack/react-router'
import {
  fetchOrganizations,
  domainQueryOptions,
  domainRecordsQueryOptions,
} from '@/lib/react-query/hooks'

const RECORDS_PER_PAGE = 25

export const Route = createFileRoute(
  '/_public/organizations/$orgId/domains/$domainId/',
)({
  loader: async ({ params, context }) => {
    // Only run on client side (SDK requires browser environment)
    if (typeof window === 'undefined') {
      return
    }

    const { orgId, domainId } = params
    const { queryClient } = context

    // Prefetch organizations if not already loaded
    await queryClient.prefetchQuery({
      queryKey: ['organizations', 'console'],
      queryFn: fetchOrganizations,
      staleTime: 5 * 60 * 1000, // 5 minutes
    })

    // Fetch domain details and DNS records - blocks navigation until ready
    if (domainId) {
      await Promise.all([
        // Fetch domain details - blocks navigation until ready
        queryClient.ensureQueryData(domainQueryOptions(domainId)),
        // Fetch first page of DNS records - blocks navigation until ready
        queryClient.ensureQueryData(
          domainRecordsQueryOptions(domainId, 0, RECORDS_PER_PAGE),
        ),
      ])
    }
  },
  component: DomainDetailPage,
})

function DomainDetailPage() {
  const { orgId, domainId } = Route.useParams()
  return <DomainDetailView key={`domain-${domainId}-index`} />
}

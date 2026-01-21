import { DomainDetailView } from '@/components/pages/organizations/$orgId/domains/$domainId/View'
import { createFileRoute } from '@tanstack/react-router'
import {
  fetchOrganizations,
  fetchDomain,
  fetchDomainRecords,
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

    // Prefetch domain details and DNS records
    if (domainId) {
      // Prefetch domain details
      try {
        await queryClient.ensureQueryData({
          queryKey: ['domain', domainId],
          queryFn: () => fetchDomain(domainId),
          staleTime: 30 * 1000, // 30 seconds
        })
      } catch (error) {
        // Silently fail - component will handle error state
      }

      // Prefetch DNS records (initial page) - use ensureQueryData to prevent layout shifts
      try {
        await queryClient.ensureQueryData({
          queryKey: ['dns-records', 'domain', domainId, 0, RECORDS_PER_PAGE],
          queryFn: () => fetchDomainRecords(domainId, 0, RECORDS_PER_PAGE),
          staleTime: 30 * 1000, // 30 seconds
        })
      } catch (error) {
        // Silently fail - component will handle error state
      }
    }
  },
  component: DomainDetailPage,
})

function DomainDetailPage() {
  const { orgId, domainId } = Route.useParams()
  return <DomainDetailView key={`domain-${domainId}-index`} />
}

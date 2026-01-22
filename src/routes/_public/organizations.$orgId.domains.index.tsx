import { DomainsView } from '@/components/pages/organizations/$orgId/domains/View'
import { createFileRoute } from '@tanstack/react-router'
import {
  fetchOrganizations,
  fetchOrganizationDomains,
} from '@/lib/react-query/hooks'

const DOMAINS_PER_PAGE = 25

export const Route = createFileRoute('/_public/organizations/$orgId/domains/')({
  loader: async ({ params, context }) => {
    // Only run on client side (SDK requires browser environment)
    if (typeof window === 'undefined') {
      return
    }

    const { orgId } = params
    const { queryClient } = context

    // Prefetch organizations if not already loaded
    await queryClient.prefetchQuery({
      queryKey: ['organizations', 'console'],
      queryFn: fetchOrganizations,
      staleTime: 5 * 60 * 1000, // 5 minutes
    })

    // Fetch domains for the organization (initial page, no search)
    if (orgId) {
      // Fetch first page of domains - blocks navigation until ready
      await queryClient.fetchQuery({
        queryKey: ['domains', 'organization', orgId, 0, DOMAINS_PER_PAGE, ''],
        queryFn: () => fetchOrganizationDomains(orgId, 0, DOMAINS_PER_PAGE, ''),
        staleTime: 30 * 1000, // 30 seconds
      })
    }
  },
  component: DomainsIndexPage,
})

function DomainsIndexPage() {
  return <DomainsView />
}

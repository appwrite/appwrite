import { createFileRoute, Outlet } from '@tanstack/react-router'
import {
  fetchOrganizations,
  fetchOrganizationDomains,
} from '@/lib/react-query/hooks'

const DOMAINS_PER_PAGE = 25

export const Route = createFileRoute('/_public/organizations/$orgId/domains')({
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

    // Prefetch domains for the organization (initial page, no search)
    // This ensures data is ready even if index route hasn't matched yet
    if (orgId) {
      // Prefetch initial page of domains - use ensureQueryData to prevent layout shifts
      await queryClient.ensureQueryData({
        queryKey: ['domains', 'organization', orgId, 0, DOMAINS_PER_PAGE, ''],
        queryFn: () => fetchOrganizationDomains(orgId, 0, DOMAINS_PER_PAGE, ''),
        staleTime: 30 * 1000, // 30 seconds
      })

      // Prefetch total count for limit checking (separate from search query)
      await queryClient.prefetchQuery({
        queryKey: ['domains', 'organization', orgId, 0, 1, ''],
        queryFn: () => fetchOrganizationDomains(orgId, 0, 1, ''),
        staleTime: 30 * 1000, // 30 seconds
      })
    }
  },
  component: DomainsLayout,
})

function DomainsLayout() {
  // This is a layout route that just renders the outlet
  // The index route handles the list view, and the $domainId route handles detail views
  return <Outlet />
}

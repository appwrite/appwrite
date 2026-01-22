import { createFileRoute, Outlet } from '@tanstack/react-router'
import {
  fetchOrganizations,
  organizationDomainsQueryOptions,
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

    // Fetch domains for the organization (initial page, no search)
    // This ensures data is ready even if index route hasn't matched yet
    if (orgId) {
      // Fetch first page of domains - blocks navigation until ready
      await queryClient.ensureQueryData(
        organizationDomainsQueryOptions(orgId, 0, DOMAINS_PER_PAGE, ''),
      )
    }
  },
  component: DomainsLayout,
})

function DomainsLayout() {
  // This is a layout route that just renders the outlet
  // The index route handles the list view, and the $domainId route handles detail views
  return <Outlet />
}

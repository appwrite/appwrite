import { DomainsView } from '@/components/pages/organizations/$orgId/domains/View'
import { createFileRoute, Outlet, useMatches } from '@tanstack/react-router'
import { fetchOrganizations, fetchOrganizationDomains } from '@/lib/react-query/hooks'

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
    if (orgId) {
      await queryClient.ensureQueryData({
        queryKey: ['domains', 'organization', orgId, 0, DOMAINS_PER_PAGE, ''],
        queryFn: () => fetchOrganizationDomains(orgId, 0, DOMAINS_PER_PAGE, ''),
        staleTime: 30 * 1000, // 30 seconds
      })
    }
  },
  component: DomainsPage,
})

function DomainsPage() {
  const matches = useMatches()
  
  // Check if we're on a child route (domain detail)
  const isChildRoute = matches.some(
    (match) =>
      match.routeId.includes('/domains/$domainId') ||
      match.routeId === '/_public/organizations/$orgId/domains/$domainId' ||
      match.routeId.startsWith('/_public/organizations/$orgId/domains/$domainId')
  )

  // If we're on a child route, render the outlet (child route component)
  // The child route will be rendered within OrgOverview from the parent route
  if (isChildRoute) {
    return <Outlet />
  }

  // Otherwise, show the domains list view
  // This will be rendered within OrgOverview from the parent route
  return <DomainsView />
}


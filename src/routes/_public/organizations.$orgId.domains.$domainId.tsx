import { DomainDetailView } from '@/components/pages/organizations/$orgId/domains/$domainId/View'
import { createFileRoute, Outlet, useMatches } from '@tanstack/react-router'
import { fetchOrganizations, fetchDomain, fetchDomainRecords } from '@/lib/react-query/hooks'

const RECORDS_PER_PAGE = 25

export const Route = createFileRoute('/_public/organizations/$orgId/domains/$domainId')({
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

    // Prefetch domain details
    if (domainId) {
      await queryClient.ensureQueryData({
        queryKey: ['domain', domainId],
        queryFn: () => fetchDomain(domainId),
        staleTime: 30 * 1000, // 30 seconds
      })

      // Prefetch DNS records (initial page)
      await queryClient.prefetchQuery({
        queryKey: ['dns-records', 'domain', domainId, 0, RECORDS_PER_PAGE],
        queryFn: () => fetchDomainRecords(domainId, 0, RECORDS_PER_PAGE),
        staleTime: 30 * 1000, // 30 seconds
      })
    }
  },
  component: DomainDetailLayout,
})

function DomainDetailLayout() {
  const matches = useMatches()
  const { domainId } = Route.useParams()
  
  // Check if we're on a child route (settings sub-route)
  const isChildRoute = matches.some(
    (match) =>
      match.routeId.includes('/domains/$domainId/settings') ||
      match.routeId === '/_public/organizations/$orgId/domains/$domainId/settings' ||
      match.routeId.startsWith('/_public/organizations/$orgId/domains/$domainId/settings')
  )

  // If we're on a child route, render the outlet (child route component)
  if (isChildRoute) {
    return <Outlet />
  }

  // Render the domain detail view directly (it will be within OrgOverview layout from parent route)
  return <DomainDetailView key={`domain-${domainId}`} />
}


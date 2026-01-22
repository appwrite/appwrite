import { createFileRoute } from '@tanstack/react-router'
import {
  fetchOrganizationMemberships,
  fetchOrganizations,
  fetchActiveProjects,
  fetchOrganizationPlan,
} from '@/lib/react-query/hooks'
import { DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'

export const Route = createFileRoute('/_public/organizations/$orgId/')({
  loader: async ({ params, context }) => {
    // Only run on client side (SDK requires browser environment)
    if (typeof window === 'undefined') {
      return
    }

    const { orgId } = params
    const { queryClient } = context

    // Prefetch organizations list (non-critical, for dropdowns) - doesn't block
    queryClient.prefetchQuery({
      queryKey: ['organizations', 'console'],
      queryFn: fetchOrganizations,
      staleTime: 5 * 60 * 1000, // 5 minutes
    }).catch(() => {
      // Don't block on optional data
    })

    // Fetch critical page-specific data before rendering to prevent layout shifts
    // Note: Organization plan is already loaded in parent route's beforeLoad
    if (orgId) {
      await Promise.all([
        // Fetch first page of projects - blocks navigation until ready
        queryClient.ensureQueryData({
          queryKey: ['projects', 'active', 0, '', orgId],
          queryFn: () => fetchActiveProjects(orgId, 0, DEFAULT_PAGE_SIZE, ''),
          staleTime: 30 * 1000, // 30 seconds
        }),
        // Fetch first page of memberships - blocks navigation until ready
        queryClient.ensureQueryData({
          queryKey: [
            'memberships',
            'organization',
            orgId,
            0,
            DEFAULT_PAGE_SIZE,
            '',
          ],
          queryFn: () =>
            fetchOrganizationMemberships(orgId, 0, DEFAULT_PAGE_SIZE, ''),
          staleTime: 30 * 1000, // 30 seconds
        }),
      ])
    }
  },
  component: OrgOverviewIndexPage,
})

// Index route doesn't need to render anything - parent OrgOverview handles the content
function OrgOverviewIndexPage() {
  return null
}

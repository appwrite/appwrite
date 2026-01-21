import { createFileRoute } from '@tanstack/react-router'
import {
  fetchOrganizationMemberships,
  fetchOrganizations,
  fetchActiveProjects,
  fetchOrganizationPlan,
} from '@/lib/react-query/hooks'

const PROJECTS_PER_PAGE = 25
const MEMBERSHIPS_PER_PAGE = 25

export const Route = createFileRoute('/_public/organizations/$orgId/')({
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

    // Ensure critical data is loaded before rendering to prevent layout shifts
    if (orgId) {
      await Promise.all([
        // Ensure organization plan is loaded
        queryClient.ensureQueryData({
          queryKey: ['organization', 'plan', orgId],
          queryFn: () => fetchOrganizationPlan(orgId),
          staleTime: 5 * 60 * 1000, // 5 minutes
        }),
        // Ensure projects are loaded
        queryClient.ensureQueryData({
          queryKey: ['projects', 'active', 0, '', orgId],
          queryFn: () => fetchActiveProjects(orgId, 0, PROJECTS_PER_PAGE, ''),
          staleTime: 30 * 1000, // 30 seconds
        }),
        // Ensure memberships are loaded
        queryClient.ensureQueryData({
          queryKey: [
            'memberships',
            'organization',
            orgId,
            0,
            MEMBERSHIPS_PER_PAGE,
            '',
          ],
          queryFn: () =>
            fetchOrganizationMemberships(orgId, 0, MEMBERSHIPS_PER_PAGE, ''),
          staleTime: 30 * 1000, // 30 seconds
        }),
        // Prefetch total count for limit checking (optional)
        queryClient.prefetchQuery({
          queryKey: ['projects', 'active', 'total', orgId],
          queryFn: () => fetchActiveProjects(orgId, 0, 1, ''),
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

import { OrgOverview } from '@/components/pages/organizations/$orgId/overview/View'
import { createFileRoute } from '@tanstack/react-router'
import { RequireAuth } from '@/components/global/auth/RequireAuth'
import { fetchOrganizationMemberships, fetchOrganizations, fetchActiveProjects, fetchOrganizationPlan } from '@/lib/react-query/hooks'

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

    // Prefetch projects for the organization (initial page, no search)
    if (orgId) {
      // Prefetch organization plan
      await queryClient.prefetchQuery({
        queryKey: ['organization', 'plan', orgId],
        queryFn: () => fetchOrganizationPlan(orgId),
        staleTime: 5 * 60 * 1000, // 5 minutes
      })

      // Prefetch projects for the organization (initial page, no search)
      await queryClient.prefetchQuery({
        queryKey: ['projects', 'active', 0, '', orgId],
        queryFn: () => fetchActiveProjects(orgId, 0, PROJECTS_PER_PAGE, ''),
        staleTime: 30 * 1000, // 30 seconds
      })

      // Prefetch total count for limit checking
      await queryClient.prefetchQuery({
        queryKey: ['projects', 'active', 'total', orgId],
        queryFn: () => fetchActiveProjects(orgId, 0, 1, ''),
        staleTime: 30 * 1000, // 30 seconds
      })

      // Prefetch memberships for the organization (initial page, no search)
      await queryClient.prefetchQuery({
        queryKey: ['memberships', 'organization', orgId, 0, MEMBERSHIPS_PER_PAGE, ''],
        queryFn: () => fetchOrganizationMemberships(orgId, 0, MEMBERSHIPS_PER_PAGE, ''),
        staleTime: 30 * 1000, // 30 seconds
      })
    }
  },
  component: OrgOverviewPage,
})

function OrgOverviewPage() {
  const { orgId } = Route.useParams()
  return (
    <RequireAuth>
      <OrgOverview key={`org-${orgId}-projects-index`} />
    </RequireAuth>
  )
}


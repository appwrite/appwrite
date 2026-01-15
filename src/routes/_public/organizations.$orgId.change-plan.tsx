import { createFileRoute } from '@tanstack/react-router'
import {
  fetchOrganizations,
  fetchOrganizationById,
  fetchOrganizationPlan,
  fetchOrganizationMemberships,
  fetchBillingPlans,
  fetchOrganizationUsage,
  fetchOrganizationProjects,
} from '@/lib/react-query/hooks'
import { ChangePlanWizardFullscreen } from '@/components/pages/organizations/$orgId/billing/ChangePlanWizardFullscreen'

const MEMBERSHIPS_PER_PAGE = 25

export const Route = createFileRoute('/_public/organizations/$orgId/change-plan')({
  loader: async ({ params, context }) => {
    // Only run on client side (SDK requires browser environment)
    if (typeof window === 'undefined') {
      return
    }

    const { orgId } = params
    const { queryClient } = context

    if (!orgId) {
      return
    }

    // Prefetch all required data in parallel (don't block on errors)
    try {
      await Promise.all([
        // Organizations list
        queryClient.prefetchQuery({
          queryKey: ['organizations', 'console'],
          queryFn: fetchOrganizations,
          staleTime: 5 * 60 * 1000, // 5 minutes
        }).catch(() => {}),

        // Organization details
        queryClient.prefetchQuery({
          queryKey: ['organization', orgId],
          queryFn: () => fetchOrganizationById(orgId),
          staleTime: 5 * 60 * 1000, // 5 minutes
        }).catch(() => {}),

        // Organization plan
        queryClient.prefetchQuery({
          queryKey: ['organization', 'plan', orgId],
          queryFn: () => fetchOrganizationPlan(orgId),
          staleTime: 5 * 60 * 1000, // 5 minutes
        }).catch(() => {}),

        // Organization memberships
        queryClient.prefetchQuery({
          queryKey: ['memberships', 'organization', orgId, 0, MEMBERSHIPS_PER_PAGE, ''],
          queryFn: () => fetchOrganizationMemberships(orgId, 0, MEMBERSHIPS_PER_PAGE, ''),
          staleTime: 30 * 1000, // 30 seconds
        }).catch(() => {}),

        // Billing plans
        queryClient.prefetchQuery({
          queryKey: ['billing-plans'],
          queryFn: fetchBillingPlans,
          staleTime: 5 * 60 * 1000, // 5 minutes
        }).catch(() => {}),

        // Organization usage
        queryClient.prefetchQuery({
          queryKey: ['organization-usage', orgId],
          queryFn: () => fetchOrganizationUsage(orgId),
          staleTime: 30 * 1000, // 30 seconds
        }).catch(() => {}),

        // Organization projects
        queryClient.prefetchQuery({
          queryKey: ['organization-projects', orgId],
          queryFn: () => fetchOrganizationProjects(orgId),
          staleTime: 30 * 1000, // 30 seconds
        }).catch(() => {}),
      ])
    } catch (error) {
      // Don't block rendering if prefetch fails
      console.warn('Error prefetching data in change-plan route:', error)
    }
  },
  component: ChangePlanPage,
})

function ChangePlanPage() {
  return <ChangePlanWizardFullscreen />
}

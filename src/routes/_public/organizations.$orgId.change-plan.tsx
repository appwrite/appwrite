import { createFileRoute } from '@tanstack/react-router'
import { pageTitle } from '@/lib/utils/page-title'
import {
  organizationsQueryOptions,
  organizationQueryOptions,
  organizationPlanQueryOptions,
  organizationMembershipsQueryOptions,
  billingPlansQueryOptions,
  organizationUsageQueryOptions,
  organizationProjectsQueryOptions,
} from '@/lib/react-query/hooks'
import { ChangePlanWizardFullscreen } from '@/components/pages/organizations/$orgId/billing/ChangePlanWizardFullscreen'
import { DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'

export const Route = createFileRoute(
  '/_public/organizations/$orgId/change-plan',
)({
  head: () => ({ meta: [{ title: pageTitle('Change plan', 'Organization') }] }),
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

    // Fetch all critical data before rendering to prevent layout shifts
    // ensureQueryData blocks navigation and uses cache if fresh, fetches if stale/missing
    await Promise.all([
      // Organization plan - CRITICAL for plan selection and self-service check
      queryClient.ensureQueryData(organizationPlanQueryOptions(orgId)),

      // Organization details - CRITICAL for default plan calculation
      queryClient.ensureQueryData(organizationQueryOptions(orgId)),

      // Organizations list - CRITICAL for hasFreeOrgs check
      queryClient.ensureQueryData(organizationsQueryOptions()),

      // Organization memberships - CRITICAL for members count
      queryClient.ensureQueryData(
        organizationMembershipsQueryOptions(orgId, 0, DEFAULT_PAGE_SIZE, ''),
      ),

      // Billing plans - CRITICAL for plan selection UI
      queryClient.ensureQueryData(billingPlansQueryOptions()),

      // Organization usage - used in sidebar (less critical but prefetch for better UX)
      queryClient
        .ensureQueryData(organizationUsageQueryOptions(orgId))
        .catch(() => {
          // Don't block navigation if usage fetch fails
        }),

      // Organization projects - used for downgrade flow (less critical but prefetch)
      queryClient
        .ensureQueryData(organizationProjectsQueryOptions(orgId))
        .catch(() => {
          // Don't block navigation if projects fetch fails
        }),
    ])
  },
  component: ChangePlanPage,
})

function ChangePlanPage() {
  return <ChangePlanWizardFullscreen />
}

import { createFileRoute, redirect } from '@tanstack/react-router'
import { z } from 'zod'
import { RequireAuth } from '@/components/global/auth/RequireAuth'
import { pageTitle } from '@/lib/utils/page-title'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
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
import { GRID_DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import { searchParamsFromRouterLocation } from '@/lib/table-filters'

const upgradeSearchSchema = z.object({
  orgId: z.string().optional(),
  plan: z.string().optional(),
  code: z.string().optional(),
  type: z.string().optional(),
  invites: z.string().optional(),
})

export const Route = createFileRoute('/_public/upgrade')({
  validateSearch: upgradeSearchSchema,
  head: () => ({ meta: [{ title: pageTitle('Upgrade') }] }),
  beforeLoad: () => {
    if (!getActiveProfileFeatures().billing) {
      throw redirect({ to: '/', replace: true })
    }
  },
  loader: async ({ context, location }) => {
    if (typeof window === 'undefined') {
      return
    }

    const { queryClient } = context
    const orgId =
      searchParamsFromRouterLocation(location).get('orgId') ?? undefined

    const sharedPrefetches: Promise<unknown>[] = [
      queryClient.ensureQueryData(organizationsQueryOptions()),
      queryClient.ensureQueryData(billingPlansQueryOptions()),
    ]

    if (orgId) {
      await Promise.all([
        ...sharedPrefetches,
        queryClient.ensureQueryData(organizationPlanQueryOptions(orgId)),
        queryClient.ensureQueryData(organizationQueryOptions(orgId)),
        queryClient.ensureQueryData(
          organizationMembershipsQueryOptions(
            orgId,
            0,
            GRID_DEFAULT_PAGE_SIZE,
            '',
          ),
        ),
      ])

      // Usage / project lists are non-critical for the wizard first paint.
      void queryClient
        .prefetchQuery(organizationUsageQueryOptions(orgId))
        .catch(() => undefined)
      void queryClient
        .prefetchQuery(organizationProjectsQueryOptions(orgId))
        .catch(() => undefined)
      return
    }

    await Promise.all(sharedPrefetches)
  },
  component: UpgradePage,
})

function UpgradePage() {
  return (
    <RequireAuth>
      <div className="fixed inset-0 z-[9997] flex flex-col bg-background">
        <ChangePlanWizardFullscreen />
      </div>
    </RequireAuth>
  )
}

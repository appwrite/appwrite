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
import { prefetchVisitorCountry } from '@/lib/react-query/hooks/locale'
import { ChangePlanWizardFullscreen } from '@/components/pages/organizations/$orgId/billing/ChangePlanWizardFullscreen'
import { GRID_DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import {
  getRawSearchParam,
  searchParamsFromRouterLocation,
} from '@/lib/table-filters'

const upgradeSearchSchema = z.object({
  // Values come from links built outside the Console (emails, other sites). The router
  // JSON-parses search values, so a number-like one (`2024`, `true`) arrives as a number
  // or boolean: coerce it back to its text rather than fail the page.
  orgId: z.coerce.string().optional(),
  plan: z.coerce.string().optional(),
  code: z.coerce.string().optional(),
  type: z.coerce.string().optional(),
  invites: z.coerce.string().optional(),
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
      getRawSearchParam(searchParamsFromRouterLocation(location), 'orgId') ??
      undefined

    const sharedPrefetches: Promise<unknown>[] = [
      queryClient.ensureQueryData(organizationsQueryOptions()),
      queryClient.ensureQueryData(billingPlansQueryOptions()),
      prefetchVisitorCountry(queryClient),
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

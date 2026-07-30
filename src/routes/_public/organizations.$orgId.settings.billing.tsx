import { createFileRoute, redirect } from '@tanstack/react-router'
import { pageTitle } from '@/lib/utils/page-title'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import {
  organizationsQueryOptions,
  organizationInvoicesQueryOptions,
  organizationQueryOptions,
  organizationPlanQueryOptions,
  organizationBillingAggregationQueryOptions,
  organizationCreditsQueryOptions,
  paymentMethodsQueryOptions,
  billingAddressesQueryOptions,
  organizationPaymentMethodQueryOptions,
  billingAddressQueryOptions,
  canSeeOrganizationBilling,
  resolveOrganizationAccess,
} from '@/lib/react-query/hooks'
import {
  DEFAULT_BILLING_PROJECTS_LIMIT,
} from '@/lib/react-query/hooks/constants'

const INVOICES_PER_PAGE = 5
const CREDITS_PER_PAGE = 5

export const Route = createFileRoute(
  '/_public/organizations/$orgId/settings/billing',
)({
  head: () => ({ meta: [{ title: pageTitle('Billing', 'Organization') }] }),
  beforeLoad: ({ params }) => {
    if (!getActiveProfileFeatures().billing) {
      throw redirect({
        to: '/organizations/$orgId/settings',
        params: { orgId: params.orgId },
        replace: true,
      })
    }
  },
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

    // Prefetch organizations list (non-critical, for dropdowns) - doesn't block
    queryClient.prefetchQuery(organizationsQueryOptions()).catch(() => {
      // Don't block on optional data
    })

    // Fetch critical data before rendering to prevent layout shifts
    const [orgData] = await Promise.all([
      queryClient.ensureQueryData(organizationQueryOptions(orgId)),
    ])

    const billingAccess = await resolveOrganizationAccess(queryClient, orgId)
    const canFetchBillingInvoices = canSeeOrganizationBilling(billingAccess)

    await Promise.all([
      queryClient.ensureQueryData(organizationPlanQueryOptions(orgId)),
      ...(canFetchBillingInvoices
        ? [
            queryClient.ensureQueryData(
              organizationInvoicesQueryOptions(orgId, 0, INVOICES_PER_PAGE),
            ),
          ]
        : []),
      queryClient.ensureQueryData(organizationCreditsQueryOptions(orgId, 0, 1)),
      queryClient.ensureQueryData(
        organizationCreditsQueryOptions(orgId, 0, CREDITS_PER_PAGE),
      ),
      queryClient.ensureQueryData(paymentMethodsQueryOptions()),
      queryClient.ensureQueryData(billingAddressesQueryOptions()),
    ])

    // Usage/aggregation is non-critical: PlanSummary shows its own skeleton while
    // this loads, so a slow usage API must not block the rest of billing.
    if (orgData?.billingAggregationId) {
      void queryClient
        .prefetchQuery(
          organizationBillingAggregationQueryOptions(
            orgId,
            orgData.billingAggregationId,
            DEFAULT_BILLING_PROJECTS_LIMIT,
            0,
          ),
        )
        .catch(() => undefined)
    }

    const optionalPrefetches = []
    if (orgData?.paymentMethodId) {
      optionalPrefetches.push(
        queryClient
          .ensureQueryData(
            organizationPaymentMethodQueryOptions(
              orgId,
              orgData.paymentMethodId,
            ),
          )
          .catch(() => {}),
      )
    }
    if (orgData?.backupPaymentMethodId) {
      optionalPrefetches.push(
        queryClient
          .ensureQueryData(
            organizationPaymentMethodQueryOptions(
              orgId,
              orgData.backupPaymentMethodId,
            ),
          )
          .catch(() => {}),
      )
    }
    if (orgData?.billingAddressId) {
      optionalPrefetches.push(
        queryClient
          .ensureQueryData(billingAddressQueryOptions(orgData.billingAddressId))
          .catch(() => {}),
      )
    }
    if (optionalPrefetches.length > 0) {
      await Promise.all(optionalPrefetches)
    }
  },
  component: BillingPage,
})

function BillingPage() {
  return null
}

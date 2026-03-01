import { createFileRoute } from '@tanstack/react-router'
import { pageTitle } from '@/lib/utils/page-title'
import {
  organizationsQueryOptions,
  organizationInvoicesQueryOptions,
  organizationQueryOptions,
  organizationBillingAggregationQueryOptions,
  organizationCreditsQueryOptions,
  paymentMethodsQueryOptions,
  billingAddressesQueryOptions,
  paymentMethodQueryOptions,
  billingAddressQueryOptions,
} from '@/lib/react-query/hooks'

const INVOICES_PER_PAGE = 5
const CREDITS_PER_PAGE = 5
const PROJECTS_PER_PAGE = 10

export const Route = createFileRoute(
  '/_public/organizations/$orgId/settings/billing',
)({
  head: () => ({ meta: [{ title: pageTitle('Billing', 'Organization') }] }),
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

    const aggregationPromise = orgData?.billingAggregationId
      ? queryClient.ensureQueryData(
          organizationBillingAggregationQueryOptions(
            orgId,
            orgData.billingAggregationId,
            PROJECTS_PER_PAGE,
            0,
          ),
        )
      : Promise.resolve(null)

    await Promise.all([
      aggregationPromise,
      queryClient.ensureQueryData(
        organizationInvoicesQueryOptions(orgId, 0, INVOICES_PER_PAGE),
      ),
      queryClient.ensureQueryData(
        organizationCreditsQueryOptions(orgId, 0, 1),
      ),
      queryClient.ensureQueryData(
        organizationCreditsQueryOptions(orgId, 0, CREDITS_PER_PAGE),
      ),
      queryClient.ensureQueryData(paymentMethodsQueryOptions()),
      queryClient.ensureQueryData(billingAddressesQueryOptions()),
    ])

    const optionalPrefetches = []
    if (orgData?.paymentMethodId) {
      optionalPrefetches.push(
        queryClient
          .ensureQueryData(paymentMethodQueryOptions(orgData.paymentMethodId))
          .catch(() => {}),
      )
    }
    if (orgData?.backupPaymentMethodId) {
      optionalPrefetches.push(
        queryClient
          .ensureQueryData(
            paymentMethodQueryOptions(orgData.backupPaymentMethodId),
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

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

export const Route = createFileRoute('/_public/organizations/$orgId/billing')({
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
    // Note: Organization plan is already loaded in parent route's loader
    // Load organization first, then use its data for aggregation

    const [orgData] = await Promise.all([
      // Fetch organization - blocks navigation until ready
      queryClient.ensureQueryData(organizationQueryOptions(orgId)),
    ])

    // Now load aggregation using orgData (must be after orgData is loaded)
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

    // Fetch remaining critical data before rendering - blocks navigation until ready
    await Promise.all([
      aggregationPromise,

      // Fetch first page of invoices - blocks navigation until ready
      // Component uses page 0, limit 5, no queries parameter (undefined)
      queryClient.ensureQueryData(
        organizationInvoicesQueryOptions(orgId, 0, INVOICES_PER_PAGE),
      ),

      // Fetch credits - blocks navigation until ready
      // PlanSummary uses limit 1, AvailableCreditsSection uses limit 5
      // Prefetch both to avoid duplicate calls
      queryClient.ensureQueryData(
        organizationCreditsQueryOptions(orgId, 0, 1), // For PlanSummary
      ),
      queryClient.ensureQueryData(
        organizationCreditsQueryOptions(orgId, 0, CREDITS_PER_PAGE), // For AvailableCreditsSection
      ),

      // Fetch payment methods - blocks navigation until ready
      queryClient.ensureQueryData(paymentMethodsQueryOptions()),

      // Fetch billing addresses - blocks navigation until ready
      queryClient.ensureQueryData(billingAddressesQueryOptions()),
    ])

    // Prefetch optional payment method details and billing address (non-blocking)
    // These are prefetched but errors don't block navigation
    const optionalPrefetches = []
    if (orgData?.paymentMethodId) {
      optionalPrefetches.push(
        queryClient
          .ensureQueryData(paymentMethodQueryOptions(orgData.paymentMethodId))
          .catch(() => {
            // Ignore errors
          }),
      )
    }

    if (orgData?.backupPaymentMethodId) {
      optionalPrefetches.push(
        queryClient
          .ensureQueryData(
            paymentMethodQueryOptions(orgData.backupPaymentMethodId),
          )
          .catch(() => {
            // Ignore errors
          }),
      )
    }

    if (orgData?.billingAddressId) {
      optionalPrefetches.push(
        queryClient
          .ensureQueryData(billingAddressQueryOptions(orgData.billingAddressId))
          .catch(() => {
            // Ignore errors
          }),
      )
    }

    // Wait for optional prefetches to complete (but don't block on errors)
    if (optionalPrefetches.length > 0) {
      await Promise.all(optionalPrefetches)
    }
  },
  component: BillingPage,
})

// This route doesn't need to render anything - parent OrgOverview handles the content
function BillingPage() {
  return null
}

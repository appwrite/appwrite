import { createFileRoute } from '@tanstack/react-router'
import {
  fetchOrganizations,
  fetchOrganizationInvoices,
  fetchOrganizationById,
  fetchOrganizationPlan,
  fetchOrganizationBillingAggregation,
  fetchOrganizationCredits,
  fetchPaymentMethods,
  fetchBillingAddresses,
} from '@/lib/react-query/hooks'

const INVOICES_PER_PAGE = 5
const CREDITS_PER_PAGE = 5
const PROJECTS_PER_PAGE = 10

export const Route = createFileRoute('/_public/organizations/$orgId/billing')({
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
    queryClient.prefetchQuery({
      queryKey: ['organizations', 'console'],
      queryFn: fetchOrganizations,
      staleTime: 5 * 60 * 1000, // 5 minutes
    }).catch(() => {
      // Don't block on optional data
    })

    // Fetch critical data before rendering to prevent layout shifts
    // Note: Organization plan is already loaded in parent route's beforeLoad
    // Load organization first, then use its data for aggregation

    const [orgData] = await Promise.all([
      // Fetch organization - blocks navigation until ready
      queryClient.ensureQueryData({
        queryKey: ['organization', orgId],
        queryFn: () => fetchOrganizationById(orgId),
        staleTime: 5 * 60 * 1000, // 5 minutes
      }),
    ])

    // Now load aggregation using orgData (must be after orgData is loaded)
    const aggregationPromise = orgData?.billingAggregationId
      ? queryClient.ensureQueryData({
          queryKey: [
            'billing-aggregation',
            'organization',
            orgId,
            orgData.billingAggregationId,
            PROJECTS_PER_PAGE,
            0,
          ],
          queryFn: () =>
            fetchOrganizationBillingAggregation(
              orgId,
              orgData.billingAggregationId,
              PROJECTS_PER_PAGE,
              0,
            ),
          staleTime: 30 * 1000, // 30 seconds
        })
      : Promise.resolve(null)

    // Fetch remaining critical data before rendering - blocks navigation until ready
    await Promise.all([
      aggregationPromise,

      // Fetch first page of invoices - blocks navigation until ready
      queryClient.ensureQueryData({
        queryKey: [
          'invoices',
          'organization',
          orgId,
          0,
          INVOICES_PER_PAGE,
          null,
        ],
        queryFn: () => fetchOrganizationInvoices(orgId, 0, INVOICES_PER_PAGE),
        staleTime: 30 * 1000, // 30 seconds
      }),

      // Fetch first page of credits - blocks navigation until ready
      queryClient.ensureQueryData({
        queryKey: ['credits', 'organization', orgId, 0, CREDITS_PER_PAGE],
        queryFn: () => fetchOrganizationCredits(orgId, 0, CREDITS_PER_PAGE),
        staleTime: 30 * 1000, // 30 seconds
      }),

      // Fetch payment methods - blocks navigation until ready
      queryClient.ensureQueryData({
        queryKey: ['payment-methods', 'account'],
        queryFn: fetchPaymentMethods,
        staleTime: 5 * 60 * 1000, // 5 minutes
      }),

      // Fetch billing addresses - blocks navigation until ready
      queryClient.ensureQueryData({
        queryKey: ['billing-addresses', 'account'],
        queryFn: fetchBillingAddresses,
        staleTime: 5 * 60 * 1000, // 5 minutes
      }),
    ])

    // Prefetch optional payment method details (non-blocking)
    if (orgData?.paymentMethodId) {
      queryClient.prefetchQuery({
        queryKey: ['payment-method', orgData.paymentMethodId],
        queryFn: async () => {
          const { fetchPaymentMethod } = await import('@/lib/react-query/hooks')
          return fetchPaymentMethod(orgData.paymentMethodId)
        },
        staleTime: 5 * 60 * 1000,
      }).catch(() => {
        // Ignore errors
      })
    }

    if (orgData?.backupPaymentMethodId) {
      queryClient.prefetchQuery({
        queryKey: ['payment-method', orgData.backupPaymentMethodId],
        queryFn: async () => {
          const { fetchPaymentMethod } = await import('@/lib/react-query/hooks')
          return fetchPaymentMethod(orgData.backupPaymentMethodId)
        },
        staleTime: 5 * 60 * 1000,
      }).catch(() => {
        // Ignore errors
      })
    }

    if (orgData?.billingAddressId) {
      queryClient.prefetchQuery({
        queryKey: ['billing-address', orgData.billingAddressId],
        queryFn: async () => {
          const { fetchBillingAddress } = await import('@/lib/react-query/hooks')
          return fetchBillingAddress(orgData.billingAddressId)
        },
        staleTime: 5 * 60 * 1000,
      }).catch(() => {
        // Ignore errors
      })
    }
  },
  component: BillingPage,
})

// This route doesn't need to render anything - parent OrgOverview handles the content
function BillingPage() {
  return null
}

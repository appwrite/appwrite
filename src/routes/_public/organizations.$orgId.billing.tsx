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

    // Prefetch all billing-related data in parallel
    await Promise.all([
      // Organizations list
      queryClient.prefetchQuery({
        queryKey: ['organizations', 'console'],
        queryFn: fetchOrganizations,
        staleTime: 5 * 60 * 1000, // 5 minutes
      }),

      // Organization details
      queryClient.ensureQueryData({
        queryKey: ['organization', orgId],
        queryFn: () => fetchOrganizationById(orgId),
        staleTime: 5 * 60 * 1000, // 5 minutes
      }),

      // Organization plan
      queryClient.prefetchQuery({
        queryKey: ['organization', 'plan', orgId],
        queryFn: () => fetchOrganizationPlan(orgId),
        staleTime: 5 * 60 * 1000, // 5 minutes
      }),

      // Organization details (needed to get billingAggregationId)
      // This is already fetched above, but we need it before fetching aggregation

      // Invoices (first page)
      queryClient.ensureQueryData({
        queryKey: ['invoices', 'organization', orgId, 0, INVOICES_PER_PAGE, null],
        queryFn: () => fetchOrganizationInvoices(orgId, 0, INVOICES_PER_PAGE),
        staleTime: 30 * 1000, // 30 seconds
      }),

      // Credits (first page)
      queryClient.prefetchQuery({
        queryKey: ['credits', 'organization', orgId, 0, CREDITS_PER_PAGE],
        queryFn: () => fetchOrganizationCredits(orgId, 0, CREDITS_PER_PAGE),
        staleTime: 30 * 1000, // 30 seconds
      }),

      // Payment methods (for account)
      queryClient.prefetchQuery({
        queryKey: ['payment-methods', 'account'],
        queryFn: fetchPaymentMethods,
        staleTime: 5 * 60 * 1000, // 5 minutes
      }),

      // Billing addresses (for account)
      queryClient.prefetchQuery({
        queryKey: ['billing-addresses', 'account'],
        queryFn: fetchBillingAddresses,
        staleTime: 5 * 60 * 1000, // 5 minutes
      }),
    ])

    // After organization is loaded, prefetch aggregation and related data
    const orgData = await queryClient.fetchQuery({
      queryKey: ['organization', orgId],
      queryFn: () => fetchOrganizationById(orgId),
    })

    if (orgData) {
      const prefetchPromises = []

      // Prefetch billing aggregation if aggregationId exists
      if (orgData.billingAggregationId) {
        prefetchPromises.push(
          queryClient.prefetchQuery({
            queryKey: ['billing-aggregation', 'organization', orgId, orgData.billingAggregationId, PROJECTS_PER_PAGE, 0],
            queryFn: () => fetchOrganizationBillingAggregation(orgId, orgData.billingAggregationId, PROJECTS_PER_PAGE, 0),
            staleTime: 30 * 1000, // 30 seconds
          }).catch(() => {
            // Ignore 404 errors for new organizations
          }),
        )
      }

      // Prefetch primary payment method if exists
      if (orgData.paymentMethodId) {
        prefetchPromises.push(
          queryClient.prefetchQuery({
            queryKey: ['payment-method', orgData.paymentMethodId],
            queryFn: async () => {
              const { fetchPaymentMethod } = await import('@/lib/react-query/hooks')
              return fetchPaymentMethod(orgData.paymentMethodId)
            },
            staleTime: 5 * 60 * 1000,
          }).catch(() => {
            // Ignore errors
          }),
        )
      }

      // Prefetch backup payment method if exists
      if (orgData.backupPaymentMethodId) {
        prefetchPromises.push(
          queryClient.prefetchQuery({
            queryKey: ['payment-method', orgData.backupPaymentMethodId],
            queryFn: async () => {
              const { fetchPaymentMethod } = await import('@/lib/react-query/hooks')
              return fetchPaymentMethod(orgData.backupPaymentMethodId)
            },
            staleTime: 5 * 60 * 1000,
          }).catch(() => {
            // Ignore errors
          }),
        )
      }

      // Prefetch billing address if exists
      if (orgData.billingAddressId) {
        prefetchPromises.push(
          queryClient.prefetchQuery({
            queryKey: ['billing-address', orgData.billingAddressId],
            queryFn: async () => {
              const { fetchBillingAddress } = await import('@/lib/react-query/hooks')
              return fetchBillingAddress(orgData.billingAddressId)
            },
            staleTime: 5 * 60 * 1000,
          }).catch(() => {
            // Ignore errors
          }),
        )
      }

      await Promise.all(prefetchPromises)
    }
  },
  component: BillingPage,
})

// This route doesn't need to render anything - parent OrgOverview handles the content
function BillingPage() {
  return null
}

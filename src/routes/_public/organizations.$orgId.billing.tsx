import { createFileRoute } from '@tanstack/react-router'
import { fetchOrganizations, fetchOrganizationInvoices } from '@/lib/react-query/hooks'

const INVOICES_PER_PAGE = 5

export const Route = createFileRoute('/_public/organizations/$orgId/billing')({
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

    // Prefetch invoices if orgId is available (sorted newest to old, first page)
    // Use ensureQueryData to ensure data is loaded before component renders (prevents layout shift)
    if (orgId) {
      await queryClient.ensureQueryData({
        queryKey: ['invoices', 'organization', orgId, 0, INVOICES_PER_PAGE, null],
        queryFn: () => fetchOrganizationInvoices(orgId, 0, INVOICES_PER_PAGE),
        staleTime: 30 * 1000, // 30 seconds
      })
    }
  },
  component: BillingPage,
})

// This route doesn't need to render anything - parent OrgOverview handles the content
function BillingPage() {
  return null
}

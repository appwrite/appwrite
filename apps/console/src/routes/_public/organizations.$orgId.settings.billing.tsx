import { createFileRoute, redirect } from '@tanstack/react-router'
import { pageTitle } from '@/lib/utils/page-title'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import { prefetchOrganizationBillingTab } from '@/lib/react-query/hooks'

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

    await prefetchOrganizationBillingTab(queryClient, orgId)
  },
  component: BillingPage,
})

function BillingPage() {
  return null
}

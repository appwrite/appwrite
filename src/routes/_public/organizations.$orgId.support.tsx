import { createFileRoute, redirect } from '@tanstack/react-router'
import {
  organizationPlanQueryOptions,
  organizationProjectsQueryOptions,
} from '@/lib/react-query/hooks'
import { SupportWizardFullscreen } from '@/components/pages/organizations/$orgId/support/SupportWizardFullscreen'
import { pageTitle } from '@/lib/utils/page-title'
import { getActiveProfileFeatures } from '@/lib/console-profiles'

export const Route = createFileRoute('/_public/organizations/$orgId/support')({
  head: () => ({ meta: [{ title: pageTitle('Support', 'Organization') }] }),
  beforeLoad: ({ params }) => {
    if (!getActiveProfileFeatures().billing) {
      throw redirect({
        to: '/organizations/$orgId',
        params: { orgId: params.orgId },
        replace: true,
      })
    }
  },
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') {
      return
    }

    const { orgId } = params
    const { queryClient } = context

    if (!orgId) {
      return
    }

    // Fetch organization plan first - required for premium support check
    const plan = await queryClient.ensureQueryData(
      organizationPlanQueryOptions(orgId),
    )

    // Support page is only accessible for plans with premium support (same check as header popover)
    if (!plan?.premiumSupport) {
      throw redirect({
        to: '/organizations/$orgId',
        params: { orgId },
      })
    }

    // Prefetch projects for the form project selector
    await queryClient
      .ensureQueryData(organizationProjectsQueryOptions(orgId))
      .catch(() => {})
  },
  component: SupportPage,
})

function SupportPage() {
  return <SupportWizardFullscreen />
}

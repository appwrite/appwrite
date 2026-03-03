import { createFileRoute, redirect } from '@tanstack/react-router'
import { UsageView } from '@/components/pages/projects/$projectId/usage/View'
import { pageTitle } from '@/lib/utils/page-title'
import { getActiveProfileFeatures } from '@/lib/console-profiles'

export const Route = createFileRoute('/_public/projects/$projectId/usage')({
  head: () => ({ meta: [{ title: pageTitle('Usage') }] }),
  beforeLoad: ({ params }) => {
    if (!getActiveProfileFeatures().usageStats) {
      throw redirect({
        to: '/projects/$projectId',
        params: { projectId: params.projectId },
        replace: true,
      })
    }
  },
  component: UsagePage,
})

function UsagePage() {
  // In a real app, you'd get the plan from the organization context
  return <UsageView plan="pro" />
}

import { createFileRoute, redirect } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/activity/View'
import { pageTitle } from '@/lib/utils/page-title'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import {
  ACTIVITY_DEFAULT_PAGE_SIZE,
  activitiesQueryOptions,
} from '@/lib/react-query/hooks'

// Mirror the Pro plan retention window (`PLAN_TIME_LIMITS.pro` in View.tsx)
// so the route loader prefetches the same query the View renders.
const PRO_PLAN_HOURS = 7 * 24

export const Route = createFileRoute('/_public/projects/$projectId/activity')({
  head: () => ({ meta: [{ title: pageTitle('Activity') }] }),
  beforeLoad: ({ params }) => {
    if (!getActiveProfileFeatures().activity) {
      throw redirect({
        to: '/projects/$projectId',
        params: { projectId: params.projectId },
        replace: true,
      })
    }
  },
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return
    const { projectId } = params
    const { queryClient } = context
    if (!projectId) return

    const since = new Date(
      Date.now() - PRO_PLAN_HOURS * 60 * 60 * 1000,
    ).toISOString()

    await queryClient
      .ensureQueryData(
        activitiesQueryOptions({
          projectId,
          page: 0,
          limit: ACTIVITY_DEFAULT_PAGE_SIZE,
          since,
        }),
      )
      .catch(() => {
        // Activity is non-critical; don't block navigation on errors.
      })
  },
  component: ActivityPage,
})

function ActivityPage() {
  const { projectId } = Route.useParams()
  // In a real app, you'd get the plan from the organization context.
  // For now, we'll use 'pro' as the default.
  return <View projectId={projectId} plan="pro" />
}

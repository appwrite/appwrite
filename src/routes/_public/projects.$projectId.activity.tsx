import { createFileRoute, redirect } from '@tanstack/react-router'
import { z } from 'zod'
import { View } from '@/components/pages/projects/$projectId/activity/View'
import { pageTitle } from '@/lib/utils/page-title'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import {
  ACTIVITY_DEFAULT_PAGE_SIZE,
  activitiesQueryOptions,
  countriesQueryOptions,
} from '@/lib/react-query/hooks'
import { getQueryParam } from '@/lib/table-filters'

const activitySearchSchema = z.object({
  /** Activity event `$id` — opens the detail drawer when valid. */
  event: z.string().optional().catch(undefined),
  /** Encoded table filters (same contract as other list views). */
  query: z.string().optional().catch(undefined),
})

// Mirror the Pro plan retention window (`PLAN_TIME_LIMITS.pro` in View.tsx)
// so the route loader prefetches the same query the View renders.
const PRO_PLAN_HOURS = 30 * 24

export const Route = createFileRoute('/_public/projects/$projectId/activity')({
  head: () => ({ meta: [{ title: pageTitle('Activity') }] }),
  validateSearch: activitySearchSchema,
  beforeLoad: ({ params }) => {
    if (!getActiveProfileFeatures().activity) {
      throw redirect({
        to: '/projects/$projectId',
        params: { projectId: params.projectId },
        replace: true,
      })
    }
  },
  loader: async ({ params, context, location }) => {
    if (typeof window === 'undefined') return
    const { projectId } = params
    const { queryClient } = context
    if (!projectId) return

    const url = new URL(location.pathname + location.search, 'http://localhost')
    const queryParam = getQueryParam(url)
    const planSinceIso = new Date(
      Date.now() - PRO_PLAN_HOURS * 60 * 60 * 1000,
    ).toISOString()

    await Promise.all([
      queryClient.ensureQueryData(countriesQueryOptions()).catch(() => undefined),
      queryClient
        .ensureQueryData(
          activitiesQueryOptions({
            projectId,
            limit: ACTIVITY_DEFAULT_PAGE_SIZE,
            cursorAfter: null,
            cursorBefore: null,
            planSinceIso,
            filterQueryKey: queryParam,
          }),
        )
        .catch(() => undefined),
    ])
  },
  component: ActivityPage,
})

function ActivityPage() {
  const { projectId } = Route.useParams()
  // In a real app, you'd get the plan from the organization context.
  // For now, we'll use 'pro' as the default.
  return <View projectId={projectId} plan="pro" />
}

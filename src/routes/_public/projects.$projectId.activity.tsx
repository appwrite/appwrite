import { createFileRoute, redirect } from '@tanstack/react-router'
import { z } from 'zod'
import { View } from '@/components/pages/projects/$projectId/activity/View'
import { pageTitle } from '@/lib/utils/page-title'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import { canAccessProjectActivity } from '@/lib/console-rbac-loader'
import { getActivityLogRetentionHoursFromPlan } from '@/lib/activity/activity-log-retention'
import {
  activitiesQueryOptions,
  countriesQueryOptions,
  organizationPlanQueryOptions,
  projectQueryOptions,
} from '@/lib/react-query/hooks'
import { ACTIVITY_DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'

const activitySearchSchema = z.object({
  /** Activity event `$id` - opens the detail drawer when valid. */
  event: z.string().optional().catch(undefined),
  /** Encoded table filters (same contract as other list views). */
  query: z.string().optional().catch(undefined),
})

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
  loaderDeps: ({ search }) => ({ query: search?.query }),
  loader: async ({ params, context, deps }) => {
    if (typeof window === 'undefined') return undefined
    const { projectId } = params
    const { queryClient } = context
    if (!projectId) return undefined

    const canAccess = await canAccessProjectActivity(queryClient, projectId)
    if (!canAccess) {
      throw redirect({
        to: '/projects/$projectId',
        params: { projectId },
        replace: true,
      })
    }

    const project = await queryClient.ensureQueryData(
      projectQueryOptions(projectId),
    )
    const plan = project?.teamId
      ? await queryClient
          .ensureQueryData(organizationPlanQueryOptions(project.teamId))
          .catch(() => undefined)
      : undefined
    const planRetentionHours = getActivityLogRetentionHoursFromPlan(plan)
    const filterQueryKey = deps?.query ?? null

    const activitiesOptions = activitiesQueryOptions({
      projectId,
      limit: ACTIVITY_DEFAULT_PAGE_SIZE,
      cursorAfter: null,
      cursorBefore: null,
      planRetentionHours,
      filterQueryKey,
    })

    // Activity events come from ClickHouse. Never block or fail this route if
    // that store is down, slow, or returns a retention/history error. The View
    // still fetches and shows a retryable error state.
    const activities = await Promise.race([
      queryClient.ensureQueryData(activitiesOptions).catch(() => undefined),
      new Promise<undefined>((resolve) => {
        window.setTimeout(() => resolve(undefined), 4000)
      }),
    ])

    void queryClient
      .ensureQueryData(countriesQueryOptions())
      .catch(() => undefined)

    return { activities }
  },
  component: ActivityPage,
})

function ActivityPage() {
  const { projectId } = Route.useParams()
  const loaderData = Route.useLoaderData()
  return (
    <View
      key={`activity-${projectId}`}
      projectId={projectId}
      initialData={loaderData?.activities}
    />
  )
}

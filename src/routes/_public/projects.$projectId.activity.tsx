import { createFileRoute, redirect } from '@tanstack/react-router'
import { z } from 'zod'
import { View } from '@/components/pages/projects/$projectId/activity/View'
import { pageTitle } from '@/lib/utils/page-title'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import { countriesQueryOptions } from '@/lib/react-query/hooks'

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
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return
    const { projectId } = params
    const { queryClient } = context
    if (!projectId) return

    // Activity list uses an in-table skeleton; fetch runs once from `useProjectActivities`.
    // Prefetch only filter UI data (country enum for filters).
    await queryClient
      .ensureQueryData(countriesQueryOptions())
      .catch(() => undefined)
  },
  component: ActivityPage,
})

function ActivityPage() {
  const { projectId } = Route.useParams()
  return <View projectId={projectId} />
}

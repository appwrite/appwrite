import {
  createFileRoute,
  Outlet,
  redirect,
  useMatches,
} from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/analytics/View'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import { pageTitle } from '@/lib/utils/page-title'
import {
  analyticsPropertiesQueryOptions,
  analyticsStatsQueryOptions,
  DEFAULT_ANALYTICS_DATE_RANGE,
  fetchProject,
} from '@/lib/react-query/hooks'

export const Route = createFileRoute('/_public/projects/$projectId/analytics')({
  head: () => ({ meta: [{ title: pageTitle('Analytics') }] }),
  beforeLoad: ({ params }) => {
    if (!getActiveProfileFeatures().analytics) {
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

    // Project first so the SDK has the project's region cached.
    await queryClient.ensureQueryData({
      queryKey: ['project', projectId],
      queryFn: () => fetchProject(projectId),
      staleTime: 5 * 60 * 1000,
    })

    const propertiesData = await queryClient
      .ensureQueryData(analyticsPropertiesQueryOptions(projectId))
      .catch(() => undefined)

    // Stats are fetched per property (listProperties does not embed metrics).
    // Prefetch them all so the list renders without layout shift; a failing
    // property must not block the list.
    await Promise.all(
      (propertiesData?.properties ?? []).map((property) =>
        queryClient
          .ensureQueryData(
            analyticsStatsQueryOptions(
              projectId,
              property.$id,
              DEFAULT_ANALYTICS_DATE_RANGE,
            ),
          )
          .catch(() => undefined),
      ),
    )
  },
  component: AnalyticsPage,
})

function AnalyticsPage() {
  const matches = useMatches()
  // Check if we're on a child route (property detail page)
  const isChildRoute = matches.some(
    (match) =>
      match.routeId === '/_public/projects/$projectId/analytics/$propertyId',
  )

  if (isChildRoute) {
    return <Outlet />
  }

  return <View />
}

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
  getDefaultAnalyticsRange,
  DEFAULT_PAGE_SIZE,
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

    // Same page/limit/search defaults as the View so the query keys match.
    const propertiesData = await queryClient
      .ensureQueryData(
        analyticsPropertiesQueryOptions(projectId, 0, DEFAULT_PAGE_SIZE, ''),
      )
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
              getDefaultAnalyticsRange(),
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

  // The add wizard renders fullscreen, so it needs a flex container of its own.
  const isAddRoute = matches.some(
    (match) => match.routeId === '/_public/projects/$projectId/analytics/add',
  )
  if (isAddRoute) {
    return (
      <div className="flex h-full min-h-0 flex-1 flex-col">
        <Outlet />
      </div>
    )
  }

  // Property pages: the layout and its tabs (Analytics, Settings).
  const isChildRoute = matches.some((match) =>
    match.routeId.startsWith('/_public/projects/$projectId/analytics/$propertyId'),
  )
  if (isChildRoute) {
    return <Outlet />
  }

  return <View />
}

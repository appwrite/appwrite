import { createFileRoute, useNavigate } from '@tanstack/react-router'
import { View } from '@/components/pages/projects/$projectId/analytics/$propertyId/View'
import { pageTitle } from '@/lib/utils/page-title'
import {
  ANALYTICS_PAGEVIEW_EVENT,
  analyticsEventMetricsQueryOptions,
  analyticsEventsQueryOptions,
  analyticsPropertyQueryOptions,
  analyticsStatsQueryOptions,
  DEFAULT_ANALYTICS_RANGE,
  fetchProject,
} from '@/lib/react-query/hooks'

export const Route = createFileRoute(
  '/_public/projects/$projectId/analytics/$propertyId',
)({
  head: () => ({ meta: [{ title: pageTitle('Property', 'Analytics') }] }),
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return undefined

    const { projectId, propertyId } = params
    const { queryClient } = context
    if (!projectId || !propertyId) return undefined

    await queryClient.ensureQueryData({
      queryKey: ['project', projectId],
      queryFn: () => fetchProject(projectId),
      staleTime: 5 * 60 * 1000,
    })

    // Property is required for the first paint; the metric payloads are
    // optional so a property with no data still renders its detail page.
    const [property, stats, events, series] = await Promise.all([
      queryClient
        .ensureQueryData(analyticsPropertyQueryOptions(projectId, propertyId))
        .catch(() => undefined),
      queryClient
        .ensureQueryData(
          analyticsStatsQueryOptions(
            projectId,
            propertyId,
            DEFAULT_ANALYTICS_RANGE,
          ),
        )
        .catch(() => undefined),
      queryClient
        .ensureQueryData(
          analyticsEventsQueryOptions(
            projectId,
            propertyId,
            DEFAULT_ANALYTICS_RANGE,
          ),
        )
        .catch(() => undefined),
      queryClient
        .ensureQueryData(
          analyticsEventMetricsQueryOptions(
            projectId,
            propertyId,
            ANALYTICS_PAGEVIEW_EVENT,
            DEFAULT_ANALYTICS_RANGE,
          ),
        )
        .catch(() => undefined),
    ])

    if (!property) return undefined

    return { property, stats, events, series }
  },
  component: PropertyAnalyticsPage,
})

function PropertyAnalyticsPage() {
  const { projectId, propertyId } = Route.useParams()
  const loaderData = Route.useLoaderData()
  const navigate = useNavigate()

  const handleBack = () => {
    navigate({ to: '/projects/$projectId/analytics', params: { projectId } })
  }

  return (
    <View
      key={`analytics-property-${propertyId}`}
      projectId={projectId}
      propertyId={propertyId}
      onBack={handleBack}
      initialData={loaderData}
    />
  )
}

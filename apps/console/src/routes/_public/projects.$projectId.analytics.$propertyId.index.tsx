import { createFileRoute, useNavigate } from '@tanstack/react-router'
import { AnalyticsDimension } from '@appwrite.io/console'
import { View } from '@/components/pages/projects/$projectId/analytics/$propertyId/View'
import {
  analyticsBreakdownQueryOptions,
  analyticsEventMetricsQueryOptions,
  analyticsEventsQueryOptions,
  analyticsPropertyQueryOptions,
  analyticsStatsQueryOptions,
  getDefaultAnalyticsRange,
  getPreviousAnalyticsRange,
  toAnalyticsRange,
} from '@/lib/react-query/hooks'
import { getConsoleAccountFromCache } from '@/lib/react-query/hooks/auth'
import { resolveAnalyticsChartSelection } from '@/lib/analytics/chart-prefs'
import { isUsageChartIntervalTooFineForRange } from '@/lib/usage/chart-interval'
import type { UserPrefs } from '@/lib/user-prefs-keys'

/**
 * The property's Analytics tab. The parent layout route has already loaded
 * the project and the property; this loader adds the metrics for the first
 * paint, so the Settings tab never pays for them.
 */
export const Route = createFileRoute(
  '/_public/projects/$projectId/analytics/$propertyId/',
)({
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return undefined

    const { projectId, propertyId } = params
    const { queryClient } = context
    if (!projectId || !propertyId) return undefined

    // Prefetch the range the page will open with: the user's saved analytics
    // range and interval (account prefs), or the default.
    const prefs = getConsoleAccountFromCache(queryClient)?.prefs as
      | UserPrefs
      | undefined
    let selection = resolveAnalyticsChartSelection(prefs)
    // "All time" starts at the property's creation date, so only then is the
    // property needed before the range is known (same anchor as the View).
    if (selection.presetId === 'all') {
      const property = await queryClient
        .ensureQueryData(analyticsPropertyQueryOptions(projectId, propertyId))
        .catch(() => undefined)
      if (property) {
        selection = resolveAnalyticsChartSelection(prefs, {
          since: new Date(property.$createdAt),
        })
      }
    }
    const defaultRange =
      toAnalyticsRange(selection.dateRange) ?? getDefaultAnalyticsRange()
    const interval =
      selection.interval === '1h' &&
      isUsageChartIntervalTooFineForRange('1h', selection.dateRange)
        ? '1d'
        : selection.interval
    const previousRange = getPreviousAnalyticsRange(defaultRange)

    // Property is required for the first paint; the metric payloads are
    // optional so a property with no data still renders its detail page.
    // Previous-period reads back the "vs previous period" deltas and are
    // fire-and-forget (prefetchQuery never throws): they never hold up paint.
    void queryClient.prefetchQuery(
      analyticsStatsQueryOptions(projectId, propertyId, previousRange),
    )
    // Property-wide series (all events), matching the chart's default.
    void queryClient.prefetchQuery(
      analyticsEventMetricsQueryOptions(
        projectId,
        propertyId,
        null,
        previousRange,
        interval,
      ),
    )

    const [property, stats, events, series] = await Promise.all([
      queryClient
        .ensureQueryData(analyticsPropertyQueryOptions(projectId, propertyId))
        .catch(() => undefined),
      queryClient
        .ensureQueryData(
          analyticsStatsQueryOptions(projectId, propertyId, defaultRange),
        )
        .catch(() => undefined),
      queryClient
        .ensureQueryData(
          analyticsEventsQueryOptions(projectId, propertyId, defaultRange),
        )
        .catch(() => undefined),
      queryClient
        .ensureQueryData(
          analyticsEventMetricsQueryOptions(
            projectId,
            propertyId,
            null,
            defaultRange,
            interval,
          ),
        )
        .catch(() => undefined),
    ])

    // Only dimensions visible above the fold are prefetched: the humans vs
    // bots bar (traffic type, bot category, bot name) and the first panel row
    // (channels, pages). The rest load when their panel's tab is first shown.
    // Prefetching all 21 would mean 21 requests before first paint.
    await Promise.all(
      [
        AnalyticsDimension.TrafficType,
        AnalyticsDimension.BotCategory,
        AnalyticsDimension.BotName,
        AnalyticsDimension.Channel,
        AnalyticsDimension.Page,
      ].map((dimension) =>
        queryClient
          .ensureQueryData(
            analyticsBreakdownQueryOptions(
              projectId,
              propertyId,
              dimension,
              defaultRange,
            ),
          )
          .catch(() => undefined),
      ),
    )

    if (!property) return undefined

    return { property, stats, events, series }
  },
  component: PropertyAnalyticsPage,
})

function PropertyAnalyticsPage() {
  const { projectId, propertyId } = Route.useParams()
  const { query } = Route.useSearch()
  const loaderData = Route.useLoaderData()
  const navigate = useNavigate()

  const handleBack = () => {
    navigate({ to: '/projects/$projectId/analytics', params: { projectId } })
  }

  // Filters live in `?query=` (same contract as Usage) so a filtered view can
  // be shared or bookmarked. `replace` keeps filter tweaks out of history.
  const handleFilterQueryChange = (next: string | undefined) => {
    navigate({
      to: '/projects/$projectId/analytics/$propertyId',
      params: { projectId, propertyId },
      search: next ? { query: next } : {},
      replace: true,
    })
  }

  return (
    <View
      key={`analytics-property-${propertyId}`}
      projectId={projectId}
      propertyId={propertyId}
      onBack={handleBack}
      initialData={loaderData}
      filterQuery={query}
      onFilterQueryChange={handleFilterQueryChange}
    />
  )
}

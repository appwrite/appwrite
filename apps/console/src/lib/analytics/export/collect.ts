import type { QueryClient } from '@tanstack/react-query'
import { AnalyticsDimension, type Models } from '@appwrite.io/console'
import {
  analyticsBreakdownQueryOptions,
  analyticsEventMetricsQueryOptions,
  analyticsStatsQueryOptions,
  type AnalyticsChartInterval,
  type AnalyticsRange,
} from '@/lib/react-query/hooks'
import {
  isAnalyticsFilterShapeSupported,
  type AnalyticsFilter,
} from '@/lib/analytics/analytics-filters'
import {
  buildAnalyticsChartPoints,
  type AnalyticsChartPoint,
} from '@/components/pages/projects/$projectId/analytics/_components/chart-series'

/** Rows per dimension in an export (cards show 8, the modal pages up). */
export const EXPORT_BREAKDOWN_LIMIT = 100

/** Every dimension the API breaks down by, grouped for the report. */
export const EXPORT_DIMENSIONS: {
  dimension: AnalyticsDimension
  label: string
  group: 'Acquisition' | 'Content' | 'Audience' | 'Technology' | 'Automation' | 'Events'
}[] = [
  { dimension: AnalyticsDimension.Channel, label: 'Channels', group: 'Acquisition' },
  { dimension: AnalyticsDimension.ReferrerSource, label: 'Sources', group: 'Acquisition' },
  { dimension: AnalyticsDimension.UtmCampaign, label: 'UTM campaigns', group: 'Acquisition' },
  { dimension: AnalyticsDimension.UtmSource, label: 'UTM sources', group: 'Acquisition' },
  { dimension: AnalyticsDimension.UtmMedium, label: 'UTM mediums', group: 'Acquisition' },
  { dimension: AnalyticsDimension.UtmContent, label: 'UTM content', group: 'Acquisition' },
  { dimension: AnalyticsDimension.UtmTerm, label: 'UTM terms', group: 'Acquisition' },
  { dimension: AnalyticsDimension.Page, label: 'Top pages', group: 'Content' },
  { dimension: AnalyticsDimension.EntryPage, label: 'Entry pages', group: 'Content' },
  { dimension: AnalyticsDimension.ExitPage, label: 'Exit pages', group: 'Content' },
  { dimension: AnalyticsDimension.Hostname, label: 'Hostnames', group: 'Content' },
  { dimension: AnalyticsDimension.Country, label: 'Countries', group: 'Audience' },
  { dimension: AnalyticsDimension.Region, label: 'Regions', group: 'Audience' },
  { dimension: AnalyticsDimension.City, label: 'Cities', group: 'Audience' },
  { dimension: AnalyticsDimension.Browser, label: 'Browsers', group: 'Technology' },
  { dimension: AnalyticsDimension.OperatingSystem, label: 'Operating systems', group: 'Technology' },
  { dimension: AnalyticsDimension.Device, label: 'Devices', group: 'Technology' },
  { dimension: AnalyticsDimension.ScreenSize, label: 'Screen sizes', group: 'Technology' },
  { dimension: AnalyticsDimension.TrafficType, label: 'Traffic type', group: 'Automation' },
  { dimension: AnalyticsDimension.BotCategory, label: 'Bot categories', group: 'Automation' },
  { dimension: AnalyticsDimension.BotName, label: 'Bots', group: 'Automation' },
  { dimension: AnalyticsDimension.EventName, label: 'Events', group: 'Events' },
]

export type ExportBreakdown = {
  dimension: AnalyticsDimension
  label: string
  group: string
  rows: Models.AnalyticsMetric[]
  /** Not exportable with the active page / event filter, or the read failed. */
  skipped?: 'unsupported' | 'error'
}

export type AnalyticsExportData = {
  property: Models.AnalyticsProperty
  range: AnalyticsRange
  interval: AnalyticsChartInterval
  comparisonRange: AnalyticsRange | null
  compareLabel: string | null
  /** Human-readable filter chips, e.g. "Country is United States". */
  filterLabels: string[]
  stats: Models.AnalyticsMetric | null
  comparisonStats: Models.AnalyticsMetric | null
  series: AnalyticsChartPoint[]
  comparisonSeries: AnalyticsChartPoint[]
  breakdowns: ExportBreakdown[]
  generatedAt: Date
}

/** Run async tasks with a concurrency cap so an export never floods the API. */
async function mapWithConcurrency<T, R>(
  items: readonly T[],
  limit: number,
  task: (item: T) => Promise<R>,
): Promise<R[]> {
  const results = new Array<R>(items.length)
  let next = 0
  const workers = Array.from({ length: Math.min(limit, items.length) }, async () => {
    while (next < items.length) {
      const index = next++
      results[index] = await task(items[index])
    }
  })
  await Promise.all(workers)
  return results
}

export async function collectAnalyticsExport(
  queryClient: QueryClient,
  options: {
    projectId: string
    property: Models.AnalyticsProperty
    range: AnalyticsRange
    interval: AnalyticsChartInterval
    comparisonRange: AnalyticsRange | null
    compareLabel: string | null
    filters: readonly AnalyticsFilter[]
    filterLabels: string[]
    onProgress?: (done: number, total: number) => void
  },
): Promise<AnalyticsExportData> {
  const {
    projectId,
    property,
    range,
    interval,
    comparisonRange,
    filters,
  } = options
  const propertyId = property.$id
  const flatSupported = isAnalyticsFilterShapeSupported(filters, { kind: 'flat' })

  const total = EXPORT_DIMENSIONS.length + 4
  let done = 0
  const tick = () => options.onProgress?.(++done, total)

  // `fetchQuery` honours staleTime, so anything already on screen is reused.
  const safe = async <T,>(promise: Promise<T>): Promise<T | null> => {
    try {
      return await promise
    } catch {
      return null
    } finally {
      tick()
    }
  }

  const [stats, comparisonStats, seriesData, comparisonSeriesData] =
    await Promise.all([
      flatSupported
        ? safe(queryClient.fetchQuery(analyticsStatsQueryOptions(projectId, propertyId, range, filters)))
        : (tick(), null),
      flatSupported && comparisonRange
        ? safe(queryClient.fetchQuery(analyticsStatsQueryOptions(projectId, propertyId, comparisonRange, filters)))
        : (tick(), null),
      safe(
        queryClient.fetchQuery(
          analyticsEventMetricsQueryOptions(projectId, propertyId, null, range, interval, filters),
        ),
      ),
      comparisonRange
        ? safe(
            queryClient.fetchQuery(
              analyticsEventMetricsQueryOptions(
                projectId,
                propertyId,
                null,
                comparisonRange,
                interval,
                filters,
              ),
            ),
          )
        : (tick(), null),
    ])

  const breakdowns = await mapWithConcurrency(
    EXPORT_DIMENSIONS,
    4,
    async ({ dimension, label, group }): Promise<ExportBreakdown> => {
      if (!isAnalyticsFilterShapeSupported(filters, { kind: 'breakdown', dimension })) {
        tick()
        return { dimension, label, group, rows: [], skipped: 'unsupported' }
      }
      const data = await safe(
        queryClient.fetchQuery(
          analyticsBreakdownQueryOptions(
            projectId,
            propertyId,
            dimension,
            range,
            EXPORT_BREAKDOWN_LIMIT,
            true,
            filters,
          ),
        ),
      )
      return data
        ? { dimension, label, group, rows: data.breakdown }
        : { dimension, label, group, rows: [], skipped: 'error' }
    },
  )

  return {
    property,
    range,
    interval,
    comparisonRange,
    compareLabel: options.compareLabel,
    filterLabels: options.filterLabels,
    stats,
    comparisonStats,
    series: buildAnalyticsChartPoints(seriesData?.points ?? [], range, interval),
    comparisonSeries:
      comparisonRange && comparisonSeriesData
        ? buildAnalyticsChartPoints(comparisonSeriesData.points, comparisonRange, interval)
        : [],
    breakdowns,
    generatedAt: new Date(),
  }
}

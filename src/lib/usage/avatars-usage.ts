import type { DateRange } from 'react-day-picker'
import {
  formatCompactCount,
} from '@/lib/usage/format-metric'
import {
  computeChangePercent,
  fetchProjectUsageMetricSeriesOverview,
  sumUsageChartPoints,
  type FetchUsageOverviewOptions,
  type UsageChartInterval,
  type UsageChartPoint,
} from '@/lib/usage/usage-events-common'
import { DEFAULT_USAGE_CHART_INTERVAL } from '@/lib/usage/chart-interval'

/** Webpage screenshots generated via Avatars (event counter). */
export const AVATARS_SCREENSHOTS_EVENT_METRIC =
  'avatars.screenshotsGenerated' as const

export type AvatarsUsageChartPoint = UsageChartPoint

export interface AvatarsUsageChartOverview {
  changePercent: number
  chartPoints: AvatarsUsageChartPoint[]
}

export const AVATARS_SCREENSHOTS_DESCRIPTION =
  'Webpage screenshots generated through the Avatars Screenshots API during the selected period. Each successful screenshot request counts toward your plan limit.'

export const AVATARS_DOCS_HREF = '/docs/products/avatars/screenshots'

export function formatAvatarsScreenshotsTotal(count: number): string {
  return formatCompactCount(count, { compact: true })
}

export function formatAvatarsScreenshotsValue(count: number): string {
  return formatCompactCount(count, { compact: true })
}

export {
  formatCompactCountAxis as formatAvatarsScreenshotsAxisValue,
} from '@/lib/usage/format-metric'

export { sumUsageChartPoints } from '@/lib/usage/usage-events-common'

export async function fetchProjectAvatarsScreenshotsOverview(
  projectId: string,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  options?: FetchUsageOverviewOptions,
): Promise<AvatarsUsageChartOverview> {
  const overview = await fetchProjectUsageMetricSeriesOverview(
    projectId,
    AVATARS_SCREENSHOTS_EVENT_METRIC,
    dateRange,
    interval,
    [],
    0,
    options,
  )

  return {
    chartPoints: overview.chartPoints,
    changePercent: computeChangePercent(
      sumUsageChartPoints(overview.chartPoints),
      sumUsageChartPoints(overview.previousChartPoints),
    ),
  }
}

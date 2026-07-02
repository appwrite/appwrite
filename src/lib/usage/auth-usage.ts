import type { DateRange } from 'react-day-picker'
import {
  formatCompactCount,
  formatCompactCountAxis,
} from '@/lib/usage/format-metric'
import {
  computeChangePercent,
  fetchProjectUsageMetricSeriesOverview,
  sumUsageChartPoints,
  type FetchUsageOverviewOptions,
  type UsageChartInterval,
  type UsageChartPoint,
} from '@/lib/usage/usage-events-common'
import {
  fetchProjectUsageGaugeChartSeries,
  fetchProjectUsageGaugesChartOverview,
} from '@/lib/usage/usage-gauges-common'
import { DEFAULT_USAGE_CHART_INTERVAL } from '@/lib/usage/chart-interval'
import { getUsageChartLatestValue } from '@/lib/usage/database-usage'

/** Monthly active users (gauge snapshot per interval). */
export const AUTH_MAU_GAUGE_METRIC = 'users.mau' as const

/** Phone OTP verification attempts (event counter). */
export const AUTH_OTP_EVENT_METRIC = 'auth.method.phone' as const

/** Total registered users (gauge); sign-up chart uses per-interval growth. */
export const AUTH_USERS_GAUGE_METRIC = 'users' as const

export const AUTH_MAU_GAUGE_METRICS = [AUTH_MAU_GAUGE_METRIC] as const

export type AuthUsageChartPoint = UsageChartPoint

export interface AuthUsageChartOverview {
  changePercent: number
  chartPoints: AuthUsageChartPoint[]
}

export const AUTH_MAU_DESCRIPTION =
  'Rolling monthly active user count over time. Each point is the MAU snapshot at that moment, not new users in that interval. MAU beyond your plan limit may incur additional charges.'

export const AUTH_OTP_DESCRIPTION =
  'Phone OTP verification attempts during the selected period. Each SMS or voice OTP sent counts toward your plan limit.'

export const AUTH_SIGNUPS_DESCRIPTION =
  'New user registrations during the selected period. Net growth in total registered users (account deletions reduce this count).'

export const AUTH_DOCS_HREF = '/docs/products/auth'

export function formatAuthMauTotal(count: number): string {
  return formatCompactCount(count, { compact: true })
}

export function formatAuthMauValue(count: number): string {
  return formatCompactCount(count, { compact: true })
}

export function formatAuthOtpTotal(count: number): string {
  return formatCompactCount(count, { compact: true })
}

export function formatAuthOtpValue(count: number): string {
  return formatCompactCount(count, { compact: true })
}

export function formatAuthSignupsTotal(count: number): string {
  return formatCompactCount(count, { compact: true })
}

export function formatAuthSignupsValue(count: number): string {
  return formatCompactCount(count, { compact: true })
}

export {
  formatCompactCountAxis as formatAuthCountAxisValue,
} from '@/lib/usage/format-metric'

export { sumUsageChartPoints } from '@/lib/usage/usage-events-common'

/** Net change in a cumulative gauge series across the chart window. */
export function getUsageChartPeriodDelta(points: UsageChartPoint[]): number {
  if (points.length === 0) return 0
  if (points.length === 1) return Math.max(0, points[0].total)
  const first = points[0].total
  const last = points[points.length - 1].total
  return Math.max(0, last - first)
}

/** Per-interval new users from a cumulative total-users gauge series. */
export function toIncrementalGaugeChartPoints(
  points: UsageChartPoint[],
  baselineTotal = 0,
): UsageChartPoint[] {
  if (points.length === 0) return []

  return points.map((point, index) => {
    const previousTotal =
      index === 0 ? baselineTotal : points[index - 1].total
    return {
      ...point,
      total: Math.max(0, point.total - previousTotal),
    }
  })
}

/** MAU time series from usage.listGauges. */
export async function fetchProjectAuthMauOverview(
  projectId: string,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  options?: FetchUsageOverviewOptions,
): Promise<AuthUsageChartOverview> {
  const overview = await fetchProjectUsageGaugesChartOverview(
    projectId,
    dateRange,
    AUTH_MAU_GAUGE_METRICS,
    interval,
    options?.queries,
  )

  return {
    changePercent: overview.changePercent,
    chartPoints: overview.chartPoints,
  }
}

/** Phone OTP attempts from usage.listEvents. */
export async function fetchProjectAuthOtpOverview(
  projectId: string,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  options?: FetchUsageOverviewOptions,
): Promise<AuthUsageChartOverview> {
  const overview = await fetchProjectUsageMetricSeriesOverview(
    projectId,
    AUTH_OTP_EVENT_METRIC,
    dateRange,
    interval,
    [],
    0,
    options,
  )

  return {
    changePercent: overview.changePercent,
    chartPoints: overview.chartPoints,
  }
}

/** Sign-ups derived from total-users gauge growth per interval. */
export async function fetchProjectAuthSignupsOverview(
  projectId: string,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  options?: FetchUsageOverviewOptions,
): Promise<AuthUsageChartOverview> {
  const { chartPoints, previousChartPoints } =
    await fetchProjectUsageGaugeChartSeries(
      projectId,
      dateRange,
      [AUTH_USERS_GAUGE_METRIC],
      interval,
      options?.queries,
    )

  const previousBaseline =
    previousChartPoints.length > 0
      ? previousChartPoints[previousChartPoints.length - 1].total
      : 0
  const incrementalCurrent = toIncrementalGaugeChartPoints(
    chartPoints,
    previousBaseline,
  )
  const incrementalPrevious = toIncrementalGaugeChartPoints(previousChartPoints)

  return {
    chartPoints: incrementalCurrent,
    changePercent: computeChangePercent(
      sumUsageChartPoints(incrementalCurrent),
      sumUsageChartPoints(incrementalPrevious),
    ),
  }
}

export function getAuthMauDisplayTotal(points: UsageChartPoint[]): number {
  return getUsageChartLatestValue(points)
}

export function getAuthSignupsDisplayTotal(points: UsageChartPoint[]): number {
  return sumUsageChartPoints(points)
}

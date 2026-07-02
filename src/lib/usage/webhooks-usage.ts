import type { DateRange } from 'react-day-picker'
import { formatCompactCount } from '@/lib/usage/format-metric'
import {
  fetchProjectUsageMetricSeriesOverview,
  type FetchUsageOverviewOptions,
  type UsageChartInterval,
  type UsageChartPoint,
} from '@/lib/usage/usage-events-common'
import { fetchProjectUsageGaugesChartOverview } from '@/lib/usage/usage-gauges-common'
import { DEFAULT_USAGE_CHART_INTERVAL } from '@/lib/usage/chart-interval'
import { getUsageChartLatestValue } from '@/lib/usage/database-usage'

/** Webhook events successfully delivered (event counter). */
export const WEBHOOKS_EVENTS_SENT_EVENT_METRIC =
  'webhooks.events.sent' as const

/** Webhook delivery failures (event counter). */
export const WEBHOOKS_EVENTS_FAILED_EVENT_METRIC =
  'webhooks.events.failed' as const

/** Configured webhooks in the project (gauge snapshot). */
export const WEBHOOKS_GAUGE_METRIC = 'webhooks' as const

export const WEBHOOKS_GAUGE_METRICS = [WEBHOOKS_GAUGE_METRIC] as const

export type WebhooksUsageChartPoint = UsageChartPoint

export interface WebhooksUsageChartOverview {
  changePercent: number
  chartPoints: WebhooksUsageChartPoint[]
}

export const WEBHOOKS_EVENTS_SENT_DESCRIPTION =
  'Webhook events successfully delivered during the selected period. Each HTTP request sent to your endpoint counts as one event.'

export const WEBHOOKS_EVENTS_FAILED_DESCRIPTION =
  'Webhook delivery failures during the selected period. Failed attempts include non-2xx responses and connection errors.'

export const WEBHOOKS_COUNT_DESCRIPTION =
  'Webhooks configured in your project. Each webhook subscribes to one or more Appwrite events.'

export const WEBHOOKS_DOCS_HREF = '/docs/advanced/platform/webhooks'

export function formatWebhooksCountTotal(count: number): string {
  return formatCompactCount(count, { compact: true })
}

export function formatWebhooksCountValue(count: number): string {
  return formatCompactCount(count, { compact: true })
}

export {
  formatCompactCount as formatWebhooksCountAxisValue,
} from '@/lib/usage/format-metric'

export { sumUsageChartPoints } from '@/lib/usage/usage-events-common'
export { getUsageChartLatestValue as getWebhooksCountDisplayTotal } from '@/lib/usage/database-usage'

export async function fetchProjectWebhooksEventsSentOverview(
  projectId: string,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  options?: FetchUsageOverviewOptions,
): Promise<WebhooksUsageChartOverview> {
  const overview = await fetchProjectUsageMetricSeriesOverview(
    projectId,
    WEBHOOKS_EVENTS_SENT_EVENT_METRIC,
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

export async function fetchProjectWebhooksEventsFailedOverview(
  projectId: string,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  options?: FetchUsageOverviewOptions,
): Promise<WebhooksUsageChartOverview> {
  const overview = await fetchProjectUsageMetricSeriesOverview(
    projectId,
    WEBHOOKS_EVENTS_FAILED_EVENT_METRIC,
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

export async function fetchProjectWebhooksCountOverview(
  projectId: string,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  options?: FetchUsageOverviewOptions,
): Promise<WebhooksUsageChartOverview> {
  const overview = await fetchProjectUsageGaugesChartOverview(
    projectId,
    dateRange,
    WEBHOOKS_GAUGE_METRICS,
    interval,
    options?.queries,
  )

  return {
    changePercent: overview.changePercent,
    chartPoints: overview.chartPoints,
  }
}

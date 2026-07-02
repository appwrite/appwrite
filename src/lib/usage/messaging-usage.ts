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

/** Messages delivered across all channels (event counter). */
export const MESSAGING_MESSAGES_SENT_EVENT_METRIC = 'messages.sent' as const

/** SMS messages delivered (event counter). */
export const MESSAGING_SMS_SENT_EVENT_METRIC = 'messages.sms.sent' as const

/** Messaging topics in the project (gauge snapshot). */
export const MESSAGING_TOPICS_GAUGE_METRIC = 'topics' as const

export const MESSAGING_TOPICS_GAUGE_METRICS = [
  MESSAGING_TOPICS_GAUGE_METRIC,
] as const

export type MessagingUsageChartPoint = UsageChartPoint

export interface MessagingUsageChartOverview {
  changePercent: number
  chartPoints: MessagingUsageChartPoint[]
}

export const MESSAGING_MESSAGES_DESCRIPTION =
  'Messages sent across all channels (push, email, SMS) during the selected period. Each delivery to an end-user target counts as one message.'

export const MESSAGING_TOPICS_DESCRIPTION =
  'Messaging topics in your project. Topics group subscribers for broadcast and targeted notifications.'

export const MESSAGING_SMS_DESCRIPTION =
  'SMS messages sent during the selected period. Each SMS segment delivered to a phone target counts as one message.'

export const MESSAGING_DOCS_HREF = '/docs/products/messaging'

export function formatMessagingCountTotal(count: number): string {
  return formatCompactCount(count, { compact: true })
}

export function formatMessagingCountValue(count: number): string {
  return formatCompactCount(count, { compact: true })
}

export {
  formatCompactCount as formatMessagingCountAxisValue,
} from '@/lib/usage/format-metric'

export { sumUsageChartPoints } from '@/lib/usage/usage-events-common'
export { getUsageChartLatestValue as getMessagingTopicsDisplayTotal } from '@/lib/usage/database-usage'

export async function fetchProjectMessagingMessagesOverview(
  projectId: string,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  options?: FetchUsageOverviewOptions,
): Promise<MessagingUsageChartOverview> {
  const overview = await fetchProjectUsageMetricSeriesOverview(
    projectId,
    MESSAGING_MESSAGES_SENT_EVENT_METRIC,
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

export async function fetchProjectMessagingSmsOverview(
  projectId: string,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  options?: FetchUsageOverviewOptions,
): Promise<MessagingUsageChartOverview> {
  const overview = await fetchProjectUsageMetricSeriesOverview(
    projectId,
    MESSAGING_SMS_SENT_EVENT_METRIC,
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

export async function fetchProjectMessagingTopicsOverview(
  projectId: string,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
  options?: FetchUsageOverviewOptions,
): Promise<MessagingUsageChartOverview> {
  const overview = await fetchProjectUsageGaugesChartOverview(
    projectId,
    dateRange,
    MESSAGING_TOPICS_GAUGE_METRICS,
    interval,
    options?.queries,
  )

  return {
    changePercent: overview.changePercent,
    chartPoints: overview.chartPoints,
  }
}

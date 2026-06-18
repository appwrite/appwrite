import type { DateRange } from 'react-day-picker'
import {
  formatCompactBytes,
} from '@/lib/usage/format-metric'
import {
  fetchProjectUsageGaugeSnapshotOverview,
  type UsageChartInterval,
  type UsageTopEndpoint,
} from '@/lib/usage/usage-gauges-common'
import { DEFAULT_USAGE_CHART_INTERVAL } from '@/lib/usage/chart-interval'

/** File storage gauge for the overview snapshot and bucket breakdown. */
export const BUCKET_STORAGE_GAUGE_METRIC = 'files.storage' as const

export type StorageTopConsumer = UsageTopEndpoint

export interface ProjectStorageOverview {
  changePercent: number
  latestValue: number
  topConsumers: StorageTopConsumer[]
}

export function formatStorageTotal(bytes: number): string {
  return formatCompactBytes(bytes, { compact: true })
}

export function formatStorageValue(bytes: number): string {
  return formatCompactBytes(bytes, { compact: true })
}

export { formatCompactBytesAxis as formatStorageAxisValue } from '@/lib/usage/format-metric'

/** Latest files.storage snapshot + top bucket breakdown (no time series). */
export async function fetchProjectStorageOverview(
  projectId: string,
  dateRange: DateRange | undefined,
  interval: UsageChartInterval = DEFAULT_USAGE_CHART_INTERVAL,
): Promise<ProjectStorageOverview> {
  const overview = await fetchProjectUsageGaugeSnapshotOverview(
    projectId,
    dateRange,
    BUCKET_STORAGE_GAUGE_METRIC,
    interval,
    {
      dimensions: ['resourceId'],
    },
  )

  return {
    changePercent: overview.changePercent,
    latestValue: overview.latestValue,
    topConsumers: overview.topConsumers,
  }
}

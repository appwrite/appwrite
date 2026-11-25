/**
 * Log volume over time (Cloud `/v1/logs/usage`): which bucket width to ask for
 * and how to turn the sparse buckets the API returns into one bar per bucket.
 */

import type { UsageChartInterval } from '@/lib/usage/chart-interval'

export const RUNTIME_LOG_USAGE_INTERVAL_MS: Record<UsageChartInterval, number> =
  {
    '1m': 60_000,
    '15m': 15 * 60_000,
    '1h': 60 * 60_000,
    '1d': 24 * 60 * 60_000,
  }

/** One bucket as the API returns it; empty buckets are left out. */
export interface RuntimeLogUsageBucket {
  date: string
  total: number
  stderr: number
}

/** One bar on the chart. `day` is the bucket start, as the brush expects. */
export interface RuntimeLogVolumePoint {
  day: Date
  date: string
  stdout: number
  stderr: number
  total: number
}

/**
 * The narrowest bucket that keeps the chart under about 150 bars. A window
 * with no known start (the plan's whole retention) uses hours.
 */
export function runtimeLogUsageInterval(
  spanMs: number | null,
): UsageChartInterval {
  if (spanMs === null) return '1h'
  if (spanMs <= 2 * 60 * 60_000) return '1m'
  if (spanMs <= 24 * 60 * 60_000) return '15m'
  if (spanMs <= 7 * 24 * 60 * 60_000) return '1h'
  return '1d'
}

/** Buckets start on multiples of their width since the epoch, as the API counts them. */
function bucketStart(time: number, step: number): number {
  return Math.floor(time / step) * step
}

/**
 * One point per bucket from the one holding `from` to the one holding `to`,
 * with zeros where the API returned nothing. Without a `from`, starts at the
 * first bucket returned, or a day before `to` when there is none.
 */
export function fillRuntimeLogVolume(
  buckets: readonly RuntimeLogUsageBucket[],
  from: Date | null,
  to: Date,
  interval: UsageChartInterval,
): RuntimeLogVolumePoint[] {
  const step = RUNTIME_LOG_USAGE_INTERVAL_MS[interval]
  const counts = new Map<number, RuntimeLogUsageBucket>()
  for (const bucket of buckets) {
    const time = new Date(bucket.date).getTime()
    if (Number.isFinite(time)) counts.set(bucketStart(time, step), bucket)
  }

  const end = bucketStart(to.getTime(), step)
  const firstReturned = counts.size > 0 ? Math.min(...counts.keys()) : null
  const start = bucketStart(
    from?.getTime() ?? firstReturned ?? to.getTime() - 24 * 60 * 60_000,
    step,
  )

  const points: RuntimeLogVolumePoint[] = []
  for (let time = start; time <= end; time += step) {
    const bucket = counts.get(time)
    const total = bucket?.total ?? 0
    const stderr = Math.min(bucket?.stderr ?? 0, total)
    const day = new Date(time)
    points.push({
      day,
      date: day.toISOString(),
      stdout: total - stderr,
      stderr,
      total,
    })
  }
  return points
}

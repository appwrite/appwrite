/**
 * Transform console-protocol chart actions into UsageChartPoint series
 * for the Console charts library.
 */

import { format, parseISO } from 'date-fns'
import { formatLocalizedDate } from '@/lib/i18n/date-format'
import {
  DEFAULT_USAGE_CHART_INTERVAL,
  type UsageChartInterval,
} from '@/lib/usage/chart-interval'
import {
  fillChartPointsGaps,
  fillGaugeChartPointsGaps,
  sumUsageChartPoints,
  type UsageChartPoint,
} from '@/lib/usage/usage-events-common'
import type {
  ConsoleAction,
  ConsoleChartAxisFormat,
  ConsoleChartKind,
  ConsoleChartMetric,
  ConsoleChartPoint,
  ConsoleChartType,
} from '@/lib/assistant/console-protocol'

export type ConsoleChartAction = Extract<ConsoleAction, { type: 'chart' }>

/**
 * Usage API occasionally returns timestamps like `…Z+00:00`.
 * Normalize to a parseable ISO string before `parseISO`.
 */
export function normalizeUsageTimestamp(time: string): string {
  const trimmed = time.trim()
  if (!trimmed) return trimmed
  // `2026-08-04T06:37:57.677199Z+00:00` → drop redundant offset after Z
  if (/Z[+-]\d{2}:?\d{2}$/i.test(trimmed)) {
    return trimmed.replace(/Z[+-]\d{2}:?\d{2}$/i, 'Z')
  }
  return trimmed
}

export function parseConsoleChartTime(time: string): Date | null {
  const normalized = normalizeUsageTimestamp(time)
  if (!normalized) return null
  try {
    const day = parseISO(normalized)
    if (Number.isNaN(day.getTime())) return null
    return day
  } catch {
    return null
  }
}

/** Map usage API interval strings onto Console chart intervals when possible. */
export function resolveConsoleChartInterval(
  interval: string | undefined,
): UsageChartInterval | null {
  const value = interval?.trim().toLowerCase()
  if (!value) return null
  if (value === '15m' || value === '1h' || value === '1d') return value
  // Coarser/finer API intervals: plot raw points (no gap fill) rather than lie.
  return null
}

function formatRawPointLabel(day: Date, from: Date, to: Date): string {
  const sameDay = from.toDateString() === to.toDateString()
  const spanMs = Math.abs(to.getTime() - from.getTime())
  const spansMultipleDays = !sameDay || spanMs > 36 * 60 * 60 * 1000
  if (spansMultipleDays) {
    return formatLocalizedDate(day, 'd MMM HH:mm')
  }
  return format(day, 'HH:mm')
}

function metricPointsToMergedMap(
  points: ConsoleChartPoint[],
): Map<string, number> {
  const merged = new Map<string, number>()
  for (const point of points) {
    const day = parseConsoleChartTime(point.time)
    if (!day) continue
    const key = day.toISOString()
    const value = Number(point.value)
    if (!Number.isFinite(value)) continue
    merged.set(key, (merged.get(key) ?? 0) + value)
  }
  return merged
}

function rawPointsToChartPoints(points: ConsoleChartPoint[]): UsageChartPoint[] {
  const byTime = new Map<number, { day: Date; total: number }>()
  for (const point of points) {
    const day = parseConsoleChartTime(point.time)
    if (!day) continue
    const value = Number(point.value)
    if (!Number.isFinite(value)) continue
    const key = day.getTime()
    const existing = byTime.get(key)
    if (existing) {
      existing.total += value
    } else {
      byTime.set(key, { day, total: value })
    }
  }
  const sorted = Array.from(byTime.values()).sort(
    (a, b) => a.day.getTime() - b.day.getTime(),
  )
  if (sorted.length === 0) return []
  const from = sorted[0]!.day
  const to = sorted[sorted.length - 1]!.day
  return sorted.map(({ day, total }) => ({
    date: formatRawPointLabel(day, from, to),
    day,
    total,
  }))
}

/**
 * Convert one metric series into UsageChartPoint[].
 * Gap-fills when interval + startAt + endAt are available and supported.
 */
export function consoleMetricToChartPoints(
  metric: ConsoleChartMetric,
  options: {
    interval?: string
    startAt?: string
    endAt?: string
    kind?: ConsoleChartKind
  },
): UsageChartPoint[] {
  const points = Array.isArray(metric.points) ? metric.points : []
  if (points.length === 0) return []

  const chartInterval = resolveConsoleChartInterval(options.interval)
  const from = options.startAt ? parseConsoleChartTime(options.startAt) : null
  const to = options.endAt ? parseConsoleChartTime(options.endAt) : null

  if (chartInterval && from && to && from.getTime() <= to.getTime()) {
    const merged = metricPointsToMergedMap(points)
    if (options.kind === 'gauges') {
      return fillGaugeChartPointsGaps(merged, from, to, chartInterval)
    }
    return fillChartPointsGaps(merged, from, to, chartInterval)
  }

  return rawPointsToChartPoints(points)
}

export type ConsoleBarChartRow = {
  label: string
  value: number
  fullLabel: string
}

/** Build categorical bar rows from metric points (label or time). */
export function consoleMetricsToBarRows(
  metrics: ConsoleChartMetric[],
): ConsoleBarChartRow[] {
  const rows: ConsoleBarChartRow[] = []
  for (const metric of metrics) {
    const points = Array.isArray(metric.points) ? metric.points : []
    for (const point of points) {
      const value = Number(point.value)
      if (!Number.isFinite(value)) continue
      const label =
        (typeof point.label === 'string' && point.label.trim()) ||
        metric.metric?.trim() ||
        (() => {
          const day = parseConsoleChartTime(point.time)
          return day ? formatLocalizedDate(day, 'd MMM HH:mm') : point.time
        })()
      rows.push({
        label: truncateLabel(label, 28),
        fullLabel: label,
        value,
      })
    }
  }
  return rows
}

function truncateLabel(label: string, max: number): string {
  const trimmed = label.trim()
  if (trimmed.length <= max) return trimmed
  return `${trimmed.slice(0, Math.max(0, max - 1))}…`
}

export function resolveConsoleChartType(
  action: Pick<ConsoleChartAction, 'chartType' | 'interval' | 'metrics'>,
): ConsoleChartType {
  if (action.chartType === 'area' || action.chartType === 'bar') {
    return action.chartType
  }
  // Heuristic: labeled points or flat aggregate with multiple categories → bar
  const metrics = Array.isArray(action.metrics) ? action.metrics : []
  const allPoints = metrics.flatMap((m) =>
    Array.isArray(m.points) ? m.points : [],
  )
  const labeled = allPoints.some(
    (p) => typeof p.label === 'string' && p.label.trim().length > 0,
  )
  if (labeled) return 'bar'

  const interval = action.interval?.trim()
  if (!interval && allPoints.length > 1) {
    const uniqueTimes = new Set(
      allPoints
        .map((p) => parseConsoleChartTime(p.time)?.getTime())
        .filter((t): t is number => t != null),
    )
    if (uniqueTimes.size <= 1) return 'bar'
  }

  return 'area'
}

export function resolveConsoleChartUnitLabel(
  action: Pick<ConsoleChartAction, 'unitLabel' | 'metrics'>,
): string {
  if (action.unitLabel?.trim()) return action.unitLabel.trim()
  const first = action.metrics?.[0]?.metric?.trim()
  return first || 'total'
}

export function resolveConsoleChartAxisFormat(
  action: Pick<ConsoleChartAction, 'axisFormat' | 'metrics' | 'unitLabel'>,
): ConsoleChartAxisFormat {
  if (
    action.axisFormat === 'count' ||
    action.axisFormat === 'bytes' ||
    action.axisFormat === 'gbhours'
  ) {
    return action.axisFormat
  }
  const hint =
    `${action.unitLabel ?? ''} ${action.metrics?.[0]?.metric ?? ''}`.toLowerCase()
  if (
    hint.includes('bandwidth') ||
    hint.includes('storage') ||
    hint.includes('bytes') ||
    hint.includes('inbound') ||
    hint.includes('outbound')
  ) {
    return 'bytes'
  }
  if (
    hint.includes('gbhours') ||
    hint.includes('gb-hours') ||
    hint.includes('gb hours')
  ) {
    return 'gbhours'
  }
  return 'count'
}

export function sumConsoleChartPoints(points: UsageChartPoint[]): number {
  return sumUsageChartPoints(points)
}

export function defaultConsoleChartInterval(
  interval: string | undefined,
): UsageChartInterval {
  return resolveConsoleChartInterval(interval) ?? DEFAULT_USAGE_CHART_INTERVAL
}

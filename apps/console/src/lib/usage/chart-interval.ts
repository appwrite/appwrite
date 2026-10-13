import { differenceInCalendarDays } from 'date-fns'
import type { Models } from '@appwrite.io/console'
import type { DateRange } from 'react-day-picker'
import { resolveUsageDateBounds } from '@/lib/usage/usage-date-range'

export type UsageChartInterval = '1m' | '15m' | '1h' | '1d'

export type UsageChartIntervalPlan = Pick<
  Models.BillingPlan,
  'usageLogsIntervals'
> | null | undefined

export const DEFAULT_USAGE_CHART_INTERVAL: UsageChartInterval = '1h'

/** Dedicated database monitor: last-hour view at 1-minute buckets. */
export const DEFAULT_MONITOR_CHART_INTERVAL: UsageChartInterval = '1m'

export const USAGE_CHART_INTERVAL_OPTIONS: {
  value: UsageChartInterval
  label: string
}[] = [
  { value: '1m', label: '1m' },
  { value: '15m', label: '15m' },
  { value: '1h', label: '1h' },
  { value: '1d', label: '1d' },
]

/**
 * Intervals allowed by the org plan (`usageLogsIntervals`), falling back to the
 * full console set when the plan omits the field.
 */
export function getUsageChartIntervalOptionsForPlan(
  plan: UsageChartIntervalPlan,
): typeof USAGE_CHART_INTERVAL_OPTIONS {
  const allowed = plan?.usageLogsIntervals
  if (!allowed?.length) return USAGE_CHART_INTERVAL_OPTIONS

  const allowedSet = new Set(allowed)
  const filtered = USAGE_CHART_INTERVAL_OPTIONS.filter((option) =>
    allowedSet.has(option.value),
  )
  return filtered.length > 0 ? filtered : USAGE_CHART_INTERVAL_OPTIONS
}

export function getUsageChartIntervalsForPlan(
  plan: UsageChartIntervalPlan,
): UsageChartInterval[] {
  return getUsageChartIntervalOptionsForPlan(plan).map((option) => option.value)
}

export function resolveUsageChartIntervalForPlan(
  interval: UsageChartInterval,
  plan: UsageChartIntervalPlan,
): UsageChartInterval {
  const options = getUsageChartIntervalOptionsForPlan(plan)
  if (options.some((option) => option.value === interval)) return interval
  return options[0]?.value ?? DEFAULT_USAGE_CHART_INTERVAL
}

/** Finest to coarsest - used when coarsening interval for wider date ranges. */
export const USAGE_CHART_INTERVAL_COARSEN_ORDER: UsageChartInterval[] = [
  '1m',
  '15m',
  '1h',
  '1d',
]

const INTERVAL_DURATION_MS: Record<UsageChartInterval, number> = {
  '1m': 60_000,
  '15m': 15 * 60_000,
  '1h': 60 * 60_000,
  '1d': 24 * 60 * 60_000,
}

function resolveChartIntervalDateBounds(dateRange: DateRange | undefined): {
  from: Date
  to: Date
} {
  return resolveUsageDateBounds(dateRange)
}

/** Max inclusive calendar days allowed for each interval (null = unlimited). */
const INTERVAL_MAX_RANGE_DAYS: Record<UsageChartInterval, number | null> = {
  '1m': null,
  '15m': null,
  '1h': 31,
  '1d': null,
}

/** Max duration in hours (checked when calendar-day limit is null). */
const INTERVAL_MAX_RANGE_HOURS: Partial<Record<UsageChartInterval, number>> = {
  '1m': 24,
  '15m': 24,
}

export function getUsageChartIntervalDurationMs(
  interval: UsageChartInterval,
): number {
  return INTERVAL_DURATION_MS[interval]
}

export function getUsageChartIntervalMaxRangeDays(
  interval: UsageChartInterval,
): number | null {
  return INTERVAL_MAX_RANGE_DAYS[interval]
}

export function getUsageChartIntervalMaxRangeHours(
  interval: UsageChartInterval,
): number | null {
  return INTERVAL_MAX_RANGE_HOURS[interval] ?? null
}

/** Range is too wide for this interval's bucket size (too many points). */
export function isUsageChartIntervalTooFineForRange(
  interval: UsageChartInterval,
  dateRange: DateRange | undefined,
): boolean {
  const { from, to } = resolveChartIntervalDateBounds(dateRange)

  const maxHours = getUsageChartIntervalMaxRangeHours(interval)
  if (maxHours !== null) {
    const durationMs = to.getTime() - from.getTime()
    const maxDurationMs = maxHours * 60 * 60 * 1000
    const toleranceMs = 60_000
    return durationMs > maxDurationMs + toleranceMs
  }

  const maxDays = getUsageChartIntervalMaxRangeDays(interval)
  if (maxDays === null) return false

  const rangeDays = Math.max(1, differenceInCalendarDays(to, from) + 1)
  return rangeDays > maxDays
}

/** Interval is not strictly smaller than the selected range. */
export function isUsageChartIntervalTooCoarseForRange(
  interval: UsageChartInterval,
  dateRange: DateRange | undefined,
): boolean {
  const { from, to } = resolveChartIntervalDateBounds(dateRange)
  return getUsageChartIntervalDurationMs(interval) >= to.getTime() - from.getTime()
}

export function isUsageChartIntervalValidForRange(
  interval: UsageChartInterval,
  dateRange: DateRange | undefined,
): boolean {
  return (
    !isUsageChartIntervalTooFineForRange(interval, dateRange) &&
    !isUsageChartIntervalTooCoarseForRange(interval, dateRange)
  )
}

function hasFinerUsageChartInterval(
  interval: UsageChartInterval,
  allowedIntervals?: readonly UsageChartInterval[],
): boolean {
  const options = allowedIntervals?.length
    ? allowedIntervals
    : USAGE_CHART_INTERVAL_COARSEN_ORDER
  const durationMs = getUsageChartIntervalDurationMs(interval)
  return options.some(
    (option) => getUsageChartIntervalDurationMs(option) < durationMs,
  )
}

export function getUsageChartIntervalDisabledReason(
  interval: UsageChartInterval,
  dateRange: DateRange | undefined,
  allowedIntervals?: readonly UsageChartInterval[],
): string | undefined {
  const details = getUsageChartIntervalDisabledReasonDetails(
    interval,
    dateRange,
    allowedIntervals,
  )
  if (!details) return undefined

  if (details.kind === 'hours') {
    return `Use a date range of ${details.maxHours} hours or less for this interval.`
  }

  if (details.kind === 'days') {
    return `Use a date range of ${details.maxDays} days or less for this interval.`
  }

  return 'This interval is larger than the selected date range.'
}

export type UsageChartIntervalDisabledReasonDetails =
  | { kind: 'hours'; maxHours: number }
  | { kind: 'days'; maxDays: number }
  | { kind: 'largerThanRange' }

export function getUsageChartIntervalDisabledReasonDetails(
  interval: UsageChartInterval,
  dateRange: DateRange | undefined,
  allowedIntervals?: readonly UsageChartInterval[],
): UsageChartIntervalDisabledReasonDetails | undefined {
  if (isUsageChartIntervalTooFineForRange(interval, dateRange)) {
    const maxHours = getUsageChartIntervalMaxRangeHours(interval)
    if (maxHours !== null) {
      return { kind: 'hours', maxHours }
    }

    const maxDays = getUsageChartIntervalMaxRangeDays(interval)
    if (maxDays !== null) {
      return { kind: 'days', maxDays }
    }

    return undefined
  }

  if (!isUsageChartIntervalTooCoarseForRange(interval, dateRange)) {
    return undefined
  }

  // Keep this interval selectable when the plan has nothing smaller.
  if (!hasFinerUsageChartInterval(interval, allowedIntervals)) {
    return undefined
  }

  return { kind: 'largerThanRange' }
}

function pickFallbackUsageChartInterval(
  allowed: UsageChartInterval[],
  dateRange: DateRange | undefined,
): UsageChartInterval {
  const tooCoarse = allowed.filter(
    (candidate) =>
      isUsageChartIntervalTooCoarseForRange(candidate, dateRange) &&
      !isUsageChartIntervalTooFineForRange(candidate, dateRange),
  )
  if (tooCoarse.length > 0) return tooCoarse[0]!

  for (let index = allowed.length - 1; index >= 0; index -= 1) {
    const candidate = allowed[index]!
    if (isUsageChartIntervalTooFineForRange(candidate, dateRange)) {
      return candidate
    }
  }

  return allowed[allowed.length - 1] ?? '1d'
}

/**
 * Keep the current interval when it still fits the range; otherwise step to
 * the nearest valid neighbor (finer when the range shrinks, coarser when it
 * widens).
 */
export function resolveUsageChartIntervalForRange(
  interval: UsageChartInterval,
  dateRange: DateRange | undefined,
  plan?: UsageChartIntervalPlan,
): UsageChartInterval {
  const allowed = getUsageChartIntervalsForPlan(plan)
  const allowedSet = new Set(allowed)
  const planInterval = resolveUsageChartIntervalForPlan(interval, plan)

  if (
    allowedSet.has(planInterval) &&
    isUsageChartIntervalValidForRange(planInterval, dateRange)
  ) {
    return planInterval
  }

  const startIndex = USAGE_CHART_INTERVAL_COARSEN_ORDER.indexOf(planInterval)
  const walk =
    startIndex >= 0 &&
    isUsageChartIntervalTooCoarseForRange(planInterval, dateRange)
      ? USAGE_CHART_INTERVAL_COARSEN_ORDER.slice(0, startIndex).reverse()
      : startIndex >= 0
        ? USAGE_CHART_INTERVAL_COARSEN_ORDER.slice(startIndex + 1)
        : USAGE_CHART_INTERVAL_COARSEN_ORDER.slice(1)

  for (const candidate of walk) {
    if (!allowedSet.has(candidate)) continue
    if (isUsageChartIntervalValidForRange(candidate, dateRange)) {
      return candidate
    }
  }

  for (const candidate of USAGE_CHART_INTERVAL_COARSEN_ORDER) {
    if (!allowedSet.has(candidate)) continue
    if (isUsageChartIntervalValidForRange(candidate, dateRange)) {
      return candidate
    }
  }

  return pickFallbackUsageChartInterval(allowed, dateRange)
}

/**
 * Resolve interval against both plan `usageLogsIntervals` and date-range limits.
 */
export function resolveUsageChartInterval(
  interval: UsageChartInterval,
  dateRange: DateRange | undefined,
  plan?: UsageChartIntervalPlan,
): UsageChartInterval {
  return resolveUsageChartIntervalForRange(interval, dateRange, plan)
}

/** Map saved interval prefs to a known chart interval value. */
export function normalizeUsageChartIntervalPref(
  value: string,
): UsageChartInterval | null {
  if (isUsageChartInterval(value)) return value
  return null
}

export function isUsageChartInterval(
  value: string,
): value is UsageChartInterval {
  return (USAGE_CHART_INTERVAL_OPTIONS as { value: string }[]).some(
    (option) => option.value === value,
  )
}

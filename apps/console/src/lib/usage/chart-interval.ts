import { differenceInCalendarDays } from 'date-fns'
import type { Models } from '@appwrite.io/console'
import type { DateRange } from 'react-day-picker'
import { resolveUsageDateBounds } from '@/lib/usage/usage-date-range'

export type UsageChartInterval = '1m' | '15m' | '1h' | '1d'

export type UsageChartIntervalPlan =
  | Pick<Models.BillingPlan, 'usageLogsIntervals'>
  | null
  | undefined

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

/** Ranges shorter than one 15-minute bucket read at 1-minute resolution. */
const MINUTE_INTERVAL_RANGE_MS = 15 * 60 * 1000

const INTERVAL_DURATION_MS: Record<UsageChartInterval, number> = {
  '1m': 60 * 1000,
  '15m': 15 * 60 * 1000,
  '1h': 60 * 60 * 1000,
  '1d': 24 * 60 * 60 * 1000,
}

/** Rolling presets and full calendar days end a little off the hour. */
const RANGE_DURATION_TOLERANCE_MS = 60_000

function getUsageChartRangeDurationMs(
  dateRange: DateRange | undefined,
): number {
  const { from, to } = resolveChartIntervalDateBounds(dateRange)
  return to.getTime() - from.getTime()
}

function usageChartRangeNeedsMinuteInterval(
  dateRange: DateRange | undefined,
): boolean {
  return getUsageChartRangeDurationMs(dateRange) < MINUTE_INTERVAL_RANGE_MS
}

/**
 * An interval must be shorter than the range it splits, otherwise the chart
 * is a single bucket (1d over Today or Last 24 hours). The finest allowed
 * interval is exempt so a range always has an option.
 */
function isUsageChartIntervalTooCoarseForRange(
  interval: UsageChartInterval,
  dateRange: DateRange | undefined,
  allowedIntervals: readonly UsageChartInterval[],
): boolean {
  const finest = USAGE_CHART_INTERVAL_COARSEN_ORDER.find((candidate) =>
    allowedIntervals.includes(candidate),
  )
  if (interval === finest) return false

  return (
    INTERVAL_DURATION_MS[interval] >=
    getUsageChartRangeDurationMs(dateRange) - RANGE_DURATION_TOLERANCE_MS
  )
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

export function isUsageChartIntervalValidForRange(
  interval: UsageChartInterval,
  dateRange: DateRange | undefined,
): boolean {
  const { from, to } = resolveChartIntervalDateBounds(dateRange)

  const maxHours = getUsageChartIntervalMaxRangeHours(interval)
  if (maxHours !== null) {
    const durationMs = to.getTime() - from.getTime()
    const maxDurationMs = maxHours * 60 * 60 * 1000
    const toleranceMs = 60_000
    return durationMs <= maxDurationMs + toleranceMs
  }

  const maxDays = getUsageChartIntervalMaxRangeDays(interval)
  if (maxDays === null) return true

  const rangeDays = Math.max(1, differenceInCalendarDays(to, from) + 1)
  return rangeDays <= maxDays
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

  if (details.kind === 'short') {
    return 'Ranges shorter than 15 minutes use the 1m interval.'
  }

  if (details.kind === 'coarse') {
    return 'Use a date range longer than this interval.'
  }

  if (details.kind === 'hours') {
    return `Use a date range of ${details.maxHours} hours or less for this interval.`
  }

  return `Use a date range of ${details.maxDays} days or less for this interval.`
}

export type UsageChartIntervalDisabledReasonDetails =
  | { kind: 'short' }
  | { kind: 'coarse' }
  | { kind: 'hours'; maxHours: number }
  | { kind: 'days'; maxDays: number }

export function getUsageChartIntervalDisabledReasonDetails(
  interval: UsageChartInterval,
  dateRange: DateRange | undefined,
  allowedIntervals?: readonly UsageChartInterval[],
): UsageChartIntervalDisabledReasonDetails | undefined {
  const allowed = allowedIntervals ?? getUsageChartIntervalsForPlan(undefined)
  if (
    interval !== '1m' &&
    allowed.includes('1m') &&
    usageChartRangeNeedsMinuteInterval(dateRange)
  ) {
    return { kind: 'short' }
  }

  if (isUsageChartIntervalValidForRange(interval, dateRange)) {
    return isUsageChartIntervalTooCoarseForRange(interval, dateRange, allowed)
      ? { kind: 'coarse' }
      : undefined
  }

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

/**
 * Keep the selected interval when it still fits. A range shorter than 15
 * minutes drops to 1m when the plan allows it, so the series is not collapsed
 * into a single 15-minute bucket. An interval as long as the range refines to
 * the next finer one (1d over Today reads at 1h). Wider ranges coarsen when
 * the current interval is no longer valid.
 */
export function resolveUsageChartIntervalForRange(
  interval: UsageChartInterval,
  dateRange: DateRange | undefined,
  plan?: UsageChartIntervalPlan,
  options?: { refine?: boolean },
): UsageChartInterval {
  const allowed = getUsageChartIntervalsForPlan(plan)
  const allowedSet = new Set(allowed)
  const planInterval = resolveUsageChartIntervalForPlan(interval, plan)
  const refine = options?.refine !== false

  if (
    refine &&
    allowedSet.has('1m') &&
    usageChartRangeNeedsMinuteInterval(dateRange) &&
    isUsageChartIntervalValidForRange('1m', dateRange)
  ) {
    return '1m'
  }

  const tooCoarse = (candidate: UsageChartInterval) =>
    refine &&
    isUsageChartIntervalTooCoarseForRange(candidate, dateRange, allowed)
  const fits = (candidate: UsageChartInterval) =>
    allowedSet.has(candidate) &&
    isUsageChartIntervalValidForRange(candidate, dateRange) &&
    !tooCoarse(candidate)

  if (fits(planInterval)) return planInterval

  const startIndex = USAGE_CHART_INTERVAL_COARSEN_ORDER.indexOf(planInterval)

  if (startIndex > 0 && tooCoarse(planInterval)) {
    const finer = USAGE_CHART_INTERVAL_COARSEN_ORDER.slice(
      0,
      startIndex,
    ).reverse()
    for (const candidate of finer) {
      if (fits(candidate)) return candidate
    }
  }

  const candidates =
    startIndex >= 0
      ? USAGE_CHART_INTERVAL_COARSEN_ORDER.slice(startIndex + 1)
      : USAGE_CHART_INTERVAL_COARSEN_ORDER.slice(1)

  for (const candidate of candidates) {
    if (fits(candidate)) return candidate
  }

  for (const candidate of USAGE_CHART_INTERVAL_COARSEN_ORDER) {
    if (fits(candidate)) return candidate
  }

  return allowed[allowed.length - 1] ?? '1d'
}

/**
 * Resolve interval against both plan `usageLogsIntervals` and date-range limits.
 */
export function resolveUsageChartInterval(
  interval: UsageChartInterval,
  dateRange: DateRange | undefined,
  plan?: UsageChartIntervalPlan,
  options?: { refine?: boolean },
): UsageChartInterval {
  return resolveUsageChartIntervalForRange(interval, dateRange, plan, options)
}

/**
 * Resolve within a fixed interval set (Analytics and Affiliates offer only
 * 1h and 1d), with the same range rules as plan-limited charts.
 */
export function resolveUsageChartIntervalWithin<T extends UsageChartInterval>(
  interval: T,
  dateRange: DateRange | undefined,
  intervals: readonly T[],
  options?: { refine?: boolean },
): T {
  return resolveUsageChartIntervalForRange(
    interval,
    dateRange,
    { usageLogsIntervals: [...intervals] },
    options,
  ) as T
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

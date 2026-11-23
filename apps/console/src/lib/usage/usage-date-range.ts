import { endOfDay, startOfDay, subHours } from 'date-fns'
import type { DateRange } from 'react-day-picker'
import {
  findMatchingUsageDateRangePreset,
  getUsageDateRangePresetByValue,
  inferRollingPresetByDuration,
  inferUsageDateRangePresetFromStoredRange,
  type UsageDateRangePresetContext,
} from '@/lib/usage/usage-date-range-presets'

export function isStartOfLocalDay(date: Date): boolean {
  return date.getTime() === startOfDay(date).getTime()
}

/** Wall clock on a local calendar day, including seconds from presets. */
export type DayClock = {
  hour: number
  minute: number
  second: number
  millisecond: number
}

const FULL_DAY_START: DayClock = {
  hour: 0,
  minute: 0,
  second: 0,
  millisecond: 0,
}

const FULL_DAY_END: DayClock = {
  hour: 23,
  minute: 59,
  second: 59,
  millisecond: 999,
}

export function readDayClock(date: Date): DayClock {
  return {
    hour: date.getHours(),
    minute: date.getMinutes(),
    second: date.getSeconds(),
    millisecond: date.getMilliseconds(),
  }
}

export function applyDayClock(day: Date, clock: DayClock): Date {
  const next = new Date(day)
  next.setHours(clock.hour, clock.minute, clock.second, clock.millisecond)
  return next
}

export type DateRangeClocks = {
  start: DayClock | null
  end: DayClock | null
}

/**
 * DayPicker reports local midnights. Put the clocks the user already chose
 * back onto the new days. A missing end clock means the inclusive end of
 * that day, which is what a date-only selection already means.
 */
export function mergeCalendarRangeWithClocks(
  next: DateRange | undefined,
  clocks: DateRangeClocks,
): DateRange | undefined {
  if (!next?.from) return next

  const from = applyDayClock(next.from, clocks.start ?? FULL_DAY_START)
  if (!next.to) return { from }

  return {
    from,
    to: applyDayClock(next.to, clocks.end ?? FULL_DAY_END),
  }
}

/** Keep an end clock when the user is still picking the end day. */
export function rememberDateRangeClocks(
  clocks: DateRangeClocks,
  range: DateRange | undefined,
): DateRangeClocks {
  if (!range?.from) return { start: null, end: null }
  return {
    start: readDayClock(range.from),
    end: range.to ? readDayClock(range.to) : clocks.end,
  }
}

/**
 * Commit a picker selection. With `preserveTime`, explicit clocks stay,
 * including 00:00. Without it, a DayPicker midnight span expands to
 * inclusive calendar days.
 */
export function finalizeDateRangeSelection(
  dateRange: DateRange | undefined,
  preserveTime: boolean,
): DateRange | undefined {
  if (!dateRange?.from) return dateRange

  let from = dateRange.from
  let to = dateRange.to ?? (preserveTime ? endOfDay(from) : from)
  if (to.getTime() < from.getTime()) {
    const earlier = to
    to = from
    from = earlier
  }

  if (!preserveTime) {
    return normalizeUsageDateRangeSelection({ from, to })
  }

  return { from, to }
}

export function isFullCalendarDayRange(from: Date, to: Date): boolean {
  return isStartOfLocalDay(from) && to.getTime() === endOfDay(to).getTime()
}

/**
 * DayPicker range mode returns local midnights for both ends. A single day is
 * `{ from: day, to: day }`; a multi-day span ends on the start of the last day.
 * Those are date-only selections, not rolling hour windows.
 */
export function isCalendarDateOnlyRange(from: Date, to: Date): boolean {
  return isStartOfLocalDay(from) && isStartOfLocalDay(to)
}

/**
 * Expand DayPicker midnight ranges to inclusive calendar days so a specific
 * date is not treated as an empty or ~24h rolling window.
 */
export function normalizeUsageDateRangeSelection(
  dateRange: DateRange | undefined,
): DateRange | undefined {
  if (!dateRange?.from) return dateRange

  const from = dateRange.from
  const to = dateRange.to ?? from

  if (!isCalendarDateOnlyRange(from, to)) {
    return { from, to }
  }

  return {
    from: startOfDay(from),
    to: endOfDay(to),
  }
}

/** Default overview chart range: rolling last 24 hours. */
export function getDefaultUsageChartDateRange(): DateRange {
  const now = new Date()
  return {
    from: subHours(now, 24),
    to: now,
  }
}

/** Default database monitor chart range: rolling last hour. */
export function getDefaultMonitorChartDateRange(): DateRange {
  const now = new Date()
  return {
    from: subHours(now, 1),
    to: now,
  }
}

let stableDefaultMonitorChartDateRange: DateRange | null = null

/** Session-stable default for database monitor when no preset is active. */
export function getStableMonitorChartDateRange(): DateRange {
  if (!stableDefaultMonitorChartDateRange) {
    stableDefaultMonitorChartDateRange = getDefaultMonitorChartDateRange()
  }
  const { from, to } = stableDefaultMonitorChartDateRange
  return { from: new Date(from), to: new Date(to) }
}

export function resetStableMonitorChartDateRange() {
  stableDefaultMonitorChartDateRange = null
}

let stableDefaultUsageChartDateRange: DateRange | null = null

/**
 * Session-stable default when account prefs have no saved range.
 * Avoids new React Query keys on every render (rolling `to: now` otherwise changes each ms).
 */
export function getStableUsageChartDateRange(): DateRange {
  if (!stableDefaultUsageChartDateRange) {
    stableDefaultUsageChartDateRange = getDefaultUsageChartDateRange()
  }
  const { from, to } = stableDefaultUsageChartDateRange
  return { from: new Date(from), to: new Date(to) }
}

/** Reset after account switch (e.g. console impersonation) so the next user gets a fresh window. */
export function resetStableUsageChartDateRange() {
  stableDefaultUsageChartDateRange = null
}

/** Normalize picker range to API bounds; preserve times for rolling windows. */
export function resolveUsageDateBounds(dateRange: DateRange | undefined): {
  from: Date
  to: Date
} {
  if (!dateRange?.from) {
    return getStableUsageChartDateRange() as { from: Date; to: Date }
  }

  const normalized = normalizeUsageDateRangeSelection(dateRange)
  const from = normalized!.from!
  const to = normalized!.to ?? endOfDay(from)

  if (isFullCalendarDayRange(from, to)) {
    return { from: startOfDay(from), to: endOfDay(to) }
  }

  return { from, to }
}

export type SerializedUsageChartDateRange = {
  from?: string
  to?: string
  /** Quick-select preset id (rolling or calendar). Refreshed on each load. */
  preset?: string
}

/** Stable range for loader prefetch and View state (same query keys). */
export function serializeUsageChartDateRange(
  dateRange: DateRange,
): SerializedUsageChartDateRange {
  const normalized = normalizeUsageDateRangeSelection(dateRange) ?? dateRange
  const matchedPreset = findMatchingUsageDateRangePreset(normalized)
  if (matchedPreset) {
    return { preset: matchedPreset.value }
  }

  const { from, to } = resolveUsageDateBounds(normalized)
  return { from: from.toISOString(), to: to.toISOString() }
}

export function parseUsageChartDateRange(
  serialized: SerializedUsageChartDateRange,
  context?: UsageDateRangePresetContext,
): DateRange {
  if (serialized.preset) {
    const preset = getUsageDateRangePresetByValue(serialized.preset)
    if (preset) {
      return preset.getRange(context)
    }
  }

  if (serialized.from && serialized.to) {
    const storedRange: DateRange = {
      from: new Date(serialized.from),
      to: new Date(serialized.to),
    }

    if (
      !Number.isNaN(storedRange.from.getTime()) &&
      !Number.isNaN(storedRange.to.getTime())
    ) {
      const normalized =
        normalizeUsageDateRangeSelection(storedRange) ?? storedRange
      const inferredPreset =
        inferUsageDateRangePresetFromStoredRange(normalized)
      if (inferredPreset) {
        return inferredPreset.getRange()
      }
      return normalized
    }
  }

  return getStableUsageChartDateRange()
}

/** Bounds for API calls - rolling presets always resolve to a fresh window. */
export function resolveUsageChartFetchBounds(
  dateRange: DateRange | undefined,
  presetId?: string | null,
): { from: Date; to: Date } {
  if (presetId) {
    const preset = getUsageDateRangePresetByValue(presetId)
    if (preset) return preset.getRange()
  }

  const normalized = normalizeUsageDateRangeSelection(dateRange) ?? dateRange

  // Prefer exact / calendar matches before duration-based rolling inference
  // so Today (midnight–midnight) is not treated as Last 24 hours.
  const matchedPreset = findMatchingUsageDateRangePreset(normalized)
  if (matchedPreset) return matchedPreset.getRange()

  const inferredPreset = inferUsageDateRangePresetFromStoredRange(normalized)
  if (inferredPreset) return inferredPreset.getRange()

  const rollingPreset = inferRollingPresetByDuration(normalized)
  if (rollingPreset) return rollingPreset.getRange()

  return resolveUsageDateBounds(normalized) as { from: Date; to: Date }
}

/** Stable query-key segment for usage charts (preset id when applicable). */
export function getUsageChartQueryRangeKeyPart(
  dateRange: DateRange | undefined,
  presetId?: string | null,
): string {
  if (presetId) return `preset:${presetId}`

  const normalized = normalizeUsageDateRangeSelection(dateRange) ?? dateRange

  const matchedPreset = findMatchingUsageDateRangePreset(normalized)
  if (matchedPreset) return `preset:${matchedPreset.value}`

  const inferredPreset = inferUsageDateRangePresetFromStoredRange(normalized)
  if (inferredPreset) return `preset:${inferredPreset.value}`

  const rollingPreset = inferRollingPresetByDuration(normalized)
  if (rollingPreset) return `preset:${rollingPreset.value}`

  const { from, to } = resolveUsageDateBounds(normalized)
  return `${from.toISOString()}|${to.toISOString()}`
}

export function shouldRefetchUsageChartOnMount(rangeKeyPart: string): boolean {
  return (
    rangeKeyPart.startsWith('preset:1h') ||
    rangeKeyPart.startsWith('preset:6h') ||
    rangeKeyPart.startsWith('preset:24h') ||
    rangeKeyPart.startsWith('preset:today') ||
    rangeKeyPart.startsWith('preset:yesterday') ||
    rangeKeyPart.startsWith('preset:7d') ||
    rangeKeyPart.startsWith('preset:30d') ||
    rangeKeyPart.startsWith('preset:wtd') ||
    rangeKeyPart.startsWith('preset:mtd')
  )
}

export { isRollingUsageDateRangePresetId } from '@/lib/usage/usage-date-range-presets'

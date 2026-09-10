import { addDays, endOfDay, startOfDay } from 'date-fns'
import type { DateRange } from 'react-day-picker'
import {
  normalizeUsageDateRangeSelection,
  resolveUsageChartFetchBounds,
  resolveUsageDateBounds,
} from '@/lib/usage/usage-date-range'
import type { UsageDateRangePreset } from '@/lib/usage/usage-date-range-presets'

/** Match plan objects that treat log history as unlimited (~100 years). */
export const UNLIMITED_LOG_RETENTION_HOURS = 36500 * 24

/** Safety margin on API startAt so parallel requests and clock skew stay inside retention. */
export const USAGE_LOG_RETENTION_API_BUFFER_MS = 60_000

export function hasFiniteLogRetentionHours(retentionHours: number): boolean {
  return (
    Number.isFinite(retentionHours) &&
    retentionHours > 0 &&
    retentionHours < UNLIMITED_LOG_RETENTION_HOURS
  )
}

export function getLogRetentionFloor(
  retentionHours: number,
  nowMs: number = Date.now(),
): Date {
  return new Date(nowMs - retentionHours * 60 * 60 * 1000)
}

/** Earliest calendar day selectable as a range start without exceeding retention. */
export function getLogRetentionCalendarMinDate(retentionHours: number): Date {
  const floor = getLogRetentionFloor(retentionHours)
  const dayStart = startOfDay(floor)
  if (dayStart.getTime() >= floor.getTime()) {
    return dayStart
  }
  return startOfDay(addDays(floor, 1))
}

function isCalendarDateOnlySelection(dateRange: DateRange): boolean {
  const to = dateRange.to ?? dateRange.from
  if (!dateRange.from || !to) return false
  return (
    dateRange.from.getTime() === startOfDay(dateRange.from).getTime() &&
    to.getTime() === startOfDay(to).getTime()
  )
}

export function isDateRangeBeforeRetentionFloor(
  dateRange: DateRange | undefined,
  retentionHours: number,
  presetId?: string | null,
): boolean {
  if (!hasFiniteLogRetentionHours(retentionHours)) return false
  const { from } = resolveUsageChartFetchBounds(dateRange, presetId)
  return from.getTime() < getLogRetentionFloor(retentionHours).getTime()
}

export function isUsageDateRangePresetWithinRetention(
  preset: UsageDateRangePreset,
  retentionHours: number,
): boolean {
  if (!hasFiniteLogRetentionHours(retentionHours)) return true

  const range = preset.getRange()
  const clamped = clampDateRangeToRetentionFloor(range, retentionHours)
  if (!range?.from || !clamped?.from) return false

  // Only compare start bounds. Clamp may move `to` from endOfDay(today) to now for
  // calendar presets; that is expected and must not disable valid quick selects.
  const originalFromMs = resolveUsageDateBounds(range).from.getTime()
  const clampedFromMs = resolveUsageDateBounds(clamped).from.getTime()
  return originalFromMs === clampedFromMs
}

export function clampDateRangeToRetentionFloor(
  dateRange: DateRange | undefined,
  retentionHours: number,
): DateRange | undefined {
  if (!dateRange?.from || !hasFiniteLogRetentionHours(retentionHours)) {
    return dateRange
  }

  const floor = getLogRetentionFloor(retentionHours)
  const maxSpanMs = retentionHours * 60 * 60 * 1000
  const normalized = normalizeUsageDateRangeSelection(dateRange)
  if (!normalized?.from) return dateRange

  const calendarOnly = isCalendarDateOnlySelection(normalized)
  const { from, to } = resolveUsageDateBounds(normalized)

  let clampedFromMs = from.getTime()
  let clampedToMs = Math.min(to.getTime(), Date.now())
  if (clampedToMs < floor.getTime()) {
    clampedToMs = Date.now()
  }

  if (calendarOnly) {
    const minCalendar = getLogRetentionCalendarMinDate(retentionHours)
    if (clampedFromMs < minCalendar.getTime()) {
      clampedFromMs = minCalendar.getTime()
    }
  } else if (clampedFromMs < floor.getTime()) {
    clampedFromMs = floor.getTime()
  }

  if (clampedToMs - clampedFromMs > maxSpanMs) {
    clampedFromMs = clampedToMs - maxSpanMs
    if (calendarOnly) {
      const minCalendar = getLogRetentionCalendarMinDate(retentionHours)
      if (clampedFromMs < minCalendar.getTime()) {
        clampedFromMs = minCalendar.getTime()
      }
    } else if (clampedFromMs < floor.getTime()) {
      clampedFromMs = floor.getTime()
    }
  }

  if (clampedFromMs === from.getTime() && clampedToMs === to.getTime()) {
    return normalized
  }

  const clampedFrom = new Date(clampedFromMs)
  const clampedTo = new Date(clampedToMs)

  if (calendarOnly) {
    return {
      from: startOfDay(clampedFrom),
      to: endOfDay(clampedTo),
    }
  }

  return { from: clampedFrom, to: clampedTo }
}

type ClampUsageChartFetchBoundsOptions = {
  nowMs?: number
  /** Push startAt slightly past the retention floor for API calls only. */
  applyApiBuffer?: boolean
}

/** Clamp resolved API fetch bounds to the plan retention floor and max span. */
export function clampUsageChartFetchBounds(
  bounds: { from: Date; to: Date },
  retentionHours?: number,
  options?: ClampUsageChartFetchBoundsOptions,
): { from: Date; to: Date } {
  if (retentionHours == null || !hasFiniteLogRetentionHours(retentionHours)) {
    return bounds
  }

  const nowMs = options?.nowMs ?? Date.now()
  const floorMs =
    getLogRetentionFloor(retentionHours, nowMs).getTime() +
    (options?.applyApiBuffer ? USAGE_LOG_RETENTION_API_BUFFER_MS : 0)
  const maxSpanMs = retentionHours * 60 * 60 * 1000
  let fromMs = Math.max(bounds.from.getTime(), floorMs)
  let toMs = Math.min(bounds.to.getTime(), nowMs)

  if (toMs - fromMs > maxSpanMs) {
    fromMs = toMs - maxSpanMs
    if (fromMs < floorMs) {
      fromMs = floorMs
    }
  }

  return { from: new Date(fromMs), to: new Date(toMs) }
}

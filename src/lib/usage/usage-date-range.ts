import { endOfDay, startOfDay, subHours } from 'date-fns'
import type { DateRange } from 'react-day-picker'

export function isFullCalendarDayRange(from: Date, to: Date): boolean {
  return (
    from.getTime() === startOfDay(from).getTime() &&
    to.getTime() === endOfDay(to).getTime()
  )
}

/** Default overview chart range: rolling last 24 hours. */
export function getDefaultUsageChartDateRange(): DateRange {
  const now = new Date()
  return {
    from: subHours(now, 24),
    to: now,
  }
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

  const from = dateRange.from
  const to = dateRange.to ?? endOfDay(from)

  if (isFullCalendarDayRange(from, to)) {
    return { from: startOfDay(from), to: endOfDay(to) }
  }

  return { from, to }
}

export type SerializedUsageChartDateRange = {
  from: string
  to: string
}

/** Stable range for loader prefetch and View state (same query keys). */
export function serializeUsageChartDateRange(
  dateRange: DateRange,
): SerializedUsageChartDateRange {
  const { from, to } = resolveUsageDateBounds(dateRange)
  return { from: from.toISOString(), to: to.toISOString() }
}

export function parseUsageChartDateRange(
  serialized: SerializedUsageChartDateRange,
): DateRange {
  return {
    from: new Date(serialized.from),
    to: new Date(serialized.to),
  }
}

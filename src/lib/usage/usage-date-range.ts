import { endOfDay, startOfDay, subHours } from 'date-fns'
import type { DateRange } from 'react-day-picker'
import {
  findMatchingUsageDateRangePreset,
  getUsageDateRangePresetByValue,
  inferRollingPresetByDuration,
  inferUsageDateRangePresetFromStoredRange,
} from '@/lib/usage/usage-date-range-presets'

export function isStartOfLocalDay(date: Date): boolean {
  return date.getTime() === startOfDay(date).getTime()
}

export function isFullCalendarDayRange(from: Date, to: Date): boolean {
  return (
    isStartOfLocalDay(from) && to.getTime() === endOfDay(to).getTime()
  )
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
  const normalized =
    normalizeUsageDateRangeSelection(dateRange) ?? dateRange
  const matchedPreset = findMatchingUsageDateRangePreset(normalized)
  if (matchedPreset) {
    return { preset: matchedPreset.value }
  }

  const { from, to } = resolveUsageDateBounds(normalized)
  return { from: from.toISOString(), to: to.toISOString() }
}

export function parseUsageChartDateRange(
  serialized: SerializedUsageChartDateRange,
): DateRange {
  if (serialized.preset) {
    const preset = getUsageDateRangePresetByValue(serialized.preset)
    if (preset) {
      return preset.getRange()
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

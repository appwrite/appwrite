import type { DateRange } from 'react-day-picker'
import { endOfDay, startOfDay, subDays } from 'date-fns'
import {
  parseUsageChartDateRange,
  serializeUsageChartDateRange,
  type SerializedUsageChartDateRange,
} from '@/lib/usage/usage-date-range'
import type { UsageDateRangePresetContext } from '@/lib/usage/usage-date-range-presets'
import {
  parseAnalyticsChartDateRangeFromPrefs,
  parseAnalyticsChartIntervalFromPrefs,
  type AnalyticsChartIntervalPref,
  type UserPrefs,
} from '@/lib/user-prefs-keys'

/** Matches the page default: last 30 calendar days, daily buckets. */
export const ANALYTICS_DEFAULT_PRESET_ID = '30d'
export const ANALYTICS_DEFAULT_INTERVAL: AnalyticsChartIntervalPref = '1d'

export type AnalyticsChartSelection = {
  dateRange: DateRange
  /** Quick-select preset id (rolling ranges are re-anchored to now). */
  presetId: string | null
  interval: AnalyticsChartIntervalPref
}

function defaultDateRange(): DateRange {
  const now = new Date()
  return { from: startOfDay(subDays(now, 29)), to: endOfDay(now) }
}

/**
 * The analytics chart selection saved in account prefs, or the default.
 * Presets resolve to a fresh window ("Last 7 days" means the last 7 days
 * today, not the 7 days it meant when it was saved); custom ranges are kept
 * as absolute dates.
 *
 * `context.since` (the property's creation date) anchors "All time"; the
 * loader and the View pass the same value so their query keys match.
 */
export function resolveAnalyticsChartSelection(
  prefs: UserPrefs | null | undefined,
  context?: UsageDateRangePresetContext,
): AnalyticsChartSelection {
  const stored = parseAnalyticsChartDateRangeFromPrefs(prefs)
  const interval =
    parseAnalyticsChartIntervalFromPrefs(prefs) ?? ANALYTICS_DEFAULT_INTERVAL
  if (!stored) {
    return {
      dateRange: defaultDateRange(),
      presetId: ANALYTICS_DEFAULT_PRESET_ID,
      interval,
    }
  }
  return {
    dateRange: parseUsageChartDateRange(stored, context),
    presetId: stored.preset ?? null,
    interval,
  }
}

/** Stable serialized form, used to skip writes that wouldn't change prefs. */
export function serializeAnalyticsChartSelection(
  selection: Pick<AnalyticsChartSelection, 'dateRange' | 'presetId'>,
): SerializedUsageChartDateRange {
  return selection.presetId
    ? { preset: selection.presetId }
    : serializeUsageChartDateRange(selection.dateRange)
}

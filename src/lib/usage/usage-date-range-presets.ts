import {
  differenceInCalendarDays,
  endOfDay,
  isSameDay,
  startOfDay,
  subDays,
  subHours,
} from 'date-fns'
import type { DateRange } from 'react-day-picker'

function isFullCalendarDayRange(from: Date, to: Date): boolean {
  return (
    from.getTime() === startOfDay(from).getTime() &&
    to.getTime() === endOfDay(to).getTime()
  )
}

export type UsageDateRangePreset = {
  label: string
  value: string
  getRange: () => { from: Date; to: Date }
}

export type UsageDateRangePresetGroup = {
  title: string
  presets: UsageDateRangePreset[]
}

export const USAGE_DATE_RANGE_PRESET_GROUPS: UsageDateRangePresetGroup[] = [
  {
    title: 'Recent',
    presets: [
      {
        label: 'Last hour',
        value: '1h',
        getRange: () => {
          const now = new Date()
          return { from: subHours(now, 1), to: now }
        },
      },
      {
        label: 'Last 6 hours',
        value: '6h',
        getRange: () => {
          const now = new Date()
          return { from: subHours(now, 6), to: now }
        },
      },
      {
        label: 'Last 24 hours',
        value: '24h',
        getRange: () => {
          const now = new Date()
          return { from: subHours(now, 24), to: now }
        },
      },
    ],
  },
  {
    title: 'Days',
    presets: [
      {
        label: 'Today',
        value: 'today',
        getRange: () => {
          const n = new Date()
          return { from: startOfDay(n), to: endOfDay(n) }
        },
      },
      {
        label: 'Yesterday',
        value: 'yesterday',
        getRange: () => {
          const day = subDays(new Date(), 1)
          return { from: startOfDay(day), to: endOfDay(day) }
        },
      },
      {
        label: 'Last 7 days',
        value: '7d',
        getRange: () => ({
          from: startOfDay(subDays(new Date(), 6)),
          to: endOfDay(new Date()),
        }),
      },
      {
        label: 'Last 14 days',
        value: '14d',
        getRange: () => ({
          from: startOfDay(subDays(new Date(), 13)),
          to: endOfDay(new Date()),
        }),
      },
      {
        label: 'Last 30 days',
        value: '30d',
        getRange: () => ({
          from: startOfDay(subDays(new Date(), 29)),
          to: endOfDay(new Date()),
        }),
      },
    ],
  },
]

export const USAGE_DATE_RANGE_PRESETS = USAGE_DATE_RANGE_PRESET_GROUPS.flatMap(
  (group) => group.presets,
)

export const ROLLING_USAGE_DATE_RANGE_PRESET_VALUES = ['1h', '6h', '24h'] as const

export type RollingUsageDateRangePresetValue =
  (typeof ROLLING_USAGE_DATE_RANGE_PRESET_VALUES)[number]

export function isRollingUsageDateRangePresetId(
  value: string,
): value is RollingUsageDateRangePresetValue {
  return (ROLLING_USAGE_DATE_RANGE_PRESET_VALUES as readonly string[]).includes(
    value,
  )
}

const MATCH_TOLERANCE_MS = 60_000

function datesMatch(a: Date, b: Date, toleranceMs = MATCH_TOLERANCE_MS) {
  return Math.abs(a.getTime() - b.getTime()) <= toleranceMs
}

export function getUsageDateRangePresetByValue(
  value: string,
): UsageDateRangePreset | undefined {
  return USAGE_DATE_RANGE_PRESETS.find((preset) => preset.value === value)
}

/** Match rolling windows by duration only (ignores whether `to` is still "now"). */
export function inferRollingPresetByDuration(
  range: DateRange | undefined,
): UsageDateRangePreset | null {
  if (!range?.from || !range?.to) return null

  // Full calendar-day ranges (Today, Last 7 days, …) are not rolling windows.
  // Today is ~24h long and would otherwise match the rolling 24h preset.
  if (isFullCalendarDayRange(range.from, range.to)) {
    return null
  }

  const durationMs = range.to.getTime() - range.from.getTime()
  const hourMs = 60 * 60 * 1000

  for (const hours of [1, 6, 24]) {
    if (Math.abs(durationMs - hours * hourMs) <= MATCH_TOLERANCE_MS) {
      return getUsageDateRangePresetByValue(`${hours}h`) ?? null
    }
  }

  return null
}

/** Match a live range to a quick-select preset (picker UI). */
export function dateRangeMatchesUsagePreset(
  range: DateRange | undefined,
  preset: UsageDateRangePreset,
): boolean {
  if (!range?.from || !range?.to) return false

  const presetRange = preset.getRange()

  if (
    preset.value === 'today' ||
    preset.value === 'yesterday' ||
    preset.value === '7d' ||
    preset.value === '14d' ||
    preset.value === '30d'
  ) {
    return (
      range.from.getTime() === presetRange.from.getTime() &&
      range.to.getTime() === presetRange.to.getTime()
    )
  }

  if (preset.value === '1h' || preset.value === '6h' || preset.value === '24h') {
    const hours = preset.value === '1h' ? 1 : preset.value === '6h' ? 6 : 24
    const expectedDuration = hours * 60 * 60 * 1000
    const actualDuration = range.to.getTime() - range.from.getTime()
    const toIsRecent = datesMatch(range.to, new Date())

    return (
      Math.abs(actualDuration - expectedDuration) <= MATCH_TOLERANCE_MS &&
      toIsRecent
    )
  }

  return (
    datesMatch(range.from, presetRange.from) &&
    datesMatch(range.to, presetRange.to)
  )
}

export function findMatchingUsageDateRangePreset(
  range: DateRange | undefined,
): UsageDateRangePreset | null {
  if (!range?.from || !range?.to) return null

  for (const preset of USAGE_DATE_RANGE_PRESETS) {
    if (dateRangeMatchesUsagePreset(range, preset)) {
      return preset
    }
  }

  return null
}

/**
 * Infer preset from stored absolute timestamps (legacy prefs without `preset`).
 * Calendar day presets are checked before rolling windows so Today is not
 * mistaken for Last 24 hours (both are ~24h long).
 */
export function inferUsageDateRangePresetFromStoredRange(
  range: DateRange | undefined,
): UsageDateRangePreset | null {
  if (!range?.from || !range?.to) return null

  if (isFullCalendarDayRange(range.from, range.to)) {
    if (isSameDay(range.from, range.to)) {
      const now = new Date()
      if (isSameDay(range.from, now)) {
        return getUsageDateRangePresetByValue('today') ?? null
      }
      if (isSameDay(range.from, subDays(now, 1))) {
        return getUsageDateRangePresetByValue('yesterday') ?? null
      }
      return null
    }

    const daySpan = differenceInCalendarDays(range.to, range.from) + 1
    if (daySpan === 7) return getUsageDateRangePresetByValue('7d') ?? null
    if (daySpan === 14) return getUsageDateRangePresetByValue('14d') ?? null
    if (daySpan === 30) return getUsageDateRangePresetByValue('30d') ?? null

    return null
  }

  const durationMs = range.to.getTime() - range.from.getTime()
  const hourMs = 60 * 60 * 1000

  for (const hours of [1, 6, 24]) {
    if (Math.abs(durationMs - hours * hourMs) <= MATCH_TOLERANCE_MS) {
      return getUsageDateRangePresetByValue(`${hours}h`) ?? null
    }
  }

  return null
}

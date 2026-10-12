import { toPlanLimitNumber } from '@/lib/databases/backup-policy-plan-limits'
import type { Translator } from '@/lib/i18n/translate'

export type IntervalUnit = 'minutes' | 'hours' | 'days'

export const INTERVAL_UNIT_MINUTES: Record<IntervalUnit, number> = {
  minutes: 1,
  hours: 60,
  days: 1440,
}

export const DEFAULT_FUNCTION_INTERVAL = 60

export const FUNCTION_INTERVAL_PRESETS = [
  { minutes: 5, label: 'Every 5 minutes' },
  { minutes: 15, label: 'Every 15 minutes' },
  { minutes: 30, label: 'Every 30 minutes' },
  { minutes: 60, label: 'Hourly' },
  { minutes: 360, label: 'Every 6 hours' },
  { minutes: 720, label: 'Every 12 hours' },
  { minutes: 1440, label: 'Daily' },
] as const

const SINGLE_INTERVAL_LABELS: Record<IntervalUnit, string> = {
  minutes: 'Every minute',
  hours: 'Every hour',
  days: 'Every day',
}

const INTERVAL_LABELS: Record<IntervalUnit, string> = {
  minutes: 'Every {count} minutes',
  hours: 'Every {count} hours',
  days: 'Every {count} days',
}

/** Largest whole unit that expresses the interval: 360 minutes is 6 hours. */
export function splitInterval(minutes: number): {
  value: number
  unit: IntervalUnit
} {
  if (minutes > 0 && minutes % INTERVAL_UNIT_MINUTES.days === 0) {
    return { value: minutes / INTERVAL_UNIT_MINUTES.days, unit: 'days' }
  }
  if (minutes > 0 && minutes % INTERVAL_UNIT_MINUTES.hours === 0) {
    return { value: minutes / INTERVAL_UNIT_MINUTES.hours, unit: 'hours' }
  }
  return { value: minutes, unit: 'minutes' }
}

export function formatInterval(minutes: number, t: Translator): string {
  if (minutes <= 0) return t('Disabled')
  const { value, unit } = splitInterval(minutes)
  if (value === 1) return t(SINGLE_INTERVAL_LABELS[unit])
  return t(INTERVAL_LABELS[unit]).replace('{count}', String(value))
}

/** Shortest interval the plan allows, in minutes. `0` allows every interval. */
export function getFunctionIntervalMinimum(
  plan:
    | { functionsIntervalMinimum?: number | bigint | null }
    | null
    | undefined,
): number {
  return toPlanLimitNumber(plan?.functionsIntervalMinimum)
}

export function isIntervalBelowMinimum(
  minutes: number,
  minimum: number,
): boolean {
  return minimum > 0 && minutes > 0 && minutes < minimum
}

/**
 * Whether an interval can be saved. A function whose plan was downgraded may
 * keep resending its stored interval even when it is below the plan minimum.
 */
export function isIntervalAllowed(
  minutes: number,
  minimum: number,
  saved: number,
): boolean {
  if (!Number.isInteger(minutes) || minutes < 1) return false
  return minutes === saved || !isIntervalBelowMinimum(minutes, minimum)
}

export type FunctionScheduleMode = 'none' | 'interval' | 'cron'

export function getFunctionScheduleMode(func: {
  interval?: number
  schedule?: string
}): FunctionScheduleMode {
  if ((func.interval ?? 0) > 0) return 'interval'
  if (func.schedule?.trim()) return 'cron'
  return 'none'
}

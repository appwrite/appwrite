import type { Models } from '@appwrite.io/console'
import type { DateRange } from 'react-day-picker'
import {
  getLogRetentionFloor,
  isUsageDateRangePresetWithinRetention,
  UNLIMITED_LOG_RETENTION_HOURS,
} from '@/lib/date-range-retention'
import {
  getUsageDateRangePresetByValue,
  type UsageDateRangePreset,
} from '@/lib/usage/usage-date-range-presets'

/** Fallback when plan retention is unknown (Pro default). */
export const DEFAULT_ACTIVITY_LOG_RETENTION_DAYS = 30

export const DEFAULT_ACTIVITY_LOG_RETENTION_HOURS =
  DEFAULT_ACTIVITY_LOG_RETENTION_DAYS * 24

const UNLIMITED_ACTIVITY_LOG_RETENTION_THRESHOLD =
  UNLIMITED_LOG_RETENTION_HOURS / 24

export function hasFiniteActivityLogRetention(
  plan: Models.BillingPlan | null | undefined,
): boolean {
  const days = plan?.activityLogs
  if (days == null || !Number.isFinite(days) || days <= 0) {
    return true
  }
  return days < UNLIMITED_ACTIVITY_LOG_RETENTION_THRESHOLD
}

export function getActivityLogRetentionDaysFromPlan(
  plan: Models.BillingPlan | null | undefined,
): number {
  const days = plan?.activityLogs
  if (days == null || !Number.isFinite(days) || days <= 0) {
    return DEFAULT_ACTIVITY_LOG_RETENTION_DAYS
  }
  if (days >= UNLIMITED_ACTIVITY_LOG_RETENTION_THRESHOLD) {
    return UNLIMITED_ACTIVITY_LOG_RETENTION_THRESHOLD
  }
  return days
}

export function getActivityLogRetentionHoursFromPlan(
  plan: Models.BillingPlan | null | undefined,
): number {
  return getActivityLogRetentionDaysFromPlan(plan) * 24
}

export function getActivityLogRetentionFloor(
  retentionHours: number = DEFAULT_ACTIVITY_LOG_RETENTION_HOURS,
): Date {
  return getLogRetentionFloor(retentionHours)
}

/**
 * Fixed-length picker presets, longest first. WTD / MTD / Yesterday are
 * skipped: they vary by calendar and are a poor default visit window.
 */
const ACTIVITY_DEFAULT_DATE_RANGE_PRESET_CANDIDATES = [
  '30d',
  '7d',
  '24h',
  'today',
  '6h',
  '1h',
] as const

function activityRetentionHoursForDefaultRange(days: number): number {
  if (
    !Number.isFinite(days) ||
    days <= 0 ||
    days >= UNLIMITED_ACTIVITY_LOG_RETENTION_THRESHOLD
  ) {
    return DEFAULT_ACTIVITY_LOG_RETENTION_HOURS
  }
  return days * 24
}

/**
 * Longest DateRangePicker preset that is equal to or shorter than plan
 * retention, so the view opens on a quick-select instead of a custom span.
 */
export function getDefaultActivityDateRangePreset(
  days: number,
): UsageDateRangePreset {
  const retentionHours = activityRetentionHoursForDefaultRange(days)
  let best: UsageDateRangePreset | undefined
  let bestDurationMs = -1

  for (const value of ACTIVITY_DEFAULT_DATE_RANGE_PRESET_CANDIDATES) {
    const preset = getUsageDateRangePresetByValue(value)
    if (!preset) continue
    if (!isUsageDateRangePresetWithinRetention(preset, retentionHours)) {
      continue
    }
    const range = preset.getRange()
    const durationMs = range.to.getTime() - range.from.getTime()
    if (durationMs > bestDurationMs) {
      best = preset
      bestDurationMs = durationMs
    }
  }

  return best ?? getUsageDateRangePresetByValue('1h')!
}

/** Default picker range: the snapped preset's live window. */
export function getDefaultActivityDateRangeFromRetentionDays(
  days: number,
): DateRange {
  return getDefaultActivityDateRangePreset(days).getRange()
}

/**
 * Human-readable retention label from plan days (English source for `t()`).
 * Sub-day values (e.g. free = 1/24 day) become hours.
 */
export function formatActivityLogRetentionLabel(days: number): string {
  if (
    !Number.isFinite(days) ||
    days <= 0 ||
    days >= UNLIMITED_ACTIVITY_LOG_RETENTION_THRESHOLD
  ) {
    return 'Unlimited'
  }

  const hoursExact = days * 24
  if (hoursExact < 24) {
    const hours = Math.max(1, Math.round(hoursExact))
    return hours === 1 ? '1 hour' : `${hours} hours`
  }

  const wholeDays = Math.max(1, Math.round(days))
  return wholeDays === 1 ? '1 day' : `${wholeDays} days`
}

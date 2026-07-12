import type { Models } from '@appwrite.io/console'
import type { DateRange } from 'react-day-picker'
import { endOfDay, startOfDay, subDays, subHours } from 'date-fns'

/** Fallback when plan retention is unknown (Pro default). */
export const DEFAULT_ACTIVITY_LOG_RETENTION_DAYS = 30

export const DEFAULT_ACTIVITY_LOG_RETENTION_HOURS =
  DEFAULT_ACTIVITY_LOG_RETENTION_DAYS * 24

const UNLIMITED_ACTIVITY_LOG_RETENTION_THRESHOLD = 36500

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
  return new Date(Date.now() - retentionHours * 60 * 60 * 1000)
}

/**
 * Default picker range for plan retention days.
 *
 * Matches DateRangePicker quick-select math:
 * - Sub-day: rolling `subHours(now, hours)` → `now` (e.g. Last hour)
 * - Whole days: inclusive calendar window `subDays(now, days - 1)` → today
 *   (e.g. 30 days → Last 30 days uses offset 29, not 30×24h)
 */
export function getDefaultActivityDateRangeFromRetentionDays(
  days: number,
): DateRange {
  const now = new Date()
  const safeDays =
    !Number.isFinite(days) || days <= 0
      ? DEFAULT_ACTIVITY_LOG_RETENTION_DAYS
      : days >= UNLIMITED_ACTIVITY_LOG_RETENTION_THRESHOLD
        ? DEFAULT_ACTIVITY_LOG_RETENTION_DAYS
        : days

  const hoursExact = safeDays * 24
  if (hoursExact < 24) {
    const hours = Math.max(1, Math.round(hoursExact))
    return { from: subHours(now, hours), to: now }
  }

  const wholeDays = Math.max(1, Math.round(safeDays))
  return {
    from: startOfDay(subDays(now, wholeDays - 1)),
    to: endOfDay(now),
  }
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

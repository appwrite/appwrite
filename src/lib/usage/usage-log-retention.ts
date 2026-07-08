import type { Models } from '@appwrite.io/console'
import type { DateRange } from 'react-day-picker'
import { resolveUsageChartFetchBounds } from '@/lib/usage/usage-date-range'

/** Fallback when plan retention is unknown (Pro default). */
export const DEFAULT_USAGE_LOG_RETENTION_DAYS = 30

export const DEFAULT_USAGE_LOG_RETENTION_HOURS =
  DEFAULT_USAGE_LOG_RETENTION_DAYS * 24

const UNLIMITED_USAGE_LOG_RETENTION_THRESHOLD = 36500

export function hasFiniteUsageLogRetention(
  plan: Models.BillingPlan | null | undefined,
): boolean {
  const days = plan?.usageLogs
  if (days == null || !Number.isFinite(days) || days <= 0) {
    return true
  }
  return days < UNLIMITED_USAGE_LOG_RETENTION_THRESHOLD
}

export function getUsageLogRetentionDaysFromPlan(
  plan: Models.BillingPlan | null | undefined,
): number {
  const days = plan?.usageLogs
  if (days == null || !Number.isFinite(days) || days <= 0) {
    return DEFAULT_USAGE_LOG_RETENTION_DAYS
  }
  if (days >= UNLIMITED_USAGE_LOG_RETENTION_THRESHOLD) {
    return UNLIMITED_USAGE_LOG_RETENTION_THRESHOLD
  }
  return days
}

export function getUsageLogRetentionHoursFromPlan(
  plan: Models.BillingPlan | null | undefined,
): number {
  return getUsageLogRetentionDaysFromPlan(plan) * 24
}

export function getUsageLogRetentionFloor(
  retentionHours: number = DEFAULT_USAGE_LOG_RETENTION_HOURS,
): Date {
  return new Date(Date.now() - retentionHours * 60 * 60 * 1000)
}

export function isUsageDateRangeBeyondRetention(
  dateRange: DateRange | undefined,
  retentionHours: number = DEFAULT_USAGE_LOG_RETENTION_HOURS,
  presetId?: string | null,
): boolean {
  if (retentionHours <= 0) return false
  const { from } = resolveUsageChartFetchBounds(dateRange, presetId)
  return from.getTime() < getUsageLogRetentionFloor(retentionHours).getTime()
}

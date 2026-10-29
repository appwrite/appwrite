import type { Models } from '@appwrite.io/console'
import type { DateRange } from 'react-day-picker'
import {
  clampUsageChartFetchBounds,
  getLogRetentionFloor,
  hasFiniteLogRetentionHours,
  isDateRangeBeforeRetentionFloor,
  UNLIMITED_LOG_RETENTION_HOURS,
} from '@/lib/date-range-retention'
import {
  getUsageDateRangePresetByValue,
  type UsageDateRangePreset,
} from '@/lib/usage/usage-date-range-presets'
import { resolveUsageChartFetchBounds } from '@/lib/usage/usage-date-range'

/** Fallback when plan retention is unknown (Pro default). */
export const DEFAULT_USAGE_LOG_RETENTION_DAYS = 30

export const DEFAULT_USAGE_LOG_RETENTION_HOURS =
  DEFAULT_USAGE_LOG_RETENTION_DAYS * 24

const UNLIMITED_USAGE_LOG_RETENTION_THRESHOLD =
  UNLIMITED_LOG_RETENTION_HOURS / 24

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
  nowMs?: number,
): Date {
  return getLogRetentionFloor(retentionHours, nowMs)
}

/** Whether the clamped chart range still fits within plan retention. */
export function isClampedUsageDateRangeWithinRetention(
  dateRange: DateRange | undefined,
  retentionHours: number = DEFAULT_USAGE_LOG_RETENTION_HOURS,
  presetId?: string | null,
): boolean {
  if (!hasFiniteLogRetentionHours(retentionHours)) return true

  const { from } = clampUsageChartFetchBounds(
    resolveUsageChartFetchBounds(dateRange, presetId),
    retentionHours,
  )

  return from.getTime() >= getLogRetentionFloor(retentionHours).getTime()
}

/** Hours from retention floor treated as "at the limit" for the upgrade banner. */
const USAGE_RETENTION_NEAR_FLOOR_TOLERANCE_HOURS = 24

/** Minimum share of plan retention a range must span to count as maxed out. */
const USAGE_RETENTION_NEAR_LIMIT_SPAN_RATIO = 0.9

/** Selected range uses most of the plan retention window (valid but at the limit). */
export function isUsageDateRangeNearRetentionLimit(
  dateRange: DateRange | undefined,
  retentionHours: number = DEFAULT_USAGE_LOG_RETENTION_HOURS,
  presetId?: string | null,
): boolean {
  if (!hasFiniteLogRetentionHours(retentionHours)) return false

  const { from, to } = clampUsageChartFetchBounds(
    resolveUsageChartFetchBounds(dateRange, presetId),
    retentionHours,
  )
  const floor = getLogRetentionFloor(retentionHours)
  const spanHours = (to.getTime() - from.getTime()) / (60 * 60 * 1000)
  const hoursFromFloor = (from.getTime() - floor.getTime()) / (60 * 60 * 1000)

  const startsNearFloor =
    hoursFromFloor >= 0 &&
    hoursFromFloor <= USAGE_RETENTION_NEAR_FLOOR_TOLERANCE_HOURS
  const spansMostOfRetention =
    spanHours >= retentionHours * USAGE_RETENTION_NEAR_LIMIT_SPAN_RATIO

  return startsNearFloor || spansMostOfRetention
}

const SHORTER_USAGE_DATE_RANGE_PRESET_CANDIDATES = [
  '30d',
  'mtd',
  'wtd',
  '7d',
  'yesterday',
  'today',
  '24h',
  '6h',
  '1h',
] as const

/** Longest preset that fits within plan retention (for "Use shorter range" CTA). */
export function resolveShorterUsageDateRangePreset(
  retentionHours: number = DEFAULT_USAGE_LOG_RETENTION_HOURS,
): UsageDateRangePreset | undefined {
  for (const value of SHORTER_USAGE_DATE_RANGE_PRESET_CANDIDATES) {
    const preset = getUsageDateRangePresetByValue(value)
    if (!preset) continue
    if (
      !isDateRangeBeforeRetentionFloor(
        preset.getRange(),
        retentionHours,
        preset.value,
      )
    ) {
      return preset
    }
  }

  return getUsageDateRangePresetByValue('24h')
}

/**
 * Shared value formatting for the analytics screens.
 *
 * Nullish input is accepted because the API only populates the session-shaped
 * measures on the flat aggregate; on breakdown and time-series rows they are
 * null.
 */

const COMPACT_NUMBER_THRESHOLD = 1000

export function formatNumber(num: number | null | undefined): string {
  if (num == null || !Number.isFinite(num)) return '0'
  if (Math.abs(num) >= 1000000) {
    return (num / 1000000).toFixed(1) + 'M'
  }
  if (Math.abs(num) >= COMPACT_NUMBER_THRESHOLD) {
    return (num / 1000).toFixed(1) + 'K'
  }
  return String(Math.round(num))
}

/** Full digit grouping, for tooltips on compact K/M values. */
export function formatExactNumber(num: number | null | undefined): string {
  if (num == null || !Number.isFinite(num)) return '0'
  return Math.round(num).toLocaleString()
}

/** True when `formatNumber()` abbreviates with K/M. */
export function isCompactNumber(
  num: number | null | undefined,
): num is number {
  return (
    num != null &&
    Number.isFinite(num) &&
    Math.abs(num) >= COMPACT_NUMBER_THRESHOLD
  )
}

/** `visitDuration` / `engagementTime` are seconds. */
export function formatDuration(seconds: number | null | undefined): string {
  if (seconds == null || !Number.isFinite(seconds) || seconds <= 0) return '0s'
  const total = Math.round(seconds)
  const minutes = Math.floor(total / 60)
  const rest = total % 60
  return minutes > 0 ? `${minutes}m ${rest}s` : `${rest}s`
}

/** Percentages come back as 0-100 numbers. */
export function formatPercent(value: number | null | undefined): string {
  if (value == null || !Number.isFinite(value)) return '0%'
  return `${value.toFixed(1)}%`
}

export function formatRatio(value: number | null | undefined): string {
  if (value == null || !Number.isFinite(value)) return '0'
  return value.toFixed(1)
}

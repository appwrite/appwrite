/** Shared value formatting for the analytics screens. */

export function formatNumber(num: number): string {
  if (!Number.isFinite(num)) return '0'
  if (Math.abs(num) >= 1000000) {
    return (num / 1000000).toFixed(1) + 'M'
  }
  if (Math.abs(num) >= 1000) {
    return (num / 1000).toFixed(1) + 'K'
  }
  return String(Math.round(num))
}

/** `visitDuration` / `engagementTime` are seconds. */
export function formatDuration(seconds: number): string {
  if (!Number.isFinite(seconds) || seconds <= 0) return '0s'
  const total = Math.round(seconds)
  const minutes = Math.floor(total / 60)
  const rest = total % 60
  return minutes > 0 ? `${minutes}m ${rest}s` : `${rest}s`
}

/** Percentages come back as 0-100 numbers. */
export function formatPercent(value: number): string {
  if (!Number.isFinite(value)) return '0%'
  return `${value.toFixed(1)}%`
}

export function formatRatio(value: number): string {
  if (!Number.isFinite(value)) return '0'
  return value.toFixed(1)
}

const BYTE_BASE = 1000
const BYTE_UNITS = ['B', 'KB', 'MB', 'GB', 'TB'] as const

type ByteUnit = (typeof BYTE_UNITS)[number]

function getByteUnitIndex(bytes: number): number {
  if (bytes <= 0) return 0
  return Math.min(
    Math.floor(Math.log(bytes) / Math.log(BYTE_BASE)),
    BYTE_UNITS.length - 1,
  )
}

function trimTrailingZeros(value: string): string {
  return value.replace(/(\.\d*?)0+$/, '$1').replace(/\.$/, '')
}

/** Decimal places by unit - smaller units keep a fractional digit for readability. */
function getByteDecimals(scaled: number, unit: ByteUnit): number {
  if (unit === 'B') return 0
  if (unit === 'KB' || unit === 'MB') return 1
  if (scaled >= 100) return 0
  if (scaled >= 10) return 1
  return 2
}

export interface FormatCompactBytesOptions {
  /** When false, omits the space between value and unit (e.g. `12.5MB`). Default false. */
  compact?: boolean
}

/**
 * Compact byte formatter for overview KPIs, lists, and charts.
 * KB/MB always show at least one decimal when the scaled value is fractional;
 * whole MB/KB values show a single decimal (e.g. `12.0MB`).
 */
export function formatCompactBytes(
  bytes: number,
  options: FormatCompactBytesOptions = {},
): string {
  const { compact = true } = options
  const separator = compact ? '' : ' '

  if (!Number.isFinite(bytes) || bytes <= 0) {
    return compact ? '0B' : '0 B'
  }

  const unitIndex = getByteUnitIndex(bytes)
  const unit = BYTE_UNITS[unitIndex]
  const scaled = bytes / BYTE_BASE ** unitIndex
  const decimals = getByteDecimals(scaled, unit)

  let amount: string
  if (unit === 'KB' || unit === 'MB') {
    amount = scaled.toFixed(1)
  } else {
    amount = trimTrailingZeros(scaled.toFixed(decimals))
  }

  return `${amount}${separator}${unit}`
}

const BYTE_AXIS_SHORT_UNITS: Record<ByteUnit, string> = {
  B: 'B',
  KB: 'KB',
  MB: 'MB',
  GB: 'GB',
  TB: 'TB',
}

/**
 * Y-axis tick formatter that keeps every tick in the same unit (based on chart max)
 * so labels stay ordered and readable, e.g. `0MB`, `500KB`, `1.2MB`, `2.4MB`.
 */
export function createCompactBytesAxisTickFormatter(referenceBytes: number) {
  const unitIndex =
    !Number.isFinite(referenceBytes) || referenceBytes <= 0
      ? 2
      : getByteUnitIndex(referenceBytes)
  const shortUnit = BYTE_AXIS_SHORT_UNITS[BYTE_UNITS[unitIndex]]
  const divisor = BYTE_BASE ** unitIndex

  return (bytes: number): string => {
    if (!Number.isFinite(bytes) || bytes <= 0) {
      return `0${shortUnit}`
    }

    const scaled = bytes / divisor

    if (unitIndex === 0) {
      return `${Math.round(scaled)}${shortUnit}`
    }

    if (scaled >= 100) {
      return `${Math.round(scaled)}${shortUnit}`
    }

    return `${trimTrailingZeros(scaled.toFixed(1))}${shortUnit}`
  }
}

/** Short Y-axis labels: `12.5MB`, `1.2GB`, `800KB`. */
export function formatCompactBytesAxis(bytes: number): string {
  const label = formatCompactBytes(bytes, { compact: true })
  return label.replace(/(\d)\.0(?=[KMGTP]B$)/, '$1')
}

export interface FormatCompactCountOptions {
  compact?: boolean
}

/** Compact count formatter for requests and similar metrics (`1.2M`, `12.5K`). */
export function formatCompactCount(
  num: number,
  options: FormatCompactCountOptions = {},
): string {
  const { compact = true } = options

  if (!Number.isFinite(num) || num <= 0) {
    return '0'
  }

  if (num >= 1_000_000_000) {
    const scaled = num / 1_000_000_000
    const amount =
      scaled >= 100
        ? Math.round(scaled).toString()
        : trimTrailingZeros(scaled.toFixed(1))
    return compact ? `${amount}B` : `${amount} B`
  }

  if (num >= 1_000_000) {
    const scaled = num / 1_000_000
    const amount =
      scaled >= 100
        ? Math.round(scaled).toString()
        : trimTrailingZeros(scaled.toFixed(1))
    return compact ? `${amount}M` : `${amount} M`
  }

  if (num >= 10_000) {
    const scaled = num / 1_000
    const amount =
      scaled >= 100
        ? Math.round(scaled).toString()
        : trimTrailingZeros(scaled.toFixed(1))
    return compact ? `${amount}K` : `${amount} K`
  }

  if (num >= 1_000) {
    return compact
      ? `${trimTrailingZeros((num / 1_000).toFixed(1))}K`
      : `${trimTrailingZeros((num / 1_000).toFixed(1))} K`
  }

  return Math.round(num).toLocaleString()
}

/** Axis-friendly count labels. */
export function formatCompactCountAxis(num: number): string {
  return formatCompactCount(num, { compact: true })
}

type CountAxisUnit = 'plain' | 'K' | 'M' | 'B'

function getCountAxisUnit(max: number): CountAxisUnit {
  if (!Number.isFinite(max) || max <= 0) return 'plain'
  if (max >= 1_000_000_000) return 'B'
  if (max >= 1_000_000) return 'M'
  if (max >= 1_000) return 'K'
  return 'plain'
}

/**
 * Y-axis tick formatter that keeps every tick in the same unit (based on chart max).
 */
export function createCompactCountAxisTickFormatter(referenceCount: number) {
  const unit = getCountAxisUnit(referenceCount)
  const divisor =
    unit === 'B'
      ? 1_000_000_000
      : unit === 'M'
        ? 1_000_000
        : unit === 'K'
          ? 1_000
          : 1
  const suffix = unit === 'plain' ? '' : unit

  return (num: number): string => {
    if (!Number.isFinite(num) || num <= 0) {
      return unit === 'plain' ? '0' : `0${suffix}`
    }

    if (unit === 'plain') {
      return Math.round(num).toLocaleString()
    }

    const scaled = num / divisor

    if (scaled >= 100) {
      return `${Math.round(scaled)}${suffix}`
    }

    return `${trimTrailingZeros(scaled.toFixed(1))}${suffix}`
  }
}

/** Short Y-axis labels for GB-hours. */
export function createGbHoursAxisTickFormatter(referenceGbHours: number) {
  const useK = Number.isFinite(referenceGbHours) && referenceGbHours >= 1_000

  return (gbHours: number): string => {
    if (!Number.isFinite(gbHours) || gbHours <= 0) {
      return '0'
    }

    if (useK) {
      const scaled = gbHours / 1_000
      if (scaled >= 100) {
        return `${Math.round(scaled)}k`
      }
      return trimGbHoursValue(scaled) + 'k'
    }

    return trimGbHoursValue(gbHours)
  }
}

export type UsageChartAxisFormat = 'count' | 'bytes' | 'gbhours'

export function createUsageChartAxisTickFormatter(
  format: UsageChartAxisFormat,
  referenceMax: number,
) {
  switch (format) {
    case 'bytes':
      return createCompactBytesAxisTickFormatter(referenceMax)
    case 'gbhours':
      return createGbHoursAxisTickFormatter(referenceMax)
    case 'count':
    default:
      return createCompactCountAxisTickFormatter(referenceMax)
  }
}

export function getChartSeriesMax(
  points: readonly { total?: number; value?: number }[],
): number {
  return points.reduce((max, point) => {
    const value = point.total ?? point.value ?? 0
    return Math.max(max, value)
  }, 0)
}

/** MB-seconds to GB-hours (same divisor as cloud billing aggregation). */
export const MB_SECONDS_PER_GB_HOUR = 1000 * 3600

export function mbSecondsToGbHours(mbSeconds: number): number {
  if (!Number.isFinite(mbSeconds) || mbSeconds <= 0) return 0
  return mbSeconds / MB_SECONDS_PER_GB_HOUR
}

function trimGbHoursValue(gbHours: number): string {
  if (gbHours >= 100) return Math.round(gbHours).toLocaleString()
  if (gbHours >= 10) return gbHours.toFixed(1).replace(/\.0$/, '')
  if (gbHours >= 1) return gbHours.toFixed(1)
  if (gbHours >= 0.01) return gbHours.toFixed(2)
  return gbHours.toFixed(3).replace(/0+$/, '').replace(/\.$/, '')
}

export const GB_HOURS_UNIT_SHORT = 'GBH'

export const GB_HOURS_UNIT_TOOLTIP =
  'GB hours (GBH). Compute time based on memory allocated to functions and sites multiplied by execution duration.'

function formatGbHoursCore(gbHours: number): string {
  if (!Number.isFinite(gbHours) || gbHours <= 0) return '0'
  if (gbHours >= 1_000) {
    return `${trimGbHoursValue(gbHours / 1_000)}k`
  }
  return trimGbHoursValue(gbHours)
}

/** Compact GB-hours formatter for overview KPIs, lists, and charts. */
export function formatGbHoursTotal(gbHours: number): string {
  return `${formatGbHoursCore(gbHours)}${GB_HOURS_UNIT_SHORT}`
}

export function formatGbHoursValue(gbHours: number): string {
  return formatGbHoursTotal(gbHours)
}

/** Short Y-axis labels for GB-hours. */
export function formatGbHoursAxisValue(gbHours: number): string {
  return formatGbHoursCore(gbHours)
}

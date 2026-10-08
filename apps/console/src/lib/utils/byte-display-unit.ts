import {
  pickUnitWithLargestReadableValue,
  pickUnitWithSmallestIntegerValue,
  type UnitScale,
} from '@/lib/utils/pick-display-unit'

export type DecimalByteUnit = 'bytes' | 'KB' | 'MB' | 'GB' | 'TB'
export type BinaryByteUnit = 'bytes' | 'kb' | 'mb' | 'gb' | 'tb'

/**
 * Coerce API byte counts that may arrive as bigint (SDK json-bigint / int64).
 * Arithmetic with mixed bigint/number throws; convert before formatting or math.
 */
export function toByteCount(
  value: number | bigint | null | undefined,
): number {
  if (value == null) return 0
  if (typeof value === 'bigint') {
    const n = Number(value)
    return Number.isFinite(n) ? n : 0
  }
  return Number.isFinite(value) ? value : 0
}

const DECIMAL_BYTE_SCALES: UnitScale<DecimalByteUnit>[] = [
  { unit: 'bytes', factor: 1 },
  { unit: 'KB', factor: 1_000 },
  { unit: 'MB', factor: 1_000 ** 2 },
  { unit: 'GB', factor: 1_000 ** 3 },
  { unit: 'TB', factor: 1_000 ** 4 },
]

const BINARY_BYTE_SCALES: UnitScale<BinaryByteUnit>[] = [
  { unit: 'bytes', factor: 1 },
  { unit: 'kb', factor: 1024 },
  { unit: 'mb', factor: 1024 ** 2 },
  { unit: 'gb', factor: 1024 ** 3 },
  { unit: 'tb', factor: 1024 ** 4 },
]

const FORM_DECIMAL_BYTE_SCALES = DECIMAL_BYTE_SCALES.filter(
  (scale) => scale.unit !== 'TB',
)

export function pickDecimalByteDisplayUnit(bytes: number): {
  value: number
  unit: DecimalByteUnit
} {
  return pickUnitWithSmallestIntegerValue(bytes, DECIMAL_BYTE_SCALES)
}

/** Same as pickDecimalByteDisplayUnit but capped at GB (for unit dropdowns without TB). */
export function pickFormDecimalByteDisplayUnit(bytes: number): {
  value: number
  unit: Exclude<DecimalByteUnit, 'TB'>
} {
  return pickUnitWithSmallestIntegerValue(bytes, FORM_DECIMAL_BYTE_SCALES)
}

export function pickBinaryByteDisplayUnit(bytes: number): {
  value: number
  unit: BinaryByteUnit
} {
  return pickUnitWithSmallestIntegerValue(bytes, BINARY_BYTE_SCALES)
}

function formatByteDisplayValue(
  value: number,
  unitLabel: string,
  isBytesUnit: boolean,
): string {
  if (isBytesUnit) {
    return `${Math.round(value)} ${unitLabel}`
  }

  if (Number.isInteger(value)) {
    return `${value} ${unitLabel}`
  }

  return `${parseFloat(value.toFixed(2))} ${unitLabel}`
}

export function formatDecimalBytes(bytes: number | bigint): string {
  const n = toByteCount(bytes)
  if (n <= 0) return '0 B'

  const { value, unit } = pickUnitWithLargestReadableValue(
    n,
    DECIMAL_BYTE_SCALES,
  )
  const label = unit === 'bytes' ? 'B' : unit

  return formatByteDisplayValue(value, label, unit === 'bytes')
}

export function formatBinaryBytes(bytes: number | bigint): string {
  const n = toByteCount(bytes)
  if (n < 0) return String(bytes)
  if (n === 0) return '0 B'

  const { value, unit } = pickUnitWithLargestReadableValue(
    n,
    BINARY_BYTE_SCALES,
  )
  const label = unit === 'bytes' ? 'B' : unit.toUpperCase()

  return formatByteDisplayValue(value, label, unit === 'bytes')
}

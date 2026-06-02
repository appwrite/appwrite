import {
  pickUnitWithSmallestIntegerValue,
  type UnitScale,
} from '@/lib/utils/pick-display-unit'

export type DecimalByteUnit = 'bytes' | 'KB' | 'MB' | 'GB' | 'TB'
export type BinaryByteUnit = 'bytes' | 'kb' | 'mb' | 'gb' | 'tb'

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

export function formatDecimalBytes(bytes: number): string {
  if (!Number.isFinite(bytes) || bytes <= 0) return '0 B'

  const { value, unit } = pickDecimalByteDisplayUnit(bytes)
  const label =
    unit === 'bytes' ? 'B' : unit

  if (unit === 'bytes') {
    return `${Math.round(value)} ${label}`
  }

  if (Number.isInteger(value)) {
    return `${value} ${label}`
  }

  return `${parseFloat(value.toFixed(2))} ${label}`
}

export function formatBinaryBytes(bytes: number): string {
  if (!Number.isFinite(bytes) || bytes < 0) return String(bytes)
  if (bytes === 0) return '0 B'

  const { value, unit } = pickBinaryByteDisplayUnit(bytes)
  const label = unit === 'bytes' ? 'B' : unit.toUpperCase()

  if (Number.isInteger(value)) {
    return `${value} ${label}`
  }

  return `${parseFloat(value.toFixed(2))} ${label}`
}

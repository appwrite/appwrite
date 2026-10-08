/**
 * Helpers for filter columns with format "size" (e.g. file size in bytes).
 */

import { pickBinaryByteDisplayUnit } from '@/lib/utils/byte-display-unit'

export const SIZE_FILTER_UNITS = [
  { value: 'bytes', label: 'Bytes' },
  { value: 'kb', label: 'KB' },
  { value: 'mb', label: 'MB' },
  { value: 'gb', label: 'GB' },
  { value: 'tb', label: 'TB' },
] as const

export function sizeFilterToBytes(value: number, unit: string): number {
  const k = 1024
  const factors: Record<string, number> = {
    bytes: 1,
    kb: k,
    mb: k * k,
    gb: k * k * k,
    tb: k * k * k * k,
  }
  const factor = factors[unit.toLowerCase()] ?? 1
  return Math.round(value * factor)
}

/** Convert stored byte count to a human amount + unit for the size filter inputs. */
export function bytesToSizeFilterInput(bytes: number): {
  value: string
  unit: string
} {
  if (!Number.isFinite(bytes) || bytes < 0) {
    return { value: String(bytes), unit: 'bytes' }
  }
  const { value, unit } = pickBinaryByteDisplayUnit(bytes)
  const rounded =
    Number.isInteger(value) ? String(value) : String(parseFloat(value.toFixed(6)))
  return { value: rounded, unit }
}

/**
 * Helpers for filter columns with format "size" (e.g. file size in bytes).
 */

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

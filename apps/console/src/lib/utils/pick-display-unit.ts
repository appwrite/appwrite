/**
 * When a quantity can be expressed in multiple units, pick the unit whose numeric
 * value is smallest while still a whole integer (e.g. 86400s → 1 day, not 24 hours).
 */

export type UnitScale<T extends string = string> = {
  unit: T
  /** Base units per 1 of this unit (e.g. seconds: 1, minutes: 60). */
  factor: number
}

const DEFAULT_INTEGER_TOLERANCE = 1e-9

export function isIntegerValue(
  value: number,
  tolerance = DEFAULT_INTEGER_TOLERANCE,
): boolean {
  if (!Number.isFinite(value)) return false
  return Math.abs(value - Math.round(value)) < tolerance
}

export type PickDisplayUnitOptions = {
  /** Minimum converted value to consider (default 1). */
  minConvertedValue?: number
  tolerance?: number
}

/**
 * Pick the unit whose numeric value is smallest while still a whole integer.
 * Checks units from largest to smallest factor.
 */
export function pickUnitWithSmallestIntegerValue<T extends string>(
  baseValue: number,
  scales: readonly UnitScale<T>[],
  options: PickDisplayUnitOptions = {},
): { value: number; unit: T } {
  const minConverted = options.minConvertedValue ?? 1
  const tolerance = options.tolerance ?? DEFAULT_INTEGER_TOLERANCE

  if (scales.length === 0) {
    throw new Error('pickUnitWithSmallestIntegerValue requires at least one scale')
  }

  const smallestScale = scales.reduce((a, b) => (a.factor <= b.factor ? a : b))

  if (!Number.isFinite(baseValue) || baseValue <= 0) {
    return { value: 0, unit: smallestScale.unit }
  }

  const sorted = [...scales].sort((a, b) => b.factor - a.factor)

  for (const scale of sorted) {
    const converted = baseValue / scale.factor
    if (
      converted >= minConverted &&
      isIntegerValue(converted, tolerance)
    ) {
      return { value: Math.round(converted), unit: scale.unit }
    }
  }

  const converted = baseValue / smallestScale.factor
  return {
    value: isIntegerValue(converted, tolerance)
      ? Math.round(converted)
      : Math.round(converted * 100) / 100,
    unit: smallestScale.unit,
  }
}

/**
 * Pick the largest unit whose converted value is at least minConvertedValue (default 1).
 * Example: 999999 bytes → ~1000 KB, not 999999 B.
 */
export function pickUnitWithLargestReadableValue<T extends string>(
  baseValue: number,
  scales: readonly UnitScale<T>[],
  options: PickDisplayUnitOptions = {},
): { value: number; unit: T } {
  const minConverted = options.minConvertedValue ?? 1

  if (scales.length === 0) {
    throw new Error('pickUnitWithLargestReadableValue requires at least one scale')
  }

  const smallestScale = scales.reduce((a, b) => (a.factor <= b.factor ? a : b))

  if (!Number.isFinite(baseValue) || baseValue <= 0) {
    return { value: 0, unit: smallestScale.unit }
  }

  const sorted = [...scales].sort((a, b) => b.factor - a.factor)

  for (const scale of sorted) {
    if (scale.factor <= smallestScale.factor) continue
    const converted = baseValue / scale.factor
    if (converted >= minConverted) {
      return { value: converted, unit: scale.unit }
    }
  }

  return { value: baseValue / smallestScale.factor, unit: smallestScale.unit }
}

/**
 * Time unit conversion utilities
 *
 * Converts between different time units (seconds, minutes, hours, days, weeks, months, years)
 * and seconds (the base unit used by the API).
 */

import { pickUnitWithSmallestIntegerValue } from '@/lib/utils/pick-display-unit'

export type TimeUnit =
  | 'seconds'
  | 'minutes'
  | 'hours'
  | 'days'
  | 'weeks'
  | 'months'
  | 'years'

export interface TimeUnitPair {
  value: number
  unit: TimeUnit
}

const TIME_UNIT_TO_SECONDS: Record<TimeUnit, number> = {
  seconds: 1,
  minutes: 60,
  hours: 60 * 60,
  days: 24 * 60 * 60,
  weeks: 7 * 24 * 60 * 60,
  months: 30 * 24 * 60 * 60, // Approximate: 30 days
  years: 365 * 24 * 60 * 60, // Approximate: 365 days
}

const DEFAULT_TIME_UNITS: TimeUnit[] = [
  'years',
  'months',
  'weeks',
  'days',
  'hours',
  'minutes',
  'seconds',
]

/**
 * Converts a time value from a given unit to seconds
 */
export function toSeconds(value: number, unit: TimeUnit): number {
  return value * TIME_UNIT_TO_SECONDS[unit]
}

/**
 * Converts seconds to a time value in a given unit
 */
export function fromSeconds(seconds: number, unit: TimeUnit): number {
  return seconds / TIME_UNIT_TO_SECONDS[unit]
}

/**
 * Creates a time unit pair from seconds, picking the unit whose numeric value is
 * smallest while still a whole integer (e.g. 86400s → 1 day, not 24 hours).
 */
export function createTimeUnitPair(
  seconds: number,
  options?: { units?: readonly TimeUnit[] },
): TimeUnitPair {
  const units = options?.units ?? DEFAULT_TIME_UNITS
  const scales = units.map((unit) => ({
    unit,
    factor: TIME_UNIT_TO_SECONDS[unit],
  }))
  return pickUnitWithSmallestIntegerValue(seconds, scales)
}

/**
 * Formats a time unit pair as a human-readable string
 */
export function formatTimeUnitPair(pair: TimeUnitPair): string {
  const unitLabel =
    pair.value === 1
      ? pair.unit.slice(0, -1) // Remove 's' for singular
      : pair.unit
  return `${pair.value} ${unitLabel}`
}

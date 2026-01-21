/**
 * Time unit conversion utilities
 *
 * Converts between different time units (seconds, minutes, hours, days, weeks, months, years)
 * and seconds (the base unit used by the API).
 */

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
 * Creates a time unit pair from seconds, automatically selecting the best unit
 * to represent the duration in a human-readable format
 */
export function createTimeUnitPair(seconds: number): TimeUnitPair {
  if (seconds === 0) {
    return { value: 0, unit: 'seconds' }
  }

  // Try to find the best unit that gives a whole number or reasonable decimal
  const units: TimeUnit[] = [
    'years',
    'months',
    'weeks',
    'days',
    'hours',
    'minutes',
    'seconds',
  ]

  for (const unit of units) {
    const value = fromSeconds(seconds, unit)
    // If the value is >= 1 and reasonably whole (within 0.01), use this unit
    if (value >= 1 && Math.abs(value - Math.round(value)) < 0.01) {
      return { value: Math.round(value), unit }
    }
    // If value is >= 0.1, use this unit even if not perfectly whole
    if (value >= 0.1) {
      return { value: Math.round(value * 100) / 100, unit }
    }
  }

  // Fallback to seconds
  return { value: seconds, unit: 'seconds' }
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

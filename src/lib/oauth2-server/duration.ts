export type OAuth2ServerTimeUnit = 'seconds' | 'minutes' | 'hours' | 'days'

const MULTIPLIERS: Record<OAuth2ServerTimeUnit, number> = {
  seconds: 1,
  minutes: 60,
  hours: 3600,
  days: 86400,
}

export const OAUTH2_SERVER_TIME_UNIT_OPTIONS: {
  value: OAuth2ServerTimeUnit
  label: string
}[] = [
  { value: 'seconds', label: 'Seconds' },
  { value: 'minutes', label: 'Minutes' },
  { value: 'hours', label: 'Hours' },
  { value: 'days', label: 'Days' },
]

export function oauth2DurationFromSeconds(
  seconds: number | null | undefined,
  defaultUnit: OAuth2ServerTimeUnit = 'hours',
): { value: number | null; unit: OAuth2ServerTimeUnit } {
  if (seconds == null) return { value: null, unit: defaultUnit }
  if (seconds % 86400 === 0) return { value: seconds / 86400, unit: 'days' }
  if (seconds % 3600 === 0) return { value: seconds / 3600, unit: 'hours' }
  if (seconds % 60 === 0) return { value: seconds / 60, unit: 'minutes' }
  return { value: seconds, unit: 'seconds' }
}

export function oauth2DurationToSeconds(
  value: number | null,
  unit: OAuth2ServerTimeUnit,
): number | null {
  return value != null ? value * MULTIPLIERS[unit] : null
}

export type ZonedDateTimeParts = {
  year: number
  month: number
  day: number
  hour: number
  minute: number
  second: number
}

function partMap(date: Date, timeZone: string): Record<string, string> {
  const parts = new Intl.DateTimeFormat('en-US', {
    timeZone,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    hourCycle: 'h23',
  }).formatToParts(date)

  const map: Record<string, string> = {}
  for (const part of parts) {
    if (part.type !== 'literal') map[part.type] = part.value
  }
  return map
}

export function getZonedParts(date: Date, timeZone: string): ZonedDateTimeParts {
  const map = partMap(date, timeZone)
  return {
    year: Number(map.year),
    month: Number(map.month),
    day: Number(map.day),
    hour: Number(map.hour),
    minute: Number(map.minute),
    second: Number(map.second),
  }
}

function wallAsUtcMs(parts: ZonedDateTimeParts): number {
  return Date.UTC(
    parts.year,
    parts.month - 1,
    parts.day,
    parts.hour,
    parts.minute,
    parts.second,
  )
}

/** Convert a wall-clock time in `timeZone` to a UTC Date. */
export function zonedPartsToDate(
  parts: ZonedDateTimeParts,
  timeZone: string,
): Date {
  const asUtc = wallAsUtcMs(parts)
  let utcMs = asUtc
  for (let i = 0; i < 3; i++) {
    const offset = wallAsUtcMs(getZonedParts(new Date(utcMs), timeZone)) - utcMs
    utcMs = asUtc - offset
  }
  return new Date(utcMs)
}

/** Browser-local Date at midnight for the calendar day in `timeZone`. */
export function zonedCalendarDate(date: Date, timeZone: string): Date {
  const parts = getZonedParts(date, timeZone)
  return new Date(parts.year, parts.month - 1, parts.day)
}

export function isSameZonedDay(
  left: Date,
  right: Date,
  timeZone: string,
): boolean {
  const a = getZonedParts(left, timeZone)
  const b = getZonedParts(right, timeZone)
  return a.year === b.year && a.month === b.month && a.day === b.day
}

export function formatTimeZoneOffset(
  timeZone: string,
  at: Date = new Date(),
): string {
  const name = new Intl.DateTimeFormat('en-US', {
    timeZone,
    timeZoneName: 'shortOffset',
  })
    .formatToParts(at)
    .find((part) => part.type === 'timeZoneName')?.value
  return (name || 'UTC').replace(/^GMT/i, 'UTC')
}

export function formatTimeZoneLabel(
  timeZone: string,
  at: Date = new Date(),
): string {
  const offset = formatTimeZoneOffset(timeZone, at)
  const city = displayNameForTimeZone(timeZone)
  return `(${offset}) ${city}`
}

export function displayNameForTimeZone(timeZone: string): string {
  if (timeZone === 'UTC' || timeZone === 'Etc/UTC' || timeZone === 'Etc/GMT') {
    return 'UTC'
  }
  const city = timeZone.split('/').pop() || timeZone
  return city.replace(/_/g, ' ')
}

export function getUserTimeZone(): string {
  return Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC'
}

export function isValidTimeZone(timeZone: string): boolean {
  try {
    Intl.DateTimeFormat('en-US', { timeZone }).format(new Date())
    return true
  } catch {
    return false
  }
}

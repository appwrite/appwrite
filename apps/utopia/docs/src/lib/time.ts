// Dates in the generated data: ISO 8601 strings, from git or from the tools
// that recorded a run. The server renders absolute dates (in UTC, so server
// and browser agree); `<Time>` switches to relative ones after hydration.

const valid = (date: string | null | undefined): date is string => !!date && !Number.isNaN(Date.parse(date))

/** `2026-10-08T16:36:30+02:00` → `Oct 8, 2026`. */
export function day(date: string | null | undefined) {
  if (!valid(date)) return ''
  return new Date(date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric', timeZone: 'UTC' })
}

/** `2026-10-08T16:36:30+02:00` → `Oct 8, 2026, 14:36 UTC`. */
export function absolute(date: string | null | undefined) {
  if (!valid(date)) return ''
  const time = new Date(date).toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit', timeZone: 'UTC' })
  return `${day(date)}, ${time} UTC`
}

const UNITS: [Intl.RelativeTimeFormatUnit, number][] = [
  ['year', 365 * 24 * 3600],
  ['month', 30 * 24 * 3600],
  ['week', 7 * 24 * 3600],
  ['day', 24 * 3600],
  ['hour', 3600],
  ['minute', 60],
]

const relative = new Intl.RelativeTimeFormat('en', { numeric: 'auto' })

/** How long before `now` (milliseconds) `date` was: `3 days ago`, `yesterday`, `just now`. */
export function ago(date: string | null | undefined, now: number) {
  if (!valid(date)) return ''
  const seconds = (Date.parse(date) - now) / 1000
  for (const [unit, size] of UNITS) {
    if (Math.abs(seconds) >= size) return relative.format(Math.round(seconds / size), unit)
  }
  return 'just now'
}

/** Whether `a` is strictly before `b`; false when either is missing. */
export function before(a: string | null | undefined, b: string | null | undefined) {
  return valid(a) && valid(b) && Date.parse(a) < Date.parse(b)
}

/** Milliseconds since the epoch, for sorting; 0 when missing. */
export const epoch = (date: string | null | undefined) => (valid(date) ? Date.parse(date) : 0)

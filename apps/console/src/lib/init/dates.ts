export function parseDateOnly(isoDate: string): Date {
  const [year, month, day] = isoDate.split('-').map(Number)
  return new Date(year, month - 1, day)
}

/** Local midnight when a launch day unlocks (day 1 = event start date). */
export function resolveInitDayUnlockDate(startDate: string, dayNumber: number): Date {
  const unlock = parseDateOnly(startDate)
  unlock.setDate(unlock.getDate() + Math.max(dayNumber - 1, 0))
  return unlock
}

function getCalendarDateKeyInZone(nowMs: number, timeZone?: string): string {
  const parts = new Intl.DateTimeFormat('en-US', {
    ...(timeZone ? { timeZone } : {}),
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).formatToParts(new Date(nowMs))

  const part = (type: Intl.DateTimeFormatPartTypes) =>
    parts.find((entry) => entry.type === type)?.value ?? ''

  return `${part('year')}-${part('month')}-${part('day')}`
}

function isoDateToUtcNoonMs(isoDate: string): number {
  const [year, month, day] = isoDate.split('-').map(Number)
  return Date.UTC(year, month - 1, day, 12, 0, 0)
}

/**
 * Viewer's calendar Init day (1-indexed) from the event start date.
 * Uses the local timezone by default so the schedule follows the date on the
 * user's clock, not the unlock/day slider and not Pacific Time.
 * Returns 0 before the first day.
 */
export function resolveInitCalendarDay(
  startDate: string,
  nowMs: number = Date.now(),
  timeZone?: string,
): number {
  const todayKey = getCalendarDateKeyInZone(nowMs, timeZone)
  const diffDays = Math.round(
    (isoDateToUtcNoonMs(todayKey) - isoDateToUtcNoonMs(startDate)) /
      (24 * 60 * 60 * 1000),
  )
  if (diffDays < 0) return 0
  return diffDays + 1
}

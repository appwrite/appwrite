import { parseDateOnly, resolveInitDayUnlockDate } from './dates'
import { resolveInitCurrentDay, resolveInitRecapMode } from './event-visibility'
import type { LaunchEvent } from './types'

const INIT_PAGE_URL = 'https://appwrite.io/init'

export type InitCalendarVisibilityOptions = {
  now?: Date
  mockCurrentDay?: number | null
}

function getInitCalendarCurrentDay(
  event: LaunchEvent,
  options?: InitCalendarVisibilityOptions,
): number {
  return resolveInitCurrentDay(event, options?.now, options?.mockCurrentDay ?? null)
}

function isInitCalendarDayLocked(
  event: LaunchEvent,
  dayNumber: number,
  options?: InitCalendarVisibilityOptions,
): boolean {
  if (resolveInitRecapMode(event, options?.now, options?.mockCurrentDay ?? null)) {
    return false
  }

  const currentDay = getInitCalendarCurrentDay(event, options)
  return currentDay <= 0 || dayNumber > currentDay
}

function getInitCalendarDaySummary(
  event: LaunchEvent,
  dayNumber: number,
  options?: InitCalendarVisibilityOptions,
): string {
  const day = event.days.find((entry) => entry.day === dayNumber)
  if (!day) return ''

  if (isInitCalendarDayLocked(event, dayNumber, options)) {
    return `Init · Day ${dayNumber} (${day.dateLabel})`
  }

  return `Init Day ${dayNumber}: ${day.title}`
}

function getInitCalendarDayDescription(
  event: LaunchEvent,
  dayNumber: number,
  options?: InitCalendarVisibilityOptions,
): string {
  const day = event.days.find((entry) => entry.day === dayNumber)
  if (!day) return ''

  if (isInitCalendarDayLocked(event, dayNumber, options)) {
    return [
      'Coming soon.',
      '',
      `This launch unlocks on ${day.dateLabel}. Check back on Init for announcements, resources, and sessions.`,
      '',
      INIT_PAGE_URL,
    ].join('\n')
  }

  return [
    day.description,
    '',
    `View on Init: ${INIT_PAGE_URL}#day-${dayNumber}`,
  ].join('\n')
}

export function getInitCalendarDayMenuLabel(
  event: LaunchEvent,
  dayNumber: number,
  options?: InitCalendarVisibilityOptions,
): string {
  const day = event.days.find((entry) => entry.day === dayNumber)
  if (!day) return ''

  if (isInitCalendarDayLocked(event, dayNumber, options)) {
    return `Day ${day.day} · ${day.dateLabel}`
  }

  return `Day ${day.day} · ${day.title}`
}

function formatIcsDateOnly(date: Date): string {
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${year}${month}${day}`
}

function formatIcsUtcTimestamp(date: Date): string {
  return date.toISOString().replace(/[-:]/g, '').replace(/\.\d{3}Z$/, 'Z')
}

function escapeIcsText(value: string): string {
  return value
    .replace(/\\/g, '\\\\')
    .replace(/;/g, '\\;')
    .replace(/,/g, '\\,')
    .replace(/\r?\n/g, '\\n')
}

function foldIcsLine(line: string): string {
  if (line.length <= 75) return line

  const chunks: string[] = [line.slice(0, 75)]
  let index = 75
  while (index < line.length) {
    chunks.push(` ${line.slice(index, index + 74)}`)
    index += 74
  }
  return chunks.join('\r\n')
}

function buildInitDayCalendarEvent(
  event: LaunchEvent,
  dayNumber: number,
  options?: InitCalendarVisibilityOptions,
): string {
  const day = event.days.find((entry) => entry.day === dayNumber)
  if (!day) return ''

  const unlockDate = resolveInitDayUnlockDate(event.startDate, dayNumber)
  const endDate = new Date(unlockDate)
  endDate.setDate(endDate.getDate() + 1)

  const summary = getInitCalendarDaySummary(event, dayNumber, options)
  const description = getInitCalendarDayDescription(event, dayNumber, options)

  const lines = [
    'BEGIN:VEVENT',
    `UID:init-${event.id}-day-${dayNumber}@appwrite.io`,
    `DTSTAMP:${formatIcsUtcTimestamp(new Date())}`,
    `DTSTART;VALUE=DATE:${formatIcsDateOnly(unlockDate)}`,
    `DTEND;VALUE=DATE:${formatIcsDateOnly(endDate)}`,
    foldIcsLine(`SUMMARY:${escapeIcsText(summary)}`),
    foldIcsLine(`DESCRIPTION:${escapeIcsText(description)}`),
    `URL:${INIT_PAGE_URL}#day-${dayNumber}`,
    'END:VEVENT',
  ]

  return lines.join('\r\n')
}

/** Build an ICS file containing all launch days for the event. */
export function buildInitEventCalendarIcs(
  event: LaunchEvent,
  options?: InitCalendarVisibilityOptions,
): string {
  const dayNumbers = event.days.map((day) => day.day).sort((a, b) => a - b)
  const events = dayNumbers
    .map((dayNumber) => buildInitDayCalendarEvent(event, dayNumber, options))
    .filter(Boolean)
    .join('\r\n')

  const calendarName = `Init ${event.dateRangeLabel}`

  return [
    'BEGIN:VCALENDAR',
    'VERSION:2.0',
    'PRODID:-//Appwrite//Init//EN',
    'CALSCALE:GREGORIAN',
    'METHOD:PUBLISH',
    foldIcsLine(`X-WR-CALNAME:${escapeIcsText(calendarName)}`),
    events,
    'END:VCALENDAR',
  ].join('\r\n')
}

export function getInitEventCalendarFeedPath(eventSlug: string): string {
  return `/init/calendar/${eventSlug}`
}

export function getInitEventCalendarFeedUrl(
  eventSlug: string,
  origin?: string,
): string {
  const baseOrigin =
    origin ??
    (typeof window !== 'undefined'
      ? window.location.origin
      : 'https://cloud.appwrite.io')
  return `${baseOrigin}${getInitEventCalendarFeedPath(eventSlug)}`
}

export function getInitEventCalendarDownloadUrl(
  eventSlug: string,
  origin?: string,
): string {
  const url = new URL(getInitEventCalendarFeedUrl(eventSlug, origin))
  url.searchParams.set('download', '1')
  return url.toString()
}

function formatGoogleCalendarDate(date: Date): string {
  return formatIcsDateOnly(date)
}

function buildInitCalendarWeekDetails(
  event: LaunchEvent,
  options?: InitCalendarVisibilityOptions,
): string {
  const dayLines = [...event.days]
    .sort((a, b) => a.day - b.day)
    .map((day) => {
      if (isInitCalendarDayLocked(event, day.day, options)) {
        return `Day ${day.day} (${day.dateLabel}): Coming soon`
      }

      return `Day ${day.day} (${day.dateLabel}): ${day.title}`
    })

  return [
    event.description,
    '',
    'Daily launches:',
    ...dayLines,
    '',
    INIT_PAGE_URL,
  ].join('\n')
}

/** Full Init week as one all-day event (reliable Google Calendar deep link). */
export function buildGoogleCalendarWeekEventUrl(
  event: LaunchEvent,
  options?: InitCalendarVisibilityOptions,
): string {
  const start = parseDateOnly(event.startDate)
  const end = parseDateOnly(event.endDate)
  end.setDate(end.getDate() + 1)

  const params = new URLSearchParams({
    action: 'TEMPLATE',
    text: `Init ${event.dateRangeLabel}`,
    dates: `${formatGoogleCalendarDate(start)}/${formatGoogleCalendarDate(end)}`,
    details: buildInitCalendarWeekDetails(event, options),
    location: INIT_PAGE_URL,
  })

  return `https://calendar.google.com/calendar/render?${params.toString()}`
}

/** Single launch day as an all-day Google Calendar event. */
export function buildGoogleCalendarDayEventUrl(
  event: LaunchEvent,
  dayNumber: number,
  options?: InitCalendarVisibilityOptions,
): string {
  const day = event.days.find((entry) => entry.day === dayNumber)
  if (!day) return ''

  const unlockDate = resolveInitDayUnlockDate(event.startDate, dayNumber)
  const endDate = new Date(unlockDate)
  endDate.setDate(endDate.getDate() + 1)

  const params = new URLSearchParams({
    action: 'TEMPLATE',
    text: getInitCalendarDaySummary(event, dayNumber, options),
    dates: `${formatGoogleCalendarDate(unlockDate)}/${formatGoogleCalendarDate(endDate)}`,
    details: getInitCalendarDayDescription(event, dayNumber, options),
    location: INIT_PAGE_URL,
  })

  return `https://calendar.google.com/calendar/render?${params.toString()}`
}

export function openGoogleCalendarEventUrl(url: string): void {
  window.open(url, '_blank', 'noopener,noreferrer')
}

export function downloadInitEventCalendar(
  event: LaunchEvent,
  options?: InitCalendarVisibilityOptions,
): void {
  const ics = buildInitEventCalendarIcs(event, options)
  const blob = new Blob([ics], { type: 'text/calendar;charset=utf-8' })
  const url = URL.createObjectURL(blob)
  const anchor = document.createElement('a')
  anchor.href = url
  anchor.download = `${event.slug}.ics`
  anchor.click()
  URL.revokeObjectURL(url)
}

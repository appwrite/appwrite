import { parseDateOnly, resolveInitDayUnlockDate } from './dates'
import { resolveInitCurrentDay, resolveInitRecapMode } from './event-visibility'
import type { LaunchEvent, LaunchEventScheduleItem } from './types'

const INIT_PAGE_PATH = '/init'

function resolveInitPageUrl(origin?: string): string {
  if (origin) {
    return `${origin.replace(/\/+$/, '')}${INIT_PAGE_PATH}`
  }
  if (typeof window !== 'undefined') {
    return `${window.location.origin}${INIT_PAGE_PATH}`
  }
  return INIT_PAGE_PATH
}

function initPageAnchor(dayNumber: number): string {
  return `${INIT_PAGE_PATH}#day-${dayNumber}`
}

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
      INIT_PAGE_PATH,
    ].join('\n')
  }

  return [
    day.description,
    '',
    `View on Init: ${initPageAnchor(dayNumber)}`,
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
    `URL:${resolveInitPageUrl()}#day-${dayNumber}`,
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

function formatGoogleCalendarDateTime(date: Date): string {
  return `${formatIcsDateOnly(date)}T${String(date.getHours()).padStart(2, '0')}${String(
    date.getMinutes(),
  ).padStart(2, '0')}00`
}

function parseScheduleTimeLabel(timeLabel: string): {
  hours: number
  minutes: number
} | null {
  const match = timeLabel.trim().match(/^(\d{1,2}):(\d{2})\s*(AM|PM)$/i)
  if (!match) return null

  const hour = Number.parseInt(match[1], 10)
  const minutes = Number.parseInt(match[2], 10)
  const period = match[3].toUpperCase()
  if (hour < 1 || hour > 12 || minutes < 0 || minutes > 59) return null

  return {
    hours: (hour % 12) + (period === 'PM' ? 12 : 0),
    minutes,
  }
}

function getInitScheduleItemDateRange(
  event: LaunchEvent,
  item: LaunchEventScheduleItem,
): { start: Date; end: Date; allDay: boolean } {
  const start = resolveInitDayUnlockDate(event.startDate, item.day)
  const parsedTime = parseScheduleTimeLabel(item.timeLabel)

  if (!parsedTime) {
    const end = new Date(start)
    end.setDate(end.getDate() + 1)
    return { start, end, allDay: true }
  }

  start.setHours(parsedTime.hours, parsedTime.minutes, 0, 0)
  const end = new Date(start)
  end.setHours(end.getHours() + 1)
  return { start, end, allDay: false }
}

function getInitScheduleItemPlatformLabel(
  platform: LaunchEventScheduleItem['platform'],
): string {
  switch (platform) {
    case 'discord':
      return 'Discord'
    case 'reddit':
      return 'Reddit'
    case 'youtube':
      return 'YouTube'
  }
}

function getInitScheduleItemSummary(item: LaunchEventScheduleItem): string {
  return `Init: ${item.title}`
}

function getInitScheduleItemDescription(
  event: LaunchEvent,
  item: LaunchEventScheduleItem,
): string {
  const details = [
    `${getInitScheduleItemPlatformLabel(item.platform)} · ${item.timeLabel}`,
    '',
    `View on Init: ${initPageAnchor(item.day)}`,
  ]

  if (item.href) {
    details.push('', item.href)
  }

  const day = event.days.find((entry) => entry.day === item.day)
  if (day && 'title' in day) {
    details.unshift(`Day ${day.day}: ${day.title}`, '')
  }

  return details.join('\n')
}

function buildInitScheduleItemCalendarEvent(
  event: LaunchEvent,
  item: LaunchEventScheduleItem,
): string {
  const { start, end, allDay } = getInitScheduleItemDateRange(event, item)
  const dateLines = allDay
    ? [
        `DTSTART;VALUE=DATE:${formatIcsDateOnly(start)}`,
        `DTEND;VALUE=DATE:${formatIcsDateOnly(end)}`,
      ]
    : [
        `DTSTART:${formatGoogleCalendarDateTime(start)}`,
        `DTEND:${formatGoogleCalendarDateTime(end)}`,
      ]

  return [
    'BEGIN:VEVENT',
    `UID:init-${event.id}-${item.id}@appwrite.io`,
    `DTSTAMP:${formatIcsUtcTimestamp(new Date())}`,
    ...dateLines,
    foldIcsLine(`SUMMARY:${escapeIcsText(getInitScheduleItemSummary(item))}`),
    foldIcsLine(
      `DESCRIPTION:${escapeIcsText(getInitScheduleItemDescription(event, item))}`,
    ),
    `URL:${resolveInitPageUrl()}#day-${item.day}`,
    'END:VEVENT',
  ].join('\r\n')
}

export function buildInitScheduleItemCalendarIcs(
  event: LaunchEvent,
  item: LaunchEventScheduleItem,
): string {
  return [
    'BEGIN:VCALENDAR',
    'VERSION:2.0',
    'PRODID:-//Appwrite//Init//EN',
    'CALSCALE:GREGORIAN',
    'METHOD:PUBLISH',
    foldIcsLine(`X-WR-CALNAME:${escapeIcsText(getInitScheduleItemSummary(item))}`),
    buildInitScheduleItemCalendarEvent(event, item),
    'END:VCALENDAR',
  ].join('\r\n')
}

export function buildGoogleCalendarScheduleItemEventUrl(
  event: LaunchEvent,
  item: LaunchEventScheduleItem,
): string {
  const { start, end, allDay } = getInitScheduleItemDateRange(event, item)
  const dates = allDay
    ? `${formatGoogleCalendarDate(start)}/${formatGoogleCalendarDate(end)}`
    : `${formatGoogleCalendarDateTime(start)}/${formatGoogleCalendarDateTime(end)}`

  const params = new URLSearchParams({
    action: 'TEMPLATE',
    text: getInitScheduleItemSummary(item),
    dates,
    details: getInitScheduleItemDescription(event, item),
    location: item.href ?? resolveInitPageUrl(),
  })

  return `https://calendar.google.com/calendar/render?${params.toString()}`
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
    INIT_PAGE_PATH,
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
    location: resolveInitPageUrl(),
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
    location: resolveInitPageUrl(),
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

export function downloadInitScheduleItemCalendar(
  event: LaunchEvent,
  item: LaunchEventScheduleItem,
): void {
  const ics = buildInitScheduleItemCalendarIcs(event, item)
  const blob = new Blob([ics], { type: 'text/calendar;charset=utf-8' })
  const url = URL.createObjectURL(blob)
  const anchor = document.createElement('a')
  anchor.href = url
  anchor.download = `${event.slug}-${item.id}.ics`
  anchor.click()
  URL.revokeObjectURL(url)
}

import {
  getActiveLanguage,
  type SupportedLanguage,
} from '@/lib/i18n/active-language'
import { formatLocalizedDateTime } from '@/lib/i18n/date-format'

/**
 * Init sessions are scheduled in Pacific Time. Schedule data stores absolute
 * instants (ISO strings with an explicit offset) so every surface can render
 * them in the viewer's own zone.
 */
export const INIT_EVENT_TIME_ZONE = 'America/Los_Angeles'

const TIME_ONLY: Intl.DateTimeFormatOptions = {
  hour: 'numeric',
  minute: '2-digit',
}

const DATE_AND_TIME: Intl.DateTimeFormatOptions = {
  month: 'short',
  day: 'numeric',
  hour: 'numeric',
  minute: '2-digit',
}

export type FormatInitScheduleTimeOptions = {
  language?: SupportedLanguage
  /**
   * Zone to render in. Defaults to the viewer's zone; pass
   * `INIT_EVENT_TIME_ZONE` for output that is stable across server and client.
   */
  timeZone?: string
}

export function parseInitScheduleTime(startsAt: string): Date | null {
  const date = new Date(startsAt)
  return Number.isNaN(date.getTime()) ? null : date
}

/** `YYYY-MM-DD` for an instant in the given zone (viewer's zone when omitted). */
function getCalendarDateKey(date: Date, timeZone?: string): string {
  const parts = new Intl.DateTimeFormat('en-US', {
    timeZone,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).formatToParts(date)

  const part = (type: Intl.DateTimeFormatPartTypes) =>
    parts.find((entry) => entry.type === type)?.value ?? ''

  return `${part('year')}-${part('month')}-${part('day')}`
}

/**
 * Localized session time for the viewer.
 *
 * Renders a bare time when the instant still lands on its own Init day in the
 * target zone, and prefixes the date when the conversion rolls it onto another
 * day (e.g. a 9:30 PM IST stream reads "Sep 1, 1:00 AM" in Tokyo).
 */
export function formatInitScheduleTime(
  startsAt: string,
  options: FormatInitScheduleTimeOptions = {},
): string {
  const date = parseInitScheduleTime(startsAt)
  if (!date) return ''

  const { language = getActiveLanguage(), timeZone } = options
  const rollsOver =
    getCalendarDateKey(date, INIT_EVENT_TIME_ZONE) !==
    getCalendarDateKey(date, timeZone)

  return formatLocalizedDateTime(
    date,
    { ...(rollsOver ? DATE_AND_TIME : TIME_ONLY), timeZone },
    language,
  )
}

/**
 * Canonical session time in the event's own zone, e.g. "9:00 AM PDT".
 *
 * Always English - this is the reference rendering used in calendar exports,
 * which are English throughout. The zone abbreviation comes from `Intl`, so
 * daylight saving is reflected automatically.
 */
export function formatInitScheduleEventZoneTime(startsAt: string): string {
  const date = parseInitScheduleTime(startsAt)
  if (!date) return ''

  return date.toLocaleTimeString('en-US', {
    ...TIME_ONLY,
    timeZone: INIT_EVENT_TIME_ZONE,
    timeZoneName: 'short',
  })
}

/**
 * Uppercase month/day label in the event zone, e.g. "SEPTEMBER 5".
 * Matches the hardcoded English `dateLabel` style used by launch day data.
 */
export function formatInitScheduleDayDateLabel(startsAt: string): string {
  const date = parseInitScheduleTime(startsAt)
  if (!date) return ''

  return date
    .toLocaleDateString('en-US', {
      timeZone: INIT_EVENT_TIME_ZONE,
      month: 'long',
      day: 'numeric',
    })
    .toUpperCase()
}

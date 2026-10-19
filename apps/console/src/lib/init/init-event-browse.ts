import { parseDateOnly } from './dates'
import { getLaunchEventDerivedStatus, LAUNCH_EVENTS } from './events'
import { INIT_EVENT_CATALOG } from './init-event-catalog'
import { getInitMockDayAfter, INIT_MOCK_DAY_BEFORE } from './mock-current-day'
import type { LaunchEvent, LaunchEventStatus } from './types'

export type InitBrowsableEvent = {
  id: string
  slug: string
  edition: number
  editionLabel: string
  dateRangeLabel: string
  status: LaunchEventStatus
}

export function resolveLaunchEventFromBrowseParam(
  browseEventSlug: string | undefined | null,
): LaunchEvent | undefined {
  if (!browseEventSlug?.trim()) return undefined
  const normalized = browseEventSlug.trim()
  return (
    LAUNCH_EVENTS.find((event) => event.slug === normalized) ??
    LAUNCH_EVENTS.find((event) => event.id === normalized)
  )
}

/** Init events with day content, newest first. */
export function listBrowsableInitEvents(
  now = new Date(),
): InitBrowsableEvent[] {
  return LAUNCH_EVENTS.filter(
    (event) => event.days.length > 0 && event.id in INIT_EVENT_CATALOG,
  )
    .map((event) => {
      const catalog = INIT_EVENT_CATALOG[event.id]!
      return {
        id: event.id,
        slug: event.slug,
        edition: catalog.edition,
        editionLabel: catalog.editionLabel,
        dateRangeLabel: event.dateRangeLabel,
        status: getLaunchEventDerivedStatus(event, now),
      }
    })
    .sort(
      (a, b) =>
        parseDateOnly(
          LAUNCH_EVENTS.find((event) => event.id === b.id)!.startDate,
        ).getTime() -
        parseDateOnly(
          LAUNCH_EVENTS.find((event) => event.id === a.id)!.startDate,
        ).getTime(),
    )
}

/**
 * When browsing an upcoming event, avoid recap mode from the global default
 * current day (post Init 4). Show the before-event state instead.
 */
export function resolveInitBrowseDisplayCurrentDay(
  event: LaunchEvent,
  currentDay: number,
  now = new Date(),
): number {
  const maxDay = event.days.reduce((max, day) => Math.max(max, day.day), 0)
  if (maxDay === 0) return currentDay

  const status = getLaunchEventDerivedStatus(event, now)
  if (status === 'upcoming' && currentDay >= getInitMockDayAfter(maxDay)) {
    return INIT_MOCK_DAY_BEFORE
  }

  return currentDay
}

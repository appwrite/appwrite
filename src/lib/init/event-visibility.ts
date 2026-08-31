import { getInitMockDayAfter, getInitMockCurrentDayDefault } from './mock-current-day'
import {
  applyInitPrizesVisibility,
  getInitMaskedSessionTitle,
  isInitDailyPrizeRevealed,
} from './prize-visibility'
import {
  hasInitScheduleStarted,
  isInitScheduleStartingSoon,
} from './schedule-time'
import type {
  InitDisplayEvent,
  LaunchEvent,
  LaunchEventDay,
  LaunchEventDayLocked,
  LaunchEventDayView,
  LaunchEventLiveBanner,
  LaunchEventScheduleItem,
} from './types'

/**
 * Authored `isLive` sessions become "Live now" only after `startsAt`, and only
 * on the unlocked current day. Day unlock stays controlled; stream live state
 * follows the wall clock.
 */
export function isInitScheduleItemLiveNow(
  item: LaunchEventScheduleItem,
  currentDay: number,
  nowMs: number = Date.now(),
): boolean {
  if (!item.isLive || item.day !== currentDay) return false
  return hasInitScheduleStarted(item.startsAt, nowMs)
}

/**
 * Any session on the current unlocked day enters "Starting soon" one hour
 * before `startsAt`, until the session starts (or goes live).
 */
export function isInitScheduleItemStartingSoon(
  item: LaunchEventScheduleItem,
  currentDay: number,
  nowMs: number = Date.now(),
): boolean {
  if (currentDay < 1 || item.day !== currentDay) return false
  if (hasInitScheduleStarted(item.startsAt, nowMs)) return false
  return isInitScheduleStartingSoon(item.startsAt, nowMs)
}

function resolveInitLiveBanner(
  event: LaunchEvent,
  currentDay: number,
  nowMs: number,
): LaunchEventLiveBanner | undefined {
  if (currentDay < 1 || !event.liveBanner) return undefined

  const liveSession = event.schedule.find(
    (item) => item.isLive && item.day === currentDay,
  )
  if (!liveSession) return undefined

  // The banner follows whichever day is live, so it takes that session's link.
  const href = liveSession.href ?? event.liveBanner.href

  if (hasInitScheduleStarted(liveSession.startsAt, nowMs)) {
    return { ...event.liveBanner, href, mode: 'live' }
  }

  if (isInitScheduleStartingSoon(liveSession.startsAt, nowMs)) {
    return { ...event.liveBanner, href, mode: 'startingSoon' }
  }

  return undefined
}

function toLockedDay(day: LaunchEventDay): LaunchEventDayLocked {
  return {
    day: day.day,
    dateLabel: day.dateLabel,
    weekdayLabel: day.weekdayLabel,
    isLocked: true,
  }
}

function getInitMaxDay(event: LaunchEvent): number {
  return event.days.reduce((max, day) => Math.max(max, day.day), 0)
}

/**
 * Resolve which Init day (1-indexed) is "today".
 * Returns 0 when the event has not started yet.
 * Unlock state is always controlled (debug default / code default), never calendar.
 */
export function resolveInitCurrentDay(
  event: LaunchEvent,
  currentDay: number = getInitMockCurrentDayDefault(),
): number {
  const maxDay = event.days.reduce((max, day) => Math.max(max, day.day), 0) || 1
  return Math.min(Math.max(currentDay, 0), maxDay + 1)
}

/** True when the launch week has ended and the page should show recap mode. */
export function resolveInitRecapMode(
  event: LaunchEvent,
  currentDay: number = getInitMockCurrentDayDefault(),
): boolean {
  const maxDay = getInitMaxDay(event)
  if (maxDay === 0) return false
  return currentDay >= getInitMockDayAfter(maxDay)
}

function buildRecapDisplayEvent(
  event: LaunchEvent,
  currentDay: number,
): InitDisplayEvent {
  const days: LaunchEventDayView[] = event.days.map((day) => ({
    ...day,
    isLive: false,
  }))

  const schedule = event.schedule.map((item) => ({
    ...item,
    isLive: false,
    isStartingSoon: false,
  }))

  const recap = event.recap

  return {
    ...event,
    headline: recap?.headline ?? 'Init recap',
    description:
      recap?.description ??
      'Catch up on every launch, rewatch sessions, and explore what shipped during Init week.',
    days,
    schedule,
    liveBanner: undefined,
    liveActivities: [],
    giveaway: undefined,
    getInvolved: recap?.getInvolved ?? event.getInvolved,
    currentDay,
    isRecapMode: true,
  }
}

/**
 * Apply day-based visibility to the event for display.
 *
 * Future-day launch cards stay locked; schedule and prizes stay visible with
 * session titles masked until each day unlocks. Unlock state is always driven
 * by `currentDay` (code default or debug Day slider), never the calendar.
 * Session "Starting soon" / "Live now" and the live banner follow each
 * session's `startsAt` (starting-soon window is 1 hour).
 */
export function applyInitEventVisibility(
  event: LaunchEvent,
  options?: { currentDay?: number; nowMs?: number },
): InitDisplayEvent {
  const currentDay = resolveInitCurrentDay(
    event,
    options?.currentDay ?? getInitMockCurrentDayDefault(),
  )
  const nowMs = options?.nowMs ?? Date.now()
  const isRecapMode = resolveInitRecapMode(event, currentDay)

  if (isRecapMode) {
    return buildRecapDisplayEvent(event, currentDay)
  }

  const days: LaunchEventDayView[] = event.days.map((day) => {
    if (currentDay <= 0 || day.day > currentDay) {
      return toLockedDay(day)
    }

    return {
      ...day,
      isLive: day.day === currentDay,
    }
  })

  const schedule = event.schedule.map((item) => {
    const isLive = isInitScheduleItemLiveNow(item, currentDay, nowMs)
    return {
      ...item,
      title: isInitDailyPrizeRevealed(currentDay, item.day)
        ? item.title
        : getInitMaskedSessionTitle(item.platform, item.day),
      isLive,
      isStartingSoon:
        !isLive && isInitScheduleItemStartingSoon(item, currentDay, nowMs),
    }
  })

  const liveBanner = resolveInitLiveBanner(event, currentDay, nowMs)

  const prizes = event.prizes
    ? applyInitPrizesVisibility(event.prizes, currentDay)
    : undefined

  return {
    ...event,
    days,
    schedule,
    liveBanner,
    prizes,
    currentDay,
    isRecapMode: false,
  }
}

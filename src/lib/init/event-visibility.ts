import { parseDateOnly } from './dates'
import { getInitMockDayAfter } from './mock-current-day'
import {
  applyInitPrizesVisibility,
  getInitMaskedSessionTitle,
  isInitDailyPrizeRevealed,
} from './prize-visibility'
import type {
  InitDisplayEvent,
  LaunchEvent,
  LaunchEventDay,
  LaunchEventDayLocked,
  LaunchEventDayView,
} from './types'

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
 */
export function resolveInitCurrentDay(
  event: LaunchEvent,
  now = new Date(),
  mockCurrentDay: number | null = null,
): number {
  const dayNumbers = event.days.map((day) => day.day).sort((a, b) => a - b)
  const minDay = dayNumbers[0] ?? 1
  const maxDay = dayNumbers[dayNumbers.length - 1] ?? minDay

  if (mockCurrentDay !== null) {
    return Math.min(Math.max(mockCurrentDay, 0), maxDay + 1)
  }

  const start = parseDateOnly(event.startDate)
  const end = parseDateOnly(event.endDate)
  end.setHours(23, 59, 59, 999)

  if (now < start) return 0
  if (now > end) return maxDay

  const dayIndex =
    Math.floor((now.getTime() - start.getTime()) / (24 * 60 * 60 * 1000)) + 1

  return Math.min(Math.max(dayIndex, minDay), maxDay)
}

/** True when the launch week has ended and the page should show recap mode. */
export function resolveInitRecapMode(
  event: LaunchEvent,
  now = new Date(),
  mockCurrentDay: number | null = null,
): boolean {
  const maxDay = getInitMaxDay(event)
  if (maxDay === 0) return false

  if (mockCurrentDay !== null) {
    return mockCurrentDay >= getInitMockDayAfter(maxDay)
  }

  const end = parseDateOnly(event.endDate)
  end.setHours(23, 59, 59, 999)
  return now > end
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
 * session titles masked until each day unlocks. When `mockCurrentDay` is set
 * (debug), it drives which days unlock.
 */
export function applyInitEventVisibility(
  event: LaunchEvent,
  options?: { now?: Date; mockCurrentDay?: number | null },
): InitDisplayEvent {
  const now = options?.now ?? new Date()
  const mockCurrentDay = options?.mockCurrentDay ?? null

  const currentDay = resolveInitCurrentDay(event, now, mockCurrentDay)
  const isRecapMode = resolveInitRecapMode(event, now, mockCurrentDay)

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

  const schedule = event.schedule.map((item) => ({
    ...item,
    title: isInitDailyPrizeRevealed(currentDay, item.day)
      ? item.title
      : getInitMaskedSessionTitle(item.platform, item.day),
    isLive: item.day === currentDay ? item.isLive : false,
  }))

  const liveBanner =
    currentDay === 1 && event.liveBanner ? event.liveBanner : undefined

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

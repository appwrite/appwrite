import { parseDateOnly } from './dates'
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

/**
 * Strip future-day content from the event so locked material is not present in the tree.
 *
 * When `mockCurrentDay` is set (debug), it drives which days unlock — schedule cards,
 * detail cards, Discord sessions, and live banner all follow the same day.
 */
export function applyInitEventVisibility(
  event: LaunchEvent,
  options?: { now?: Date; mockCurrentDay?: number | null },
): InitDisplayEvent {
  const now = options?.now ?? new Date()
  const mockCurrentDay = options?.mockCurrentDay ?? null

  const currentDay = resolveInitCurrentDay(event, now, mockCurrentDay)

  const days: LaunchEventDayView[] = event.days.map((day) => {
    if (currentDay <= 0 || day.day > currentDay) {
      return toLockedDay(day)
    }

    return {
      ...day,
      isLive: day.day === currentDay,
    }
  })

  const schedule = event.schedule
    .filter((item) => currentDay > 0 && item.day <= currentDay)
    .map((item) => ({
      ...item,
      isLive: item.day === currentDay ? item.isLive : false,
    }))

  const liveBanner =
    currentDay === 1 && event.liveBanner ? event.liveBanner : undefined

  return {
    ...event,
    days,
    schedule,
    liveBanner,
    currentDay,
  }
}

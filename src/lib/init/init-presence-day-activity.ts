import {
  buildInitPreviewingDayActivity,
  buildInitReadingDayActivity,
  buildInitWaitingForDayActivity,
} from '@/lib/init/init-presence-activity'
import {
  isLaunchEventDayLocked,
  type LaunchEventDayView,
} from '@/lib/init/types'

export function buildInitDayPreviewActivity(
  day: LaunchEventDayView,
  currentDay: number,
  seed?: string,
): string {
  if (isLaunchEventDayLocked(day)) {
    return buildInitWaitingForDayActivity(day.day, {
      currentDay,
      seed: seed ?? `preview:${day.day}`,
    })
  }
  return buildInitPreviewingDayActivity(day.day, {
    locked: false,
    title: day.title,
  })
}

export function buildInitDayReadingActivity(
  day: LaunchEventDayView,
  currentDay: number,
  seed?: string,
): string {
  if (isLaunchEventDayLocked(day)) {
    return buildInitWaitingForDayActivity(day.day, {
      currentDay,
      seed: seed ?? `reading:${day.day}`,
    })
  }
  return buildInitReadingDayActivity(day.day, { title: day.title })
}

export function findInitDayView(
  days: LaunchEventDayView[],
  dayNumber: number,
): LaunchEventDayView | undefined {
  return days.find((day) => day.day === dayNumber)
}

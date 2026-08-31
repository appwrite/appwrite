import { useEffect, useState } from 'react'
import { getActiveLaunchEvent } from './events'
import {
  INIT_SCHEDULE_STARTING_SOON_WINDOW_MS,
  parseInitScheduleTime,
} from './schedule-time'

/**
 * Wall-clock ms for Init session live / starting-soon state. Reticks when the
 * next starting-soon window opens or a session `startsAt` is reached.
 */
export function useInitLiveClock(currentDay: number): number {
  const [nowMs, setNowMs] = useState(() => Date.now())

  useEffect(() => {
    const event = getActiveLaunchEvent()
    if (!event || currentDay < 1) return

    const boundaries = event.schedule
      .filter((item) => item.day === currentDay)
      .flatMap((item) => {
        const startMs = parseInitScheduleTime(item.startsAt)?.getTime()
        if (typeof startMs !== 'number') return []
        return [startMs - INIT_SCHEDULE_STARTING_SOON_WINDOW_MS, startMs]
      })
      .filter((ms) => ms > nowMs)
      .sort((a, b) => a - b)

    const nextBoundary = boundaries[0]
    if (nextBoundary === undefined) return

    const delay = Math.max(50, nextBoundary - Date.now() + 50)
    const timeoutId = window.setTimeout(() => {
      setNowMs(Date.now())
    }, delay)

    return () => window.clearTimeout(timeoutId)
  }, [currentDay, nowMs])

  return nowMs
}

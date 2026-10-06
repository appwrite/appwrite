import { describe, expect, test } from 'bun:test'
import { applyInitEventVisibility } from '@/lib/init/event-visibility'
import { LAUNCH_EVENTS } from '@/lib/init/events'
import {
  formatInitScheduleTime,
  INIT_EVENT_TIME_ZONE,
} from '@/lib/init/schedule-time'

const event = LAUNCH_EVENTS[0]!

const BEFORE_KEYNOTE = Date.parse('2026-08-31T07:00:00-07:00')
const KEYNOTE_STARTING_SOON = Date.parse('2026-08-31T08:30:00-07:00')
const DURING_KEYNOTE = Date.parse('2026-08-31T09:30:00-07:00')
const AFTER_KEYNOTE = Date.parse('2026-08-31T10:30:00-07:00')

function scheduleItem(currentDay: number, id: string, nowMs: number) {
  return applyInitEventVisibility(event, { currentDay, nowMs }).schedule.find(
    (item) => item.id === id,
  )
}

describe('init schedule live state', () => {
  test('does not mark the day 1 stream live before the event starts', () => {
    const keynote = scheduleItem(0, 'sched-keynote', DURING_KEYNOTE)
    expect(keynote?.isLive).toBe(false)
    expect(keynote?.isStartingSoon).toBe(false)
    expect(
      formatInitScheduleTime(keynote!.startsAt, {
        language: 'en',
        timeZone: INIT_EVENT_TIME_ZONE,
      }),
    ).toBe('9:00 AM')
  })

  test('marks the day 1 stream live only on day 1 during its window', () => {
    expect(scheduleItem(1, 'sched-keynote', DURING_KEYNOTE)?.isLive).toBe(true)
    expect(scheduleItem(3, 'sched-keynote', DURING_KEYNOTE)?.isLive).toBe(false)
  })

  test('follows the clock around the day 1 stream window', () => {
    expect(scheduleItem(1, 'sched-keynote', BEFORE_KEYNOTE)?.isLive).toBe(false)
    expect(
      scheduleItem(1, 'sched-keynote', BEFORE_KEYNOTE)?.isStartingSoon,
    ).toBe(false)

    const startingSoon = scheduleItem(1, 'sched-keynote', KEYNOTE_STARTING_SOON)
    expect(startingSoon?.isLive).toBe(false)
    expect(startingSoon?.isStartingSoon).toBe(true)

    const ended = scheduleItem(1, 'sched-keynote', AFTER_KEYNOTE)
    expect(ended?.isLive).toBe(false)
    expect(ended?.isStartingSoon).toBe(false)
  })
})

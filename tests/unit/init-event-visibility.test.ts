import { describe, expect, test } from 'bun:test'
import { applyInitEventVisibility } from '@/lib/init/event-visibility'
import { LAUNCH_EVENTS } from '@/lib/init/events'

const event = LAUNCH_EVENTS[0]!

function scheduleItem(currentDay: number, id: string) {
  return applyInitEventVisibility(event, { currentDay }).schedule.find(
    (item) => item.id === id,
  )
}

describe('init schedule live state', () => {
  test('does not mark the day 1 stream live before the event starts', () => {
    const keynote = scheduleItem(0, 'sched-keynote')
    expect(keynote?.isLive).toBeFalsy()
    expect(keynote?.timeLabel).toBe('10:00 AM')
  })

  test('marks the day 1 stream live only on day 1', () => {
    expect(scheduleItem(1, 'sched-keynote')?.isLive).toBe(true)
    expect(scheduleItem(3, 'sched-keynote')?.isLive).toBeFalsy()
  })
})

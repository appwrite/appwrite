import { describe, expect, test } from 'bun:test'
import { applyInitEventVisibility } from '@/lib/init/event-visibility'
import { LAUNCH_EVENTS } from '@/lib/init/events'
import { isFilmPlayerRevealed } from '@/lib/init/types'

const init5 = LAUNCH_EVENTS.find((event) => event.id === 'init-nov-2026')!
const init4 = LAUNCH_EVENTS.find((event) => event.id === 'init-july-2026')!

describe('isFilmPlayerRevealed', () => {
  test('stays hidden until Appwrite Videos is revealed on day 2', () => {
    expect(
      isFilmPlayerRevealed(applyInitEventVisibility(init5, { currentDay: 0 })),
    ).toBe(false)
    expect(
      isFilmPlayerRevealed(applyInitEventVisibility(init5, { currentDay: 1 })),
    ).toBe(false)
    expect(
      isFilmPlayerRevealed(applyInitEventVisibility(init5, { currentDay: 2 })),
    ).toBe(true)
    expect(
      isFilmPlayerRevealed(applyInitEventVisibility(init5, { currentDay: 7 })),
    ).toBe(true)
  })

  test('never shows for an event without its own player day', () => {
    expect(
      isFilmPlayerRevealed(applyInitEventVisibility(init4, { currentDay: 7 })),
    ).toBe(false)
  })
})

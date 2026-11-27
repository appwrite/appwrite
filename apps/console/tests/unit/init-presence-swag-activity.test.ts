import { describe, expect, test } from 'bun:test'
import { LAUNCH_EVENTS } from '@/lib/init/events'
import { buildInitPresenceActivityAllowlist } from '@/lib/init/init-presence-activity-allowlist'
import {
  buildInitViewingDailyPrizeActivity,
  buildInitViewingGrandPrizeActivity,
} from '@/lib/init/init-presence-activity'

function event(id: string) {
  const found = LAUNCH_EVENTS.find((entry) => entry.id === id)
  if (!found) throw new Error(`Missing launch event ${id}`)
  return found
}

describe('Init presence activities name the event swag', () => {
  test('Init 5 viewers see its own swag, not Init 4 prizes', () => {
    const allowed = buildInitPresenceActivityAllowlist(event('init-nov-2026'))
    expect(allowed.has('Hoodie and cap wishlist')).toBe(true)
    expect(allowed.has('Keyboard and desk mat envy')).toBe(true)
    expect(allowed.has('Keyboard, mug and sticker dreams')).toBe(true)
    expect(allowed.has('Tee, beanie and poster wishlist')).toBe(true)
    expect(allowed.has('Full swag collection dreams')).toBe(true)
    expect(allowed.has('Bottle and tee wishlist')).toBe(false)
    expect(allowed.has('Grand prize dreams')).toBe(false)
  })

  test('Init 4 keeps its lines', () => {
    const allowed = buildInitPresenceActivityAllowlist(event('init-july-2026'))
    expect(allowed.has('Keyboard envy')).toBe(true)
    expect(allowed.has('Bottle and tee wishlist')).toBe(true)
    expect(allowed.has('Grand prize dreams')).toBe(true)
  })

  test('a prize without its own line falls back to the day', () => {
    expect(buildInitViewingDailyPrizeActivity({ day: 3 })).toBe(
      'Eyeing day 3 swag',
    )
    expect(buildInitViewingGrandPrizeActivity({})).toBe('Grand prize dreams')
  })
})

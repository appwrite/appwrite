import { describe, expect, test } from 'bun:test'
import { getInitOrgPromoBannerContent } from '@/lib/init/org-promo-banner'
import { getInitMockDayBannerExpired } from '@/lib/init/mock-current-day'

const INIT_5 = 'init-nov-2026'
const WEEK_BEFORE_INIT_5 = new Date('2026-11-23T12:00:00Z')

function init5Banner(currentDay: number) {
  return getInitOrgPromoBannerContent({
    now: WEEK_BEFORE_INIT_5,
    currentDay,
    activeEventId: INIT_5,
  })
}

describe('Init org promo banner', () => {
  test('promotes Init 5 as upcoming in production from the default day', () => {
    const banner = getInitOrgPromoBannerContent({
      now: WEEK_BEFORE_INIT_5,
      currentDay: getInitMockDayBannerExpired(),
      activeEventId: null,
    })
    expect(banner?.eventId).toBe(INIT_5)
    expect(banner?.phase).toBe('before')
  })

  test('previews Init 5 as upcoming from the default expired day', () => {
    const banner = init5Banner(getInitMockDayBannerExpired())
    expect(banner?.eventId).toBe(INIT_5)
    expect(banner?.phase).toBe('before')
    expect(banner?.message).toContain('biggest Init ever')
    expect(banner?.cta.to).toBe('/init')
  })

  test("leads with today's launch while Init 5 is live", () => {
    const banner = init5Banner(2)
    expect(banner?.phase).toBe('during')
    expect(banner?.day).toBe(2)
    expect(banner?.message).toBe('Introducing Appwrite Videos')
    expect(banner?.badgeLabel).toBe('Live')
  })

  test('switches to the recap after the last launch day', () => {
    const banner = getInitOrgPromoBannerContent({
      now: new Date('2026-12-06T12:00:00Z'),
      currentDay: 6,
      activeEventId: INIT_5,
    })
    expect(banner?.phase).toBe('after')
    expect(banner?.message).toBe(
      'Init 5 is a wrap. Explore every launch and rewatch the streams.',
    )
    expect(banner?.cta.label).toBe('View recap')
  })

  test('shows the recap when "after the event" is picked before the dates', () => {
    expect(init5Banner(6)?.phase).toBe('after')
  })
})

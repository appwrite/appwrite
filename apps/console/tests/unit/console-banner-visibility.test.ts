import { describe, expect, it } from 'bun:test'
import {
  getConsoleBannerById,
  HALLOWEEN_BANNER_ID,
  POSTGRES_PROMO_BANNER_ID,
  START_PROMO_BANNER_ID,
} from '@/lib/console-banners/catalog'
import { isConsoleBannerVisible } from '@/lib/console-banners/visibility'

describe('isConsoleBannerVisible', () => {
  const banner = getConsoleBannerById(POSTGRES_PROMO_BANNER_ID)!
  const beforeWindow = new Date('2026-09-01T00:00:00Z')

  it('hides dismissed banners unless preview is on', () => {
    expect(
      isConsoleBannerVisible(banner, {
        now: beforeWindow,
        dismissed: true,
        cloud: true,
      }),
    ).toBe(false)
  })

  it('shows dismissed or unscheduled banners when preview is on', () => {
    expect(
      isConsoleBannerVisible(banner, {
        now: beforeWindow,
        dismissed: true,
        cloud: false,
        preview: true,
      }),
    ).toBe(true)
  })

  it('shows worldwide banners regardless of country', () => {
    expect(
      isConsoleBannerVisible(banner, {
        now: new Date(banner.startMs),
        cloud: true,
        country: 'FR',
      }),
    ).toBe(true)
  })

  it('hides country-targeted banners outside the list', () => {
    const halloween = getConsoleBannerById(HALLOWEEN_BANNER_ID)!
    const duringWindow = new Date('2026-10-31T12:00:00.000Z')

    expect(
      isConsoleBannerVisible(halloween, {
        now: duringWindow,
        cloud: true,
        country: 'FR',
      }),
    ).toBe(false)
    expect(
      isConsoleBannerVisible(halloween, {
        now: duringWindow,
        cloud: true,
        country: 'US',
      }),
    ).toBe(true)
  })

  it('hides country-targeted banners when the visitor country is unknown', () => {
    const startPromo = getConsoleBannerById(START_PROMO_BANNER_ID)!

    expect(
      isConsoleBannerVisible(startPromo, {
        now: new Date(startPromo.startMs),
        cloud: true,
      }),
    ).toBe(false)
    expect(
      isConsoleBannerVisible(startPromo, {
        now: new Date(startPromo.startMs),
        cloud: true,
        country: 'IN',
      }),
    ).toBe(true)
  })

  it('preview ignores country targeting', () => {
    const halloween = getConsoleBannerById(HALLOWEEN_BANNER_ID)!

    expect(
      isConsoleBannerVisible(halloween, {
        now: new Date('2026-11-01T00:00:00.000Z'),
        cloud: false,
        country: 'FR',
        preview: true,
      }),
    ).toBe(true)
  })
})

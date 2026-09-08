import { describe, expect, it } from 'bun:test'
import {
  getConsoleBannerById,
  POSTGRES_PROMO_BANNER_ID,
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
})

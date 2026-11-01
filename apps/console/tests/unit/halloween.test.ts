import { describe, expect, test } from 'bun:test'
import {
  getConsoleBannerById,
  HALLOWEEN_BANNER_ID,
} from '@/lib/console-banners/catalog'
import { isConsoleBannerVisible } from '@/lib/console-banners/visibility'

const halloweenBanner = getConsoleBannerById(HALLOWEEN_BANNER_ID)!

describe('Halloween banner', () => {
  test.each([
    ['2026-10-25T00:00:00.000Z', true],
    ['2026-10-31T23:59:59.999Z', true],
    ['2026-10-24T23:59:59.999Z', false],
    ['2026-11-01T00:00:00.000Z', false],
  ])('%s → %p', (iso, expected) => {
    expect(
      isConsoleBannerVisible(halloweenBanner, {
        now: new Date(iso),
        cloud: true,
        country: 'US',
      }),
    ).toBe(expected)
  })

  test('hides on self-hosted unless preview is on', () => {
    const duringWindow = new Date('2026-10-31T12:00:00.000Z')

    expect(
      isConsoleBannerVisible(halloweenBanner, {
        now: duringWindow,
        cloud: false,
        country: 'US',
      }),
    ).toBe(false)
    expect(
      isConsoleBannerVisible(halloweenBanner, {
        now: duringWindow,
        cloud: false,
        preview: true,
      }),
    ).toBe(true)
  })

  test('preview shows the decorations after they expire', () => {
    expect(
      isConsoleBannerVisible(halloweenBanner, {
        now: new Date('2026-11-01T00:00:00.000Z'),
        cloud: true,
        preview: true,
      }),
    ).toBe(true)
  })
})

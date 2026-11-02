import { describe, expect, it } from 'bun:test'
import { existsSync } from 'node:fs'
import { join } from 'node:path'
import {
  getConsoleBannerById,
  isConsoleBannerScheduled,
  START_EXPANSION_PROMO_BANNER_ID,
  START_PROMO_BANNER_ID,
} from '@/lib/console-banners/catalog'
import { resolveStartPromoBannerContent } from '@/lib/console-banners/start-promo-content'
import { START_PLAN_ELIGIBLE_COUNTRIES } from '@/lib/pricing/start-plan'

const LAUNCH_COUNTRIES = ['IN', 'NP', 'NG', 'PK']

describe('resolveStartPromoBannerContent', () => {
  it('returns country-specific copy for each Start-eligible region', () => {
    expect(resolveStartPromoBannerContent('IN')?.headline).toBe('Namaste India')
    expect(resolveStartPromoBannerContent('NP')?.headline).toBe('Namaste Nepal')
    expect(resolveStartPromoBannerContent('NG')?.headline).toBe(
      'How far, Nigeria',
    )
    expect(resolveStartPromoBannerContent('PK')?.headline).toBe(
      'Salaam Pakistan',
    )
    expect(resolveStartPromoBannerContent('bd')?.headline).toBe(
      'Salaam Bangladesh',
    )
    expect(resolveStartPromoBannerContent('KE')?.headline).toBe('Jambo Kenya')
  })

  it('gives every eligible country its own copy and landmark', () => {
    const contents = START_PLAN_ELIGIBLE_COUNTRIES.map((country) =>
      resolveStartPromoBannerContent(country),
    )
    const headlines = new Set(contents.map((content) => content?.headline))
    const images = new Set(contents.map((content) => content?.imageSrc))

    expect(headlines.size).toBe(START_PLAN_ELIGIBLE_COUNTRIES.length)
    expect(images.size).toBe(START_PLAN_ELIGIBLE_COUNTRIES.length)
    for (const content of contents) {
      expect(existsSync(join(process.cwd(), 'public', content!.imageSrc))).toBe(
        true,
      )
    }
  })

  it('keeps launch countries on the original banner and the rest on the expansion banner', () => {
    for (const country of START_PLAN_ELIGIBLE_COUNTRIES) {
      expect(resolveStartPromoBannerContent(country)?.bannerId).toBe(
        LAUNCH_COUNTRIES.includes(country)
          ? START_PROMO_BANNER_ID
          : START_EXPANSION_PROMO_BANNER_ID,
      )
    }
  })

  it('returns null outside eligible countries', () => {
    expect(resolveStartPromoBannerContent('US')).toBeNull()
    expect(resolveStartPromoBannerContent(null)).toBeNull()
  })
})

describe('Start expansion banner schedule', () => {
  const banner = getConsoleBannerById(START_EXPANSION_PROMO_BANNER_ID)!

  it('runs for fourteen days from 31 Oct 2026 UTC', () => {
    expect(
      isConsoleBannerScheduled(banner, new Date('2026-10-30T23:59:59Z')),
    ).toBe(false)
    expect(
      isConsoleBannerScheduled(banner, new Date('2026-10-31T00:00:00Z')),
    ).toBe(true)
    expect(
      isConsoleBannerScheduled(banner, new Date('2026-11-13T23:59:59Z')),
    ).toBe(true)
    expect(
      isConsoleBannerScheduled(banner, new Date('2026-11-14T00:00:00Z')),
    ).toBe(false)
  })
})

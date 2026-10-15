import { describe, expect, it } from 'bun:test'
import { resolveStartPromoBannerContent } from '@/lib/console-banners/start-promo-content'

describe('resolveStartPromoBannerContent', () => {
  it('returns country-specific copy for each Start-eligible region', () => {
    expect(resolveStartPromoBannerContent('IN')?.headline).toBe('Namaste India')
    expect(resolveStartPromoBannerContent('NP')?.headline).toBe('Namaste Nepal')
    expect(resolveStartPromoBannerContent('NG')?.headline).toBe('Welcome Nigeria')
    expect(resolveStartPromoBannerContent('PK')?.headline).toBe('Welcome Pakistan')
  })

  it('returns null outside eligible countries', () => {
    expect(resolveStartPromoBannerContent('US')).toBeNull()
    expect(resolveStartPromoBannerContent(null)).toBeNull()
  })
})

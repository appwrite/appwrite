import { describe, expect, test } from 'bun:test'
import { matchesMarketingPagePath } from '@/lib/marketing/is-marketing-page-path'
import {
  getAllMarketingPrerenderPaths,
  isMarketingPrerenderPath,
} from '@/lib/marketing/marketing-build-paths'
import { UNLAUNCHED_MARKETING_PATHS } from '@/lib/marketing/marketing-page-paths'
import { isAlternativeReleased } from '@/lib/alternatives/availability'
import { ALTERNATIVE_IDS, ALTERNATIVE_REGISTRY } from '@/lib/alternatives/registry'
import { isProductReleased } from '@/lib/products/availability'
import { getMarketingProductMenuCategories } from '@/lib/products/registry'
import { getMarketingSitemapEntries } from '@/lib/sitemap/providers/marketing'

describe('marketing pages behind a launch flag', () => {
  test('stay marketing pages, so visitors are not sent to sign in', () => {
    for (const path of UNLAUNCHED_MARKETING_PATHS) {
      expect(matchesMarketingPagePath(path)).toBe(true)
    }
  })

  test('are never prerendered, so a build cannot bake them in', () => {
    const prerendered = new Set(getAllMarketingPrerenderPaths())
    for (const path of UNLAUNCHED_MARKETING_PATHS) {
      expect(prerendered.has(path)).toBe(false)
      expect(isMarketingPrerenderPath(path)).toBe(false)
    }
  })

  test('stay out of the sitemap', () => {
    const paths = new Set(getMarketingSitemapEntries().map((entry) => entry.path))
    for (const path of UNLAUNCHED_MARKETING_PATHS) {
      expect(paths.has(path)).toBe(false)
    }
  })
})

const ALL_OFF = { videos: false, zero: false, alerts: false, beep: false, reviews: false }

describe('Zero release gate', () => {
  test('hides Zero while the flag is off and shows it when on', () => {
    expect(isProductReleased('zero', ALL_OFF)).toBe(false)
    expect(isProductReleased('zero', { ...ALL_OFF, zero: true })).toBe(true)
  })

  test('lists Zero under Build in the Products menu', () => {
    const build = getMarketingProductMenuCategories({ agent: true }).find(
      (category) => category.id === 'build',
    )
    expect(build?.productIds).toContain('zero')
  })
})

describe('Init 5 product pages', () => {
  test('Monitors, Beep, and Reviews pages are unlaunched marketing pages', () => {
    for (const path of ['/products/monitors', '/products/beep', '/products/reviews']) {
      expect(UNLAUNCHED_MARKETING_PATHS).toContain(path)
    }
  })

  test('each follows its own launch flag', () => {
    expect(isProductReleased('monitors', ALL_OFF)).toBe(false)
    expect(isProductReleased('monitors', { ...ALL_OFF, alerts: true })).toBe(true)
    expect(isProductReleased('beep', ALL_OFF)).toBe(false)
    expect(isProductReleased('beep', { ...ALL_OFF, beep: true })).toBe(true)
    expect(isProductReleased('reviews', ALL_OFF)).toBe(false)
    expect(isProductReleased('reviews', { ...ALL_OFF, reviews: true })).toBe(true)
  })

  test('list under Observe & protect in the Products menu', () => {
    const protect = getMarketingProductMenuCategories({ agent: true, analytics: true }).find(
      (category) => category.id === 'protect',
    )
    expect(protect?.productIds).toEqual(
      expect.arrayContaining(['analytics', 'monitors', 'beep', 'firewall', 'reviews']),
    )
  })
})

describe('Appwrite Reviews comparisons', () => {
  const reviewsComparisons = ALTERNATIVE_IDS.filter(
    (id) => ALTERNATIVE_REGISTRY[id].launchesWith === 'reviews',
  )

  test('cover CodeRabbit, GitHub Copilot, Cursor Bugbot, and Greptile', () => {
    expect(reviewsComparisons).toEqual(['coderabbit', 'copilot', 'bugbot', 'greptile'])
  })

  test('stay unlaunched until Reviews ships', () => {
    for (const id of reviewsComparisons) {
      expect(UNLAUNCHED_MARKETING_PATHS).toContain(`/alternative-to/${id}`)
      expect(isAlternativeReleased(id, ALL_OFF)).toBe(false)
      expect(isAlternativeReleased(id, { ...ALL_OFF, reviews: true })).toBe(true)
    }
  })

  test('leave comparisons without a launch product alone', () => {
    expect(isAlternativeReleased('supabase', ALL_OFF)).toBe(true)
    expect(isAlternativeReleased('mux', ALL_OFF)).toBe(true)
  })
})

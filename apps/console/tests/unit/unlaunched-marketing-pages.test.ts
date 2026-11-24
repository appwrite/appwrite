import { describe, expect, test } from 'bun:test'
import { matchesMarketingPagePath } from '@/lib/marketing/is-marketing-page-path'
import {
  getAllMarketingPrerenderPaths,
  isMarketingPrerenderPath,
} from '@/lib/marketing/marketing-build-paths'
import { UNLAUNCHED_MARKETING_PATHS } from '@/lib/marketing/marketing-page-paths'
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

describe('Zero in the Products menu', () => {
  const productIds = (zero: boolean) =>
    getMarketingProductMenuCategories({ agent: true, zero }).flatMap(
      (category) => category.productIds,
    )

  test('is hidden while the flag is off', () => {
    expect(productIds(false)).not.toContain('zero')
  })

  test('shows under Build when the flag is on', () => {
    const build = getMarketingProductMenuCategories({ agent: true, zero: true }).find(
      (category) => category.id === 'build',
    )
    expect(build?.productIds).toContain('zero')
  })
})

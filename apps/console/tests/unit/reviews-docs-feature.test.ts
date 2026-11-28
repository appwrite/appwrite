import { afterEach, beforeEach, describe, expect, test } from 'bun:test'
import { setExperimentEnabled } from '@/lib/experiments'
import { isFeatureGatedDocsSlugHidden } from '@/lib/docs/feature-gated-docs'
import { getDocsGlobalNav } from '@/lib/docs/navigation'
import { getAllDocsSectionNavs } from '@/lib/docs/navigation/section-navs'
import {
  isReviewsDocsHref,
  isReviewsDocsPathname,
} from '@/lib/docs/reviews-docs-feature'
import { getDocsSitemapEntries } from '@/lib/sitemap/providers/docs'
import type { DocsNavTree } from '@/lib/docs/types'

const globals = globalThis as { window?: unknown; localStorage?: unknown }
const originalWindow = globals.window
const originalLocalStorage = globals.localStorage

const hasReviewsSectionNav = () =>
  getAllDocsSectionNavs().some((config) => config.prefix === 'products/reviews')

function hrefs(navigation: DocsNavTree): string[] {
  return navigation.flatMap((entry) =>
    'items' in entry ? entry.items.map((item) => item.href) : [entry.href],
  )
}

describe('reviews docs paths', () => {
  test('match the Reviews page and its anchors only', () => {
    expect(isReviewsDocsPathname('/docs/products/reviews')).toBe(true)
    expect(isReviewsDocsPathname('/docs/products/reviews/')).toBe(true)
    expect(isReviewsDocsPathname('/docs/products/reviews/quick-start')).toBe(
      true,
    )
    expect(isReviewsDocsPathname('/docs/products/reviews-guide')).toBe(false)
    expect(isReviewsDocsHref('/docs/products/reviews#billing')).toBe(true)
    expect(isReviewsDocsHref('/docs/products/reviews?view=menu')).toBe(true)
    expect(isReviewsDocsHref('/docs/products/functions')).toBe(false)
  })
})

describe('reviews docs', () => {
  beforeEach(() => {
    const storage = new Map<string, string>()
    const localStorage = {
      getItem: (key: string) => storage.get(key) ?? null,
      setItem: (key: string, value: string) => void storage.set(key, value),
      removeItem: (key: string) => void storage.delete(key),
    }
    globals.localStorage = localStorage
    globals.window = {
      localStorage,
      location: new URL('https://cloud.appwrite.io/docs'),
      dispatchEvent: () => true,
    }
  })

  afterEach(() => {
    globals.window = originalWindow
    globals.localStorage = originalLocalStorage
  })

  test('stay hidden while the reviews flag is off', () => {
    expect(isFeatureGatedDocsSlugHidden('products/reviews')).toBe(true)
    expect(isFeatureGatedDocsSlugHidden('products/reviews/billing')).toBe(true)
    expect(
      isFeatureGatedDocsSlugHidden('products/reviews', {
        deferReviewsOnServer: true,
      }),
    ).toBe(true)
    expect(isFeatureGatedDocsSlugHidden('products/functions')).toBe(false)
    expect(isFeatureGatedDocsSlugHidden('products/reviews-guide')).toBe(false)

    expect(hrefs(getDocsGlobalNav('developers')).some(isReviewsDocsHref)).toBe(
      false,
    )
    expect(hasReviewsSectionNav()).toBe(false)
    expect(
      getDocsSitemapEntries().some((entry) =>
        isReviewsDocsPathname(entry.path),
      ),
    ).toBe(false)
  })

  test('open once the Reviews experiment turns the flag on', () => {
    setExperimentEnabled('reviews', true)

    expect(isFeatureGatedDocsSlugHidden('products/reviews')).toBe(false)
    expect(hrefs(getDocsGlobalNav('developers'))).toContain(
      '/docs/products/reviews',
    )
    expect(hasReviewsSectionNav()).toBe(true)

    setExperimentEnabled('reviews', false)
    expect(isFeatureGatedDocsSlugHidden('products/reviews')).toBe(true)
  })

  test('the server does not block a reload before the browser applies the flag', () => {
    globals.window = undefined
    expect(
      isFeatureGatedDocsSlugHidden('products/reviews', {
        deferReviewsOnServer: true,
      }),
    ).toBe(false)
    expect(isFeatureGatedDocsSlugHidden('products/reviews')).toBe(true)
  })
})
